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
use IndieCinema\Programacion\Servicios\AcuerdosDeFunciones;
use IndieCinema\Programacion\Servicios\ReservasDeFunciones;
use IndieCinema\Programacion\Servicios\ServicioDeFuncionesDelOrganizador;
use LogicException;

/**
 * «Mis funciones», alta, edición y cancelación de funciones, en /organizador/funciones/….
 * Las rutas piden el rol de organizador y el núcleo controla el token CSRF en los POST.
 */
final class FuncionesDelOrganizador extends Controlador
{
    private const PLANTILLA_ALTA = 'alta_funcion.html.twig';

    public function __construct(
        private readonly ServicioDeFuncionesDelOrganizador $servicio,
        private readonly ReservasDeFunciones $reservas,
    ) {
    }

    public function listar(Peticion $peticion): Respuesta
    {
        $funciones = $this->servicio->listar(self::organizador($peticion));
        $acuerdos = $this->servicio->acuerdosDe($funciones);

        return $this->vista('mis_funciones.html.twig', [
            'funciones' => array_map(
                static fn (FuncionDelOrganizador $funcion): array => [
                    ...self::datos($funcion),
                    ...self::datosDelAcuerdo($funcion, $acuerdos),
                ],
                $funciones,
            ),
            // El aviso de que se acaba de programar una: sólo si lo que llega tiene forma de id.
            'programada' => is_string($programada = $peticion->consulta('programada')) && Uuid::esValido($programada),
            'cancelada' => $peticion->consulta('cancelada') === '1',
            // No se pudo cancelar: funciones no respondió y la función quedó como estaba (F-01.7).
            'cancelacion_fallida' => $peticion->consulta('cancelacion') === 'fallida',
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
            // Sin mensaje general: la plantilla resume los errores de los campos (E4-10.5).
            return $this->formularioAlta($salas, $obras, ['formulario' => $formulario], 422);
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
                extra: ['formulario' => $formulario],
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

        // Antes de cancelarla, para el aviso a los espectadores: el título y la fecha.
        $funcion = $this->servicio->paraCancelar($organizador, $id);
        $anterior = $this->servicio->cancelar($organizador, $id);

        // Sólo una programada pudo tener reservas: en votación todavía no se reserva (E4-03.4).
        // Si funciones no responde, sus reservas seguirían confirmadas en una función cancelada y
        // sin aviso: la función vuelve a como estaba y el organizador lo intenta de nuevo (F-01.7).
        if ($funcion->estado === EstadoFuncion::Programada && !$this->reservas->cancelarDeLaFuncion(
            $id,
            $organizador->id,
            implode(' + ', $funcion->titulos),
            $funcion->fechaHora === null ? null : TarjetaDeFuncion::fecha($funcion->fechaHora),
        )) {
            $this->servicio->deshacerCancelacion($anterior);

            return $this->redirigir('/organizador/funciones?cancelacion=fallida');
        }

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

        // Lo que sigue es proponer las franjas (E3-13.2): sin esto, el organizador no tenía cómo
        // llegar al formulario. Da igual desde dónde la cerró.
        return $this->redirigir("/organizador/funciones/{$id}/proponer-franjas?votacion_cerrada=1");
    }

    /**
     * El organizador propone las franjas y el mínimo de asistentes (E3-13.2). Si algo no está
     * bien, vuelve el formulario con lo que cargó y el motivo, en vez de redirigir (E3-13.F2).
     */
    public function proponerAcuerdo(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }
        $funcion = $this->servicio->paraProponerFranjas($organizador, $id);
        $json = $peticion->cabecera('Accept') === 'application/json' || $peticion->cabecera('Content-Type') === 'application/json';

        $propuesta = PropuestaDeFranjas::desdeCampos($peticion->cuerpo(), $funcion->cupo);
        $error = null;
        if ($propuesta->esValida()) {
            try {
                $acuerdo = $this->servicio->proponerAcuerdoFecha($organizador, $id, $propuesta->minimo, $propuesta->franjas);

                return $json ? $this->json($acuerdo, 201) : $this->redirigir("/cartelera/{$id}?acuerdo_propuesto=1");
            } catch (ReglaIncumplida | \InvalidArgumentException $regla) {
                $error = $regla->getMessage();
            }
        }

        if ($json) {
            return $this->json(['error' => $error ?? implode(' ', $propuesta->errores), 'errores' => $propuesta->errores], 422);
        }

        return $this->formularioDeFranjas($funcion, $propuesta->valores, $propuesta->errores, $error, 422);
    }

    public function mostrarProponerFranjas(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $this->formularioDeFranjas(
            $this->servicio->paraProponerFranjas($organizador, $id),
            ['minimo_asistentes' => 10],
            votacionCerrada: $peticion->consulta('votacion_cerrada') === '1',
        );
    }

    /**
     * @param array<string, mixed>  $valores lo que cargó, para volver a mostrarlo
     * @param array<string, string> $errores campo => motivo
     */
    private function formularioDeFranjas(
        FuncionDelOrganizador $funcion,
        array $valores,
        array $errores = [],
        ?string $errorGeneral = null,
        int $estado = 200,
        bool $votacionCerrada = false,
    ): Respuesta {
        $datos = [
            'funcion' => [
                'id' => $funcion->id,
                'titulo' => implode(' + ', $funcion->titulos),
                'cupo' => $funcion->cupo,
                'precio' => $funcion->precioBase,
                'sala' => ['nombre' => $funcion->salaNombre],
                'obras' => array_map(static fn (string $t) => ['titulo' => $t], $funcion->titulos),
            ],
            'accion' => "/organizador/funciones/{$funcion->id}/proponer-franjas",
            'valores' => $valores,
            'errores' => $errores,
            // Al llegar recién cerrada la votación de títulos: el aviso de que ahora van las franjas.
            'votacion_cerrada' => $votacionCerrada,
        ];
        // La plantilla lo muestra si la variable existe: sólo se pasa cuando hay algo que decir.
        if ($errorGeneral !== null) {
            $datos['error_general'] = $errorGeneral;
        }

        return $this->vista('proponer_franjas.html.twig', $datos, $estado);
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

            // Un aviso propio: el de error_voto habla del voto del espectador (E3-13.F4).
            return $this->redirigir("/cartelera/{$id}?error_acuerdo=" . rawurlencode($regla->getMessage()));
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

            // Un aviso propio: el de error_voto habla del voto del espectador (E3-13.F4).
            return $this->redirigir("/cartelera/{$id}?error_acuerdo=" . rawurlencode($regla->getMessage()));
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
            // Las que el navegador puede revisar solo, con los mensajes de FormularioDeFuncion:
            // dice lo mismo que diría el servidor, mientras se carga (E4-09.1).
            'reglas' => FormularioDeFuncion::reglas(),
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

    /**
     * Lo que «Mis funciones» necesita del acuerdo de fecha para ofrecer el paso que sigue:
     * proponer las franjas, confirmar la fecha o, si cerró sin mínimo, proponer otras.
     *
     * @param array<string, array<string, mixed>|false|null> $acuerdos los de acuerdosDe()
     *
     * @return array{acuerdo?: string, confirmable?: bool, acuerdo_sin_minimo?: bool}
     */
    private static function datosDelAcuerdo(FuncionDelOrganizador $funcion, array $acuerdos): array
    {
        if (!array_key_exists($funcion->id, $acuerdos)) {
            return [];
        }
        $acuerdo = $acuerdos[$funcion->id];
        $estado = match (true) {
            $acuerdo === false => 'sin_dato',
            $acuerdo === null => 'sin_franjas',
            default => (string) ($acuerdo['estado'] ?? 'sin_dato'),
        };

        return [
            'acuerdo' => $estado,
            'confirmable' => is_array($acuerdo) && AcuerdosDeFunciones::alcanzoElMinimo($acuerdo),
            'acuerdo_sin_minimo' => $estado === 'sin_minimo',
        ];
    }

    private static function organizador(Peticion $peticion): Usuario
    {
        // La ruta pide el rol de organizador: si llegó acá, hay sesión.
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }
}
