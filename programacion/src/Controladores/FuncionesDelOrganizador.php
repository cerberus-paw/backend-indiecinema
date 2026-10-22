<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\FuncionDelOrganizador;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Servicios\ServicioDeFuncionesDelOrganizador;
use LogicException;

/**
 * «Mis funciones», alta, edición y cancelación de funciones, en /organizador/funciones/….
 * Las rutas piden el rol de organizador y el núcleo controla el token CSRF en los POST.
 */
final class FuncionesDelOrganizador extends Controlador
{
    private const PLANTILLA_ALTA = 'alta_funcion.html.twig';

    public function __construct(private readonly ServicioDeFuncionesDelOrganizador $servicio)
    {
    }

    public function listar(Peticion $peticion): Respuesta
    {
        $funciones = $this->servicio->listar(self::organizador($peticion));

        return $this->vista('mis_funciones.html.twig', [
            'funciones' => array_map(self::datos(...), $funciones),
            // El aviso de que se acaba de programar una: sólo si lo que llega tiene forma de id.
            'programada' => is_string($programada = $peticion->consulta('programada')) && Uuid::esValido($programada),
            'cancelada' => $peticion->consulta('cancelada') === '1',
            'votacion_cerrada' => $peticion->consulta('votacion_cerrada') === '1',
        ]);
    }

    public function nueva(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $salas = $this->servicio->salasHabilitadas($organizador);
        $obras = $this->servicio->catalogoDeObras();

        return $this->formularioAlta($salas, $obras, []);
    }

    public function crear(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $salas = $this->servicio->salasHabilitadas($organizador);
        $obras = $this->servicio->catalogoDeObras();

        $formulario = FormularioDeFuncion::desdeCampos($peticion->cuerpo(), $salas, $obras);
        if (
            !$formulario->esValido()
            || $formulario->salaId === null
            || $formulario->cupo === null
            || $formulario->precio === null
            || ($formulario->esProgramada() && $formulario->fechaHora === null)
        ) {
            return $this->formularioAlta($salas, $obras, [
                'formulario' => $formulario,
                'error_general' => 'Revisá los campos marcados.',
            ], 422);
        }

        // Lo que el formulario no puede saber solo, como que se pisa con otra función de la sala,
        // lo dice el servicio: vuelve al formulario con el motivo en vez de un error del servidor.
        try {
            if ($formulario->esProgramada()) {
                $funcion = $this->servicio->programar(
                    $organizador,
                    $formulario->salaId,
                    $formulario->obrasIds,
                    $formulario->cupo,
                    $formulario->precio,
                    $formulario->fechaHora ?? throw new LogicException('Fecha faltante en alta programada.'),
                );
            } else {
                $funcion = $this->servicio->crearEnVotacionDeTitulos(
                    $organizador,
                    $formulario->salaId,
                    $formulario->obrasIds,
                    $formulario->cupo,
                    $formulario->precio,
                );
            }
        } catch (ReglaIncumplida $regla) {
            return $this->formularioAlta($salas, $obras, ['formulario' => $formulario, 'error_general' => $regla->getMessage()], 422);
        }

        // 303 al listado: al recargar no vuelve a crear la función.
        return $this->redirigir(
            $formulario->esProgramada()
                ? "/organizador/funciones?programada={$funcion->id}"
                : '/organizador/funciones',
        );
    }

    public function editar(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        $funcion = $this->servicio->paraEditar($organizador, $id);
        $salas = $this->servicio->salasHabilitadas($organizador);
        $obras = $this->servicio->catalogoDeObras();

        $valores = [
            'sala_id' => $funcion->salaId,
            'obras' => $funcion->obrasIds,
            'fecha' => $funcion->fechaHora?->format('Y-m-d') ?? '',
            'hora' => $funcion->fechaHora?->format('H:i') ?? '',
            'precio' => (string) $funcion->precioBase,
            'cupo' => (string) $funcion->cupo,
        ];

        return $this->formularioAlta(
            salas: $salas,
            obras: $obras,
            extra: ['valores' => $valores],
            estado: 200,
            esEdicion: true,
            funcionId: $funcion->id,
        );
    }

    public function actualizar(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        // Si la función no existe o no es del organizador, corta con 404
        $this->servicio->paraEditar($organizador, $id);

        $salas = $this->servicio->salasHabilitadas($organizador);
        $obras = $this->servicio->catalogoDeObras();

        $formulario = FormularioDeFuncion::desdeCampos($peticion->cuerpo(), $salas, $obras);
        if (!$formulario->esValido() || $formulario->salaId === null || $formulario->fechaHora === null || $formulario->cupo === null || $formulario->precio === null) {
            return $this->formularioAlta(
                salas: $salas,
                obras: $obras,
                extra: [
                    'formulario' => $formulario,
                    'error_general' => 'Revisá los campos marcados.',
                ],
                estado: 422,
                esEdicion: true,
                funcionId: $id,
            );
        }

        try {
            $funcion = $this->servicio->actualizar(
                $organizador,
                $id,
                $formulario->salaId,
                $formulario->obrasIds,
                $formulario->cupo,
                $formulario->precio,
                $formulario->fechaHora,
            );
        } catch (ReglaIncumplida $regla) {
            return $this->formularioAlta(
                salas: $salas,
                obras: $obras,
                extra: [
                    'formulario' => $formulario,
                    'error_general' => $regla->getMessage(),
                ],
                estado: 422,
                esEdicion: true,
                funcionId: $id,
            );
        }

        return $this->redirigir("/cartelera/{$funcion->id}?guardada=1");
    }

    public function confirmarCancelacion(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $this->vista('cancelar_funcion.html.twig', [
            'funcion' => self::datos($this->servicio->paraCancelar($organizador, $id)),
            'accion' => "/organizador/funciones/{$id}/cancelar",
        ]);
    }

    public function cancelar(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        $this->servicio->cancelar($organizador, $id);

        return $this->redirigir('/organizador/funciones?cancelada=1');
    }

    public function cerrarVotacion(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        $this->servicio->cerrarVotacion($organizador, $id);

        if (($peticion->cuerpo()['volver'] ?? null) === 'ficha') {
            return $this->redirigir("/cartelera/{$id}?votacion_cerrada=1");
        }

        return $this->redirigir('/organizador/funciones?votacion_cerrada=1');
    }

    /**
     * El organizador propone las franjas y el mínimo de asistentes (E3-13.2 / IC-154).
     */
    public function proponerAcuerdo(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        $minimo = (int) $peticion->campo('minimo_asistentes', 0);
        $franjasEntrada = $peticion->campo('franjas');
        $franjas = [];

        if (is_array($franjasEntrada)) {
            foreach ($franjasEntrada as $f) {
                if (is_array($f)) {
                    if (!empty($f['fecha_hora_inicio'])) {
                        $franjas[] = [
                            'fecha_hora_inicio' => (string) $f['fecha_hora_inicio'],
                            'fecha_hora_fin' => !empty($f['fecha_hora_fin']) ? (string) $f['fecha_hora_fin'] : null,
                        ];
                    } elseif (!empty($f['fecha']) && !empty($f['hora_inicio'])) {
                        $fechaStr = trim((string) $f['fecha']);
                        $inicioHora = trim((string) $f['hora_inicio']);
                        $finHora = !empty($f['hora_fin']) ? trim((string) $f['hora_fin']) : null;
                        $franjas[] = [
                            'fecha_hora_inicio' => "{$fechaStr} {$inicioHora}:00",
                            'fecha_hora_fin' => $finHora !== null ? "{$fechaStr} {$finHora}:00" : null,
                        ];
                    }
                }
            }
        }

        if ($franjas === [] && is_array($peticion->campo('franja_inicio'))) {
            $inicios = (array) $peticion->campo('franja_inicio');
            $fines = (array) $peticion->campo('franja_fin', []);
            foreach ($inicios as $idx => $inicio) {
                if (is_string($inicio) && trim($inicio) !== '') {
                    $franjas[] = [
                        'fecha_hora_inicio' => trim($inicio),
                        'fecha_hora_fin' => !empty($fines[$idx]) ? trim((string) $fines[$idx]) : null,
                    ];
                }
            }
        }

        try {
            $acuerdo = $this->servicio->proponerAcuerdoFecha($organizador, $id, $minimo, $franjas);
        } catch (\InvalidArgumentException $error) {
            if ($peticion->cabecera('Accept') === 'application/json' || $peticion->cabecera('Content-Type') === 'application/json') {
                return $this->json(['error' => $error->getMessage()], 422);
            }

            return $this->redirigir("/cartelera/{$id}?error_acuerdo=" . urlencode($error->getMessage()));
        }

        if ($peticion->cabecera('Accept') === 'application/json' || $peticion->cabecera('Content-Type') === 'application/json') {
            return $this->json($acuerdo, 201);
        }

        return $this->redirigir("/cartelera/{$id}?acuerdo_propuesto=1");
    }

    /**
     * Muestra el formulario para proponer nuevas franjas horarias (E3-13.2 / IC-154, E3-13.5 / IC-157).
     */
    public function mostrarProponerFranjas(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        $detalle = $this->servicio->detalle($id);
        $esPropia = $detalle->sala->organizadorId !== null && $detalle->sala->organizadorId === $organizador->id;
        if (!$esPropia) {
            throw ExcepcionHttp::noEncontrada();
        }

        $obraPrincipal = $detalle->obras[0] ?? null;
        $titulo = $obraPrincipal?->titulo ?? 'Función en acuerdo';

        return $this->vista('proponer_franjas.html.twig', [
            'funcion' => [
                'id' => $detalle->id,
                'titulo' => $titulo,
                'cupo' => $detalle->cupo,
                'precio' => $detalle->precioBase,
                'sala' => ['nombre' => $detalle->sala->nombre, 'localidad' => $detalle->sala->localidad],
                'obras' => $detalle->obras,
            ],
            'accion' => "/organizador/funciones/{$id}/proponer-franjas",
            'valores' => [
                'minimo_asistentes' => 10,
            ],
            'errores' => [],
        ]);
    }

    /**
     * El organizador confirma la franja ganadora cuando alcanza el mínimo de asistentes (E3-13.4 / IC-156).
     */
    public function confirmarAcuerdo(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        try {
            $funcion = $this->servicio->confirmarAcuerdo($organizador, $id);
        } catch (ReglaIncumplida $regla) {
            if ($peticion->cabecera('Accept') === 'application/json' || $peticion->cabecera('Content-Type') === 'application/json') {
                return $this->json(['error' => $regla->getMessage()], 422);
            }

            return $this->redirigir("/cartelera/{$id}?error_voto=" . rawurlencode($regla->getMessage()));
        }

        if ($peticion->cabecera('Accept') === 'application/json' || $peticion->cabecera('Content-Type') === 'application/json') {
            return $this->json([
                'ok' => true,
                'estado' => $funcion->estado->value,
                'fecha_hora' => $funcion->fechaHora?->format('Y-m-d H:i:s'),
            ]);
        }

        return $this->redirigir("/cartelera/{$id}?guardada=1");
    }

    /**
     * El organizador cierra el acuerdo sin alcanzar el mínimo de asistentes (E3-13.5 / IC-157).
     */
    public function cerrarSinMinimo(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        try {
            $resultado = $this->servicio->cerrarAcuerdoSinMinimo($organizador, $id);
        } catch (ReglaIncumplida $regla) {
            if ($peticion->cabecera('Accept') === 'application/json' || $peticion->cabecera('Content-Type') === 'application/json') {
                return $this->json(['error' => $regla->getMessage()], 422);
            }

            return $this->redirigir("/cartelera/{$id}?error_voto=" . rawurlencode($regla->getMessage()));
        }

        if ($peticion->cabecera('Accept') === 'application/json' || $peticion->cabecera('Content-Type') === 'application/json') {
            return $this->json([
                'ok' => true,
                'estado_acuerdo' => 'sin_minimo',
            ]);
        }

        return $this->redirigir("/cartelera/{$id}");
    }

    /**
     * @param list<Sala>                                                                                        $salas
     * @param list<Obra>                                                                                        $obras
     * @param array{formulario?: FormularioDeFuncion, valores?: array<string, mixed>, error_general?: ?string} $extra
     */
    private function formularioAlta(
        array $salas,
        array $obras,
        array $extra = [],
        int $estado = 200,
        bool $esEdicion = false,
        ?string $funcionId = null,
    ): Respuesta {
        $formulario = $extra['formulario'] ?? null;
        $valores = $formulario?->valores ?? ($extra['valores'] ?? []);

        return $this->vista(self::PLANTILLA_ALTA, [
            'es_edicion' => $esEdicion,
            'funcion_id' => $funcionId,
            'accion' => $esEdicion && $funcionId !== null
                ? "/organizador/funciones/{$funcionId}/editar"
                : '/organizador/funciones/nueva',
            'salas' => array_map(self::datosSala(...), $salas),
            'obras' => array_map(self::datosObra(...), $obras),
            'valores' => $valores,
            'errores' => $formulario?->errores ?? [],
            'error_general' => $extra['error_general'] ?? null,
            'mensaje_exito' => null,
        ], $estado);
    }

    /**
     * La sala para el desplegable y, al lado del formulario, sus parámetros: los que hereda la
     * función, como en el wireframe.
     *
     * @return array<string, mixed>
     */
    private static function datosSala(Sala $sala): array
    {
        return [
            ...TarjetaDeSala::datos($sala),
            'peliculas_por_funcion' => $sala->datos->peliculasPorFuncion,
            'duracion_funcion' => $sala->datos->duracionFuncion,
            'tiempo_entre_funciones' => $sala->datos->tiempoEntreFunciones,
        ];
    }

    /**
     * @return array{id: string, titulo: string, anio: int, duracion: int, director: string, afiche: ?string}
     */
    private static function datosObra(Obra $obra): array
    {
        return [
            'id' => $obra->id,
            'titulo' => $obra->titulo,
            'anio' => $obra->anio,
            'duracion' => $obra->duracionMinutos,
            'director' => $obra->director,
            'afiche' => $obra->afiche,
        ];
    }

    /**
     * La fila de la tabla, con la fecha y el precio ya escritos como se leen, igual que en la
     * tarjeta de la cartelera. La plantilla recibe datos y no la entidad.
     *
     * @return array{id: string, titulo: string, titulos_elegidos: bool, sala: string, sala_id: string, sala_habilitada: bool, estado: string, estado_valor: string, es_final: bool, editable: bool, cancelable: bool, cerrable: bool, fecha: ?string, precio: string, cupo: int}
     */
    private static function datos(FuncionDelOrganizador $funcion): array
    {
        return [
            'id' => $funcion->id,
            // Una función con varias obras las proyecta todas: el título las nombra a todas.
            'titulo' => implode(' + ', $funcion->titulos),
            'titulos_elegidos' => $funcion->titulosElegidos,
            'sala' => $funcion->salaNombre,
            'sala_id' => $funcion->salaId,
            'sala_habilitada' => $funcion->salaHabilitada,
            'estado' => $funcion->estado->etiqueta(),
            'estado_valor' => $funcion->estado->value,
            'es_final' => $funcion->estado->esFinal(),
            'editable' => Funcion::seEdita($funcion->estado, $funcion->fechaHora),
            'cancelable' => Funcion::seCancela($funcion->estado, $funcion->fechaHora),
            'cerrable' => $funcion->estado === EstadoFuncion::EnVotacionTitulos,
            // null mientras la función no tiene fecha: la plantilla lo dice con palabras.
            'fecha' => $funcion->fechaHora === null ? null : TarjetaDeFuncion::fecha($funcion->fechaHora),
            'precio' => TarjetaDeFuncion::precio($funcion->precioBase),
            'cupo' => $funcion->cupo,
        ];
    }

    private static function organizador(Peticion $peticion): Usuario
    {
        // La ruta pide el rol de organizador: si llegó acá, hay sesión.
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }
}
