<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Moderacion\Modelo\Documento;
use IndieCinema\Moderacion\Modelo\EstadoSolicitud;
use IndieCinema\Moderacion\Modelo\RequisitoDeSala;
use IndieCinema\Moderacion\Modelo\SolicitudDeSala;
use IndieCinema\Moderacion\Modelo\TipoDocumento;
use IndieCinema\Moderacion\Servicios\ServicioDeSolicitudes;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * La bandeja de solicitudes de sala, en /moderacion/solicitudes desde EF-07.2, como el
 * wireframe de la E2 (moderacion-solicitudes): el buscador y la lista a la izquierda y la elegida
 * (?id=) a la derecha, con la sala, quién la mandó y la resolución, que lleva un motivo
 * obligatorio. Una pendiente también pasa a revisión manual (EF-07.6), con su motivo, y sigue en
 * la lista hasta que se aprueba o se rechaza. Cada resolución lleva el checklist de requisitos
 * (EF-07.9): aprobar exige los cuatro puntos, y queda registrado qué tildó cada moderador. Si un
 * documento no sirve, la solicitud se devuelve (EF-22.10) marcando cuáles faltan: espera al
 * solicitante en «Esperando al solicitante», sin acciones. «Ver historial de decisiones» (?ver=historial) lista
 * las resueltas; desde una
 * aprobada la sala se retira del sitio o se vuelve a publicar, con un motivo, por la API interna de
 * programación.
 *
 * La solicitud trae la sala que propone; la sala recién existe en programación cuando se aprueba.
 * Las rutas piden el rol de moderador (EF-07.3), que el administrador alcanza, y el núcleo ya
 * controló el token CSRF de los POST.
 */
final class AdministracionDeSolicitudes extends Controlador
{
    /** Como la columna solicitud_sala.motivo. */
    private const MAXIMO_MOTIVO = 1000;
    private const RESOLUCIONES = ['aprobar', 'rechazar', 'revision', 'devolver'];

    public function __construct(private readonly ServicioDeSolicitudes $servicio)
    {
    }

    /**
     * @throws ExcepcionHttp 404 si la solicitud de ?id= no existe
     */
    public function listar(Peticion $peticion): Respuesta
    {
        $id = $peticion->consulta('id');
        $elegida = $id === null ? null : $this->servicio->buscar(is_string($id) ? $id : '');

        return $this->bandeja($peticion, $elegida, mensaje: $this->mensaje($peticion));
    }

    /**
     * @throws ExcepcionHttp 404 si la solicitud no existe; 403 si es del moderador; 409 si está
     *                       devuelta, o si se devuelve una que no está por resolver; 503 si
     *                       cuentas o programación no responden al aprobar
     */
    public function resolver(Peticion $peticion): Respuesta
    {
        $solicitud = $this->servicio->buscar((string) $peticion->parametro('id'));
        // Antes que los errores del formulario: la suya no la resuelve, ni con el motivo mal.
        $this->servicio->controlarQueLaPuedeResolver($solicitud, self::moderador($peticion));
        $resolucion = $peticion->campo('resolucion');
        // EF-22.10: la devuelta espera al solicitante, y sólo se devuelve una por resolver.
        if ($solicitud->estado === EstadoSolicitud::Devuelta) {
            throw new ExcepcionHttp(409, 'La solicitud está devuelta: espera que quien la mandó complete la documentación.');
        }
        if ($resolucion === 'devolver' && !$solicitud->estaPorResolver()) {
            throw new ExcepcionHttp(409, 'La solicitud ya está resuelta: no se puede devolver.');
        }
        $motivo = self::motivo($peticion->campo('motivo'));
        $tildados = RequisitoDeSala::tildados($peticion->campo('requisitos'));
        $faltantes = TipoDocumento::marcados($peticion->campo('faltantes'));
        $errores = [];
        if (!in_array($resolucion, self::RESOLUCIONES, true)) {
            $errores['resolucion'] = 'Elegí si la aprobás, la rechazás, la devolvés o la pasás a revisión manual.';
        } elseif ($resolucion === 'revision' && $solicitud->estado === EstadoSolicitud::EnRevisionManual) {
            $errores['resolucion'] = 'Ya está en revisión manual: aprobala o rechazala.';
        }
        if ($motivo === '') {
            $errores['motivo'] = 'Escribí el motivo: se le muestra a quien la mandó.';
        } elseif (mb_strlen($motivo) > self::MAXIMO_MOTIVO) {
            $errores['motivo'] = 'El motivo puede tener hasta ' . self::MAXIMO_MOTIVO . ' caracteres.';
        }
        if ($resolucion === 'devolver' && $faltantes === []) {
            $errores['faltantes'] = 'Marcá qué documento falta.';
        }
        if ($resolucion === 'aprobar') {
            // Un papel vencido primero: su punto está deshabilitado, así que también falta.
            $vencido = $this->servicio->requisitosVencidos($solicitud)[0] ?? null;
            $errores += match (true) {
                $vencido !== null => ['requisitos' => $vencido->documento()?->estaVencido() . ': no se puede aprobar.'],
                !RequisitoDeSala::completo($tildados) => ['requisitos' => 'Para aprobarla, tildá los cuatro puntos.'],
                default => [],
            };
        }
        if ($errores !== []) {
            return $this->bandeja($peticion, $solicitud, [
                'resolucion' => is_string($resolucion) ? $resolucion : '',
                'motivo' => $motivo,
                'requisitos' => array_map(static fn (RequisitoDeSala $requisito): string => $requisito->value, $tildados),
                'faltantes' => array_map(static fn (TipoDocumento $tipo): string => $tipo->value, $faltantes),
            ], $errores, estado: 422);
        }

        $moderador = self::moderador($peticion);
        $resuelta = match ($resolucion) {
            'aprobar' => $this->servicio->aprobar($solicitud->id, $moderador, $motivo, $tildados),
            'revision' => $this->servicio->pasarARevision($solicitud->id, $moderador, $motivo, $tildados),
            'devolver' => $this->servicio->devolver($solicitud->id, $moderador, $motivo, $faltantes, $tildados),
            default => $this->servicio->rechazar($solicitud->id, $moderador, $motivo, $tildados),
        };
        $aviso = match (true) {
            !$resuelta => 'ya_resuelta',
            $resolucion === 'aprobar' => 'aprobada',
            $resolucion === 'revision' => 'en_revision',
            $resolucion === 'devolver' => 'devuelta',
            default => 'rechazada',
        };

        // 303 a las abiertas con el aviso: recargar no vuelve a mandar la resolución, y sigue la
        // próxima.
        return $this->redirigir('/solicitudes?' . http_build_query([$aviso => $solicitud->id]));
    }

    /**
     * @throws ExcepcionHttp 404 si la solicitud no existe o no está aprobada
     */
    public function deshabilitarSala(Peticion $peticion): Respuesta
    {
        return $this->cambiarLaSala($peticion, false);
    }

    /**
     * @throws ExcepcionHttp 404 si la solicitud no existe o no está aprobada
     */
    public function habilitarSala(Peticion $peticion): Respuesta
    {
        return $this->cambiarLaSala($peticion, true);
    }

    /**
     * Retira del sitio, o vuelve a publicar, la sala de una solicitud aprobada, con un motivo que
     * queda en el registro de programación junto con quién lo hizo.
     */
    private function cambiarLaSala(Peticion $peticion, bool $habilitada): Respuesta
    {
        $solicitud = $this->servicio->buscar((string) $peticion->parametro('id'));
        $this->servicio->controlarQueLaPuedeResolver($solicitud, self::moderador($peticion));
        $motivo = self::motivo($peticion->campo('motivo_sala'));
        $error = match (true) {
            $motivo === '' => 'Escribí por qué: queda en el registro.',
            mb_strlen($motivo) > self::MAXIMO_MOTIVO => 'El motivo puede tener hasta ' . self::MAXIMO_MOTIVO . ' caracteres.',
            default => null,
        };
        if ($error !== null && $solicitud->estado->value === 'aprobada') {
            return $this->bandeja($peticion, $solicitud, ['motivo_sala' => $motivo], ['motivo_sala' => $error], estado: 422);
        }
        $this->servicio->cambiarLaSala($solicitud->id, $habilitada, self::moderador($peticion), $motivo);

        return $this->redirigir('/solicitudes?' . http_build_query([
            'id' => $solicitud->id,
            $habilitada ? 'habilitada' : 'deshabilitada' => $solicitud->id,
        ]));
    }

    /**
     * @param array{resolucion?: string, motivo?: string, requisitos?: list<string>, faltantes?: list<string>, motivo_sala?: string} $valores lo que mandó, si hubo errores
     * @param array<string, string>                       $errores campo => qué está mal
     */
    private function bandeja(Peticion $peticion, ?SolicitudDeSala $elegida, array $valores = [], array $errores = [], ?string $mensaje = null, int $estado = 200): Respuesta
    {
        // La vista de la elegida; sin elegida, la que pide la URL.
        $historial = $elegida !== null ? !$elegida->estaAbierta() : $peticion->consulta('ver') === 'historial';
        $q = $peticion->consulta('q');
        $texto = is_string($q) ? trim($q) : '';
        $lista = $this->servicio->lista(!$historial, $texto);
        $elegida ??= $lista[0] ?? null;
        $vencidos = $elegida?->estaPorResolver() ? $this->servicio->requisitosVencidos($elegida) : [];

        $datos = [
            'historial' => $historial,
            'q' => $texto,
            'total_pendientes' => $this->servicio->cuantas(true),
            'total_en_la_lista' => $this->servicio->cuantas(!$historial, $texto),
            'solicitudes' => array_map(static fn (SolicitudDeSala $solicitud): array => [
                'id' => $solicitud->id,
                'estado' => $solicitud->estado->value,
                'sala' => $solicitud->datos->nombre,
                'localidad' => $solicitud->datos->localidad,
                'solicitante' => $solicitud->solicitanteNombre,
                'enviada' => FechaParaMostrar::diaCorto($solicitud->enviadaEn),
                'cambio' => $solicitud->cambio,
                'elegida' => $solicitud->id === $elegida?->id,
            ], $lista),
            // EF-22.10: las devueltas, aparte: esperan al solicitante.
            'devueltas' => $historial ? [] : array_map(static fn (SolicitudDeSala $solicitud): array => [
                'id' => $solicitud->id,
                'sala' => $solicitud->datos->nombre,
                'solicitante' => $solicitud->solicitanteNombre,
                'falta' => $solicitud->devolucion?->queFalta(),
                'vence' => $solicitud->devolucion?->vence->format('d/m/Y'),
                'cambio' => $solicitud->cambio,
                'elegida' => $solicitud->id === $elegida?->id,
            ], $this->servicio->devueltas()),
            'elegida' => $elegida === null ? null : $this->detalle($elegida, $this->enElSitio($elegida)) + [
                // La del moderador se muestra sin los formularios: la resuelve otro.
                'propia' => !$this->servicio->laPuedeResolver($elegida, self::moderador($peticion)),
            ],
            'valores' => $valores,
            'errores' => $errores,
            'maximo_motivo' => self::MAXIMO_MOTIVO,
            // EF-22.10: los documentos que se pueden marcar al devolverla.
            'tipos_de_documento' => array_map(static fn (TipoDocumento $tipo): array => [
                'clave' => $tipo->value,
                'nombre' => ucfirst($tipo->conArticulo()),
            ], TipoDocumento::cases()),
            // EF-07.9: los puntos del checklist, para resolver una abierta. El de un papel vencido
            // no se puede tildar.
            'requisitos' => array_map(static fn (RequisitoDeSala $requisito): array => [
                'clave' => $requisito->value,
                'nombre' => $requisito->nombre(),
                'aclaracion' => $requisito->aclaracion(),
                'vencido' => in_array($requisito, $vencidos, true),
            ], RequisitoDeSala::cases()),
        ];
        // La plantilla muestra el mensaje si la variable existe: sólo se pasa cuando hay algo que
        // decir.
        if ($mensaje !== null) {
            $datos['mensaje_exito'] = $mensaje;
        }

        return $this->vista('administracion_solicitudes.html.twig', $datos, $estado);
    }

    /**
     * Lo que muestra la columna de la derecha, ya escrito como se lee. Quién la mandó lo cuenta
     * cuentas: si no responde, la bandeja se ve igual, sin esos datos.
     *
     * @return array<string, mixed>
     */
    private function detalle(SolicitudDeSala $solicitud, ?string $enElSitio): array
    {
        try {
            $usuario = $this->servicio->solicitante($solicitud);
        } catch (ExcepcionHttp) {
            $usuario = false;
        }
        $datos = $solicitud->datos;

        return [
            'id' => $solicitud->id,
            'estado' => $solicitud->estado->value,
            'enviada' => FechaParaMostrar::diaYHora($solicitud->enviadaEn),
            'resuelta' => $solicitud->resueltaEn === null ? null : FechaParaMostrar::diaYHora($solicitud->resueltaEn),
            'motivo' => $solicitud->motivo,
            // EF-07.6: si pasó por la revisión manual, su motivo, aparte del de la resolución.
            'revision' => $solicitud->revisionEn === null ? null : [
                'motivo' => $solicitud->revisionMotivo,
                'fecha' => FechaParaMostrar::diaYHora($solicitud->revisionEn),
            ],
            // EF-22.10: la última devolución, si se la devolvieron alguna vez.
            'devolucion' => $solicitud->devolucion === null ? null : [
                'motivo' => $solicitud->devolucion->motivo,
                'fecha' => FechaParaMostrar::diaYHora($solicitud->devolucion->devueltaEn),
                'vence' => $solicitud->devolucion->vence->format('d/m/Y'),
                'falta' => $solicitud->devolucion->queFalta(),
                'moderador' => $this->nombreDe($solicitud->devolucion->devueltaPor),
            ],
            'sala' => [
                'id' => $solicitud->salaId,
                'nombre' => $datos->nombre,
                'localidad' => $datos->localidad,
                'capacidad' => $datos->capacidad,
                'imagen' => $this->foto($solicitud),
                'descripcion' => $datos->descripcion,
                'direccion' => $datos->direccion,
                'peliculas_por_funcion' => $datos->peliculasPorFuncion,
                'duracion_funcion' => $datos->duracionFuncion,
                'tiempo_entre_funciones' => $datos->tiempoEntreFunciones,
            ],
            'en_el_sitio' => $enElSitio,
            // EF-07.6: un pedido de cambio de dirección de una sala publicada.
            'cambio' => $solicitud->cambio,
            'direccion_actual' => $this->direccionActual($solicitud),
            // false: cuentas no respondió; null: el usuario ya no existe.
            'solicitante' => match (true) {
                $usuario === false => false,
                $usuario === null => null,
                default => [
                    'nombre' => $usuario->nombre,
                    'correo' => $usuario->correo,
                    'cuenta_creada' => FechaParaMostrar::dia($usuario->creadoEn),
                    'es_organizador' => array_intersect(['organizador', 'moderador', 'administrador'], $usuario->roles) !== [],
                ],
            },
            'solicitante_nombre' => $solicitud->solicitanteNombre,
            // EF-07.4: vacía en las que se mandaron antes de que se pidiera.
            'documentos' => array_map(fn (Documento $documento): array => [
                'nombre' => $documento->tipo->nombre(),
                'vence' => $documento->venceEn->format('d/m/Y'),
                'vencido' => $documento->vencidoAl(new DateTimeImmutable('today')),
                'estado' => $documento->estado->value,
                'url' => $this->ruta("/documentos/{$documento->id}"),
            ], $this->servicio->documentosDe($solicitud)),
            'solicitudes_previas' => $this->servicio->cuantasDe($solicitud->usuarioId) - 1,
            'revisiones' => $this->revisiones($solicitud),
        ];
    }

    /**
     * Qué tildó cada moderador (EF-07.9), de solo lectura. Quién lo firmó lo cuenta cuentas: si no
     * responde, el registro se ve igual, sin el nombre, y no se le vuelve a preguntar por cada uno.
     *
     * @return list<array{resolucion: string, fecha: string, moderador: ?string, puntos: list<array{nombre: string, cumple: bool}>}>
     */
    private function revisiones(SolicitudDeSala $solicitud): array
    {
        $nombres = [];
        $cuentasResponde = true;
        $revisiones = [];
        foreach ($this->servicio->revisionesDe($solicitud) as $revision) {
            $id = $revision['moderador_id'];
            if ($cuentasResponde && !array_key_exists($id, $nombres)) {
                try {
                    $nombres[$id] = $this->servicio->nombreDe($id);
                } catch (ExcepcionHttp) {
                    $cuentasResponde = false;
                }
            }
            $revisiones[] = [
                'resolucion' => $revision['resolucion']->value,
                'fecha' => FechaParaMostrar::diaYHora($revision['revisado_en']),
                'moderador' => $nombres[$id] ?? null,
                'puntos' => array_map(static fn (RequisitoDeSala $requisito): array => [
                    'nombre' => $requisito->nombre(),
                    'cumple' => in_array($requisito, $revision['tildados'], true),
                ], RequisitoDeSala::cases()),
            ];
        }

        return $revisiones;
    }

    /**
     * El nombre de un moderador, o null si ya no existe o cuentas no responde.
     */
    private function nombreDe(string $id): ?string
    {
        try {
            return $this->servicio->nombreDe($id);
        } catch (ExcepcionHttp) {
            return null;
        }
    }

    /**
     * Cómo está su sala en el sitio, si está aprobada. Si programación no responde, la bandeja se ve
     * igual: 'desconocido', y sin ofrecer publicarla ni retirarla.
     */
    private function enElSitio(SolicitudDeSala $solicitud): ?string
    {
        try {
            return $this->servicio->salaEnElSitio($solicitud);
        } catch (ExcepcionHttp) {
            return 'desconocido';
        }
    }

    /**
     * La dirección que tiene ahora la sala de un pedido de cambio abierto, «Av. San Martín 450,
     * Luján»; null si no es un cambio abierto. Si programación no responde, false: la bandeja se ve
     * igual, sin ese dato.
     */
    private function direccionActual(SolicitudDeSala $solicitud): string|false|null
    {
        try {
            $actual = $this->servicio->direccionActual($solicitud);
        } catch (ExcepcionHttp) {
            return false;
        }

        return $actual === null ? null : "{$actual['direccion']}, {$actual['localidad']}";
    }

    /**
     * El aviso de lo que se acaba de hacer, con el nombre de la sala, o null.
     */
    private function mensaje(Peticion $peticion): ?string
    {
        $avisos = [
            'aprobada' => 'Aprobaste la solicitud de «%s»: la sala ya se ve en el sitio y quien la mandó es organizador.',
            'rechazada' => 'Rechazaste la solicitud de «%s». Quien la mandó ve el motivo y puede corregirla.',
            'en_revision' => 'Pasaste «%s» a revisión manual. Quien la mandó ve el motivo; cuando termine la revisión, aprobala o rechazala.',
            'devuelta' => 'Devolviste la solicitud de «%s»: quien la mandó ve qué falta y, cuando la complete, vuelve a las pendientes.',
            'ya_resuelta' => 'La solicitud de «%s» ya estaba resuelta: no se cambió nada.',
            'deshabilitada' => 'Retiraste «%s» del sitio: ya no se ve, ni sus funciones en la cartelera.',
            'habilitada' => 'Volviste a publicar «%s» en el sitio.',
        ];
        foreach ($avisos as $clave => $texto) {
            $id = $peticion->consulta($clave);
            if (!is_string($id)) {
                continue;
            }
            try {
                return sprintf($texto, $this->servicio->buscar($id)->datos->nombre);
            } catch (ExcepcionHttp) {
                return null;
            }
        }

        return null;
    }

    /**
     * La foto que mandó la solicitud; la de una que se mudó de programación sin foto propia es la
     * de su sala allá, que el moderador ve aunque no esté publicada.
     */
    private function foto(SolicitudDeSala $solicitud): string
    {
        return $solicitud->imagen === null
            ? "/salas/{$solicitud->salaId}/imagen"
            : $this->ruta("/solicitudes/{$solicitud->id}/foto");
    }

    /**
     * Conserva los renglones y saca los demás caracteres de control. Un campo que llega como
     * arreglo cuenta como vacío.
     */
    private static function motivo(mixed $valor): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }

        return trim((string) preg_replace('/(?!\n)\p{Cc}/u', '', str_replace(["\r\n", "\r"], "\n", $valor)));
    }

    private static function moderador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide el rol de moderador: sin sesión no llega acá.');
    }
}
