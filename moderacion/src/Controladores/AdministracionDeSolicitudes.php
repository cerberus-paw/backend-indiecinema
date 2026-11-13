<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Controladores;

use IndieCinema\Moderacion\Modelo\SolicitudDeSala;
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
 * obligatorio. «Ver historial de decisiones» (?ver=historial) lista las resueltas; desde una
 * aprobada la sala se retira del sitio o se vuelve a publicar, con un motivo, por la API interna de
 * programación.
 *
 * La solicitud trae la sala que propone; la sala recién existe en programación cuando se aprueba.
 * Las rutas piden el rol de administrador (el de moderador llega con EF-07.3) y el núcleo ya
 * controló el token CSRF de los POST.
 */
final class AdministracionDeSolicitudes extends Controlador
{
    /** Como la columna solicitud_sala.motivo. */
    private const MAXIMO_MOTIVO = 1000;
    private const RESOLUCIONES = ['aprobar', 'rechazar'];

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
     * @throws ExcepcionHttp 404 si la solicitud no existe; 503 si cuentas o programación no
     *                       responden al aprobar
     */
    public function resolver(Peticion $peticion): Respuesta
    {
        $solicitud = $this->servicio->buscar((string) $peticion->parametro('id'));
        $resolucion = $peticion->campo('resolucion');
        $motivo = self::motivo($peticion->campo('motivo'));
        $errores = [];
        if (!in_array($resolucion, self::RESOLUCIONES, true)) {
            $errores['resolucion'] = 'Elegí si la aprobás o la rechazás.';
        }
        if ($motivo === '') {
            $errores['motivo'] = 'Escribí el motivo: se le muestra a quien la mandó.';
        } elseif (mb_strlen($motivo) > self::MAXIMO_MOTIVO) {
            $errores['motivo'] = 'El motivo puede tener hasta ' . self::MAXIMO_MOTIVO . ' caracteres.';
        }
        if ($errores !== []) {
            return $this->bandeja($peticion, $solicitud, ['resolucion' => is_string($resolucion) ? $resolucion : '', 'motivo' => $motivo], $errores, estado: 422);
        }

        $administrador = self::administrador($peticion);
        $resuelta = $resolucion === 'aprobar'
            ? $this->servicio->aprobar($solicitud->id, $administrador, $motivo)
            : $this->servicio->rechazar($solicitud->id, $administrador, $motivo);
        $aviso = match (true) {
            !$resuelta => 'ya_resuelta',
            $resolucion === 'aprobar' => 'aprobada',
            default => 'rechazada',
        };

        // 303 a las pendientes con el aviso: recargar no vuelve a mandar la resolución, y sigue la
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
        $motivo = self::motivo($peticion->campo('motivo_sala'));
        $error = match (true) {
            $motivo === '' => 'Escribí por qué: queda en el registro.',
            mb_strlen($motivo) > self::MAXIMO_MOTIVO => 'El motivo puede tener hasta ' . self::MAXIMO_MOTIVO . ' caracteres.',
            default => null,
        };
        if ($error !== null && $solicitud->estado->value === 'aprobada') {
            return $this->bandeja($peticion, $solicitud, ['motivo_sala' => $motivo], ['motivo_sala' => $error], estado: 422);
        }
        $this->servicio->cambiarLaSala($solicitud->id, $habilitada, self::administrador($peticion), $motivo);

        return $this->redirigir('/solicitudes?' . http_build_query([
            'id' => $solicitud->id,
            $habilitada ? 'habilitada' : 'deshabilitada' => $solicitud->id,
        ]));
    }

    /**
     * @param array{resolucion?: string, motivo?: string, motivo_sala?: string} $valores lo que mandó, si hubo errores
     * @param array<string, string>                       $errores campo => qué está mal
     */
    private function bandeja(Peticion $peticion, ?SolicitudDeSala $elegida, array $valores = [], array $errores = [], ?string $mensaje = null, int $estado = 200): Respuesta
    {
        // La vista de la elegida; sin elegida, la que pide la URL.
        $historial = $elegida !== null ? !$elegida->estaPendiente() : $peticion->consulta('ver') === 'historial';
        $q = $peticion->consulta('q');
        $texto = is_string($q) ? trim($q) : '';
        $lista = $this->servicio->lista(!$historial, $texto);
        $elegida ??= $lista[0] ?? null;

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
                'elegida' => $solicitud->id === $elegida?->id,
            ], $lista),
            'elegida' => $elegida === null ? null : $this->detalle($elegida, $this->enElSitio($elegida)),
            'valores' => $valores,
            'errores' => $errores,
            'maximo_motivo' => self::MAXIMO_MOTIVO,
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
            'solicitudes_previas' => $this->servicio->cuantasDe($solicitud->usuarioId) - 1,
        ];
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
     * El aviso de lo que se acaba de hacer, con el nombre de la sala, o null.
     */
    private function mensaje(Peticion $peticion): ?string
    {
        $avisos = [
            'aprobada' => 'Aprobaste la solicitud de «%s»: la sala ya se ve en el sitio y quien la mandó es organizador.',
            'rechazada' => 'Rechazaste la solicitud de «%s». Quien la mandó ve el motivo y puede corregirla.',
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
     * de su sala allá, que el administrador ve aunque no esté publicada.
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

    private static function administrador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide el rol de administrador: sin sesión no llega acá.');
    }
}
