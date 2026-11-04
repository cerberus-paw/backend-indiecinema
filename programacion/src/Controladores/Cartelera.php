<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\CupoDeFuncion;
use IndieCinema\Programacion\Modelo\DetalleDeFuncion;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\ObraEnDetalle;
use IndieCinema\Programacion\Modelo\OrdenDeCartelera;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Servicios\OcupacionDeFunciones;
use IndieCinema\Programacion\Servicios\ServicioDeCartelera;

/**
 * GET /cartelera: las funciones programadas que todavía no pasaron, para cualquiera, con o sin
 * sesión. Los filtros y la página viajan en la URL (un formulario GET), así funcionan sin
 * JavaScript y se pueden compartir. El mismo pedido con «Accept: application/json» devuelve la
 * misma consulta en JSON, para actualizar la lista sin recargar. GET /cartelera.csv descarga la
 * cartelera con los mismos filtros. GET /cartelera/{id} muestra la ficha de una función con sus
 * obras y su sala.
 */
final class Cartelera extends Controlador
{
    public function __construct(
        private readonly ServicioDeCartelera $servicio,
        private readonly OcupacionDeFunciones $ocupacion,
    ) {
    }

    /**
     * La página en HTML o, si el pedido acepta JSON, la misma consulta en JSON: las funciones de
     * la página, los filtros aplicados, los avisos, el paginado y el link al CSV. Las dos llevan
     * «Vary: Accept», porque la misma URL tiene dos representaciones y una caché (la del navegador
     * al volver atrás) no las tiene que mezclar.
     *
     * @throws ExcepcionHttp 404 si la página no es un número o no existe (en JSON, si se pidió JSON)
     */
    public function listar(Peticion $peticion): Respuesta
    {
        $parametros = $this->parametros($peticion);
        // «pagina=abc» o «pagina=0» no es una página que exista, igual que una después de la última.
        if ($parametros->pagina === null) {
            throw ExcepcionHttp::noEncontrada();
        }
        $pagina = $this->servicio->pagina($parametros->filtros, $parametros->pagina);
        $total = $pagina->totalDePaginas();
        $paginado = [
            'numero' => $pagina->numero,
            'total_paginas' => $total,
            'total_funciones' => $pagina->total,
            'anterior' => $pagina->numero > 1 ? $parametros->urlDePagina($pagina->numero - 1) : null,
            'siguiente' => $pagina->numero < $total ? $parametros->urlDePagina($pagina->numero + 1) : null,
        ];

        $tarjetas = $this->tarjetas($pagina->funciones);

        if ($peticion->aceptaJson()) {
            return $this->json([
                // Con el link a la ficha, que en HTML arma la plantilla de la tarjeta.
                'funciones' => array_map(
                    static fn (array $tarjeta): array => $tarjeta + ['url' => "/cartelera/{$tarjeta['id']}"],
                    $tarjetas,
                ),
                // Un objeto aunque no haya filtros: vacío, json_encode() lo escribiría como lista.
                'filtros' => (object) $parametros->valores,
                'avisos' => $parametros->avisos,
                'paginado' => $paginado,
                'csv' => $parametros->urlDeCsv(),
            ])->conCabecera('Vary', 'Accept');
        }

        return $this->vista('cartelera.html.twig', [
            'funciones' => $tarjetas,
            'filtros' => $parametros->valores,
            'filtrada' => $parametros->filtrada(),
            'avisos' => $parametros->avisos,
            'localidades' => $this->servicio->localidades(),
            'generos' => array_map(static fn (Genero $genero): array => ['id' => $genero->id, 'nombre' => $genero->nombre], $this->servicio->generos()),
            'ordenes' => OrdenDeCartelera::opciones(),
            'paginado' => $paginado,
            'csv' => $parametros->urlDeCsv(),
        ])->conCabecera('Vary', 'Accept');
    }

    /**
     * Todas las funciones filtradas, no sólo las de una página. Un filtro inválido se deja afuera
     * como en la página, aunque acá no hay dónde avisarlo, y la página se ignora.
     */
    public function descargar(Peticion $peticion): Respuesta
    {
        $funciones = $this->servicio->todas($this->parametros($peticion)->filtros);

        return (new Respuesta(CsvDeCartelera::armar($funciones)))
            ->conCabecera('Content-Type', 'text/csv; charset=utf-8')
            ->conCabecera('Content-Disposition', 'attachment; filename="cartelera.csv"');
    }

    /**
     * GET /cartelera/{id}: la ficha de una función con sus obras y su sala.
     *
     * @throws ExcepcionHttp 404 si la función no existe, su sala no está publicada o el id no es un UUID
     */
    public function detalle(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        $detalle = $this->servicio->detalle($id);
        $cupo = new CupoDeFuncion($detalle->id, $detalle->estado, $detalle->fechaHora, $detalle->cupo);
        $lugares = $this->lugaresDe($cupo);
        $reservable = self::seReservaConLugares($cupo, $lugares['agotada']);

        $usuario = $peticion->usuario();
        $esPropia = $usuario !== null
            && $usuario->alcanza(Rol::Organizador)
            && $detalle->sala->organizadorId !== null
            && $detalle->sala->organizadorId === $usuario->id;
        $editable = $esPropia && Funcion::seEdita($detalle->estado, $detalle->fechaHora);
        $cancelable = $esPropia && Funcion::seCancela($detalle->estado, $detalle->fechaHora);
        $cerrable = $esPropia && $detalle->estado === EstadoFuncion::EnVotacionTitulos;

        $votoActual = $usuario !== null ? $this->servicio->votoDe($detalle->id, $usuario->id) : null;
        $conteoVotos = $this->servicio->conteoDeVotos($detalle->id);

        $datosFuncion = [...self::datosDeDetalle($detalle, $editable, $cancelable, $cerrable), ...$lugares];
        $datosFuncion['es_organizador'] = $esPropia;
        $franjasDelUsuario = [];
        $conteoVotosFranjas = [];
        $acuerdo = null;

        if ($detalle->estado === EstadoFuncion::EnAcuerdoFecha) {
            $acuerdo = $this->servicio->acuerdoDeFecha($id, $usuario?->id);
            // Recién cerrada la votación, todavía no hay franjas: la ficha lo dice y le ofrece al
            // organizador proponerlas, en vez de mostrar franjas de ejemplo.
            $datosFuncion['sin_franjas'] = $acuerdo === null;
            if ($acuerdo !== null) {
                $minimoAsistentes = (int) ($acuerdo['minimo_asistentes'] ?? 20);
                $datosFuncion['minimo_asistentes'] = $minimoAsistentes;
                $datosFuncion['acuerdo_sin_minimo'] = ($acuerdo['estado'] ?? '') === 'sin_minimo';
                // Hasta cuándo se marca «Me sirve» (E3-13.F8): pasada, la ficha no ofrece marcar.
                $limite = is_string($acuerdo['fecha_limite'] ?? null) ? DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $acuerdo['fecha_limite']) : false;
                $datosFuncion['fecha_limite'] = $limite !== false ? TarjetaDeFuncion::diaMesYHora($limite) : null;
                $datosFuncion['votacion_abierta'] = ($acuerdo['votacion_abierta'] ?? true) === true;
                // Las que marcó quien mira: pueden ser varias (E3-13.F7).
                $franjasDelUsuario = is_array($acuerdo['franjas_del_usuario'] ?? null) ? $acuerdo['franjas_del_usuario'] : [];
                $datosFuncion['franjas_del_usuario'] = $franjasDelUsuario;

                // Una función tiene siempre alguna obra (DetalleDeFuncion), así que hay duración.
                $duracionMinutos = array_sum(array_map(static fn (ObraEnDetalle $o): int => $o->duracionMinutos, $detalle->obras));

                $franjas = [];
                $maxVotosFranja = -1;
                $franjaLider = null;
                foreach ($acuerdo['franjas'] ?? [] as $f) {
                    $inicioStr = (string) ($f['inicio'] ?? '');
                    $finStr = isset($f['fin']) ? (string) $f['fin'] : null;
                    $inicioDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $inicioStr) ?: new DateTimeImmutable($inicioStr);
                    $finDt = $finStr !== null
                        ? (DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $finStr) ?: new DateTimeImmutable($finStr))
                        : $inicioDt->modify("+{$duracionMinutos} minutes");

                    $votosF = (int) ($f['votos'] ?? 0);
                    $conteoVotosFranjas[$f['id']] = $votosF;

                    $franjaItem = [
                        'id' => $f['id'],
                        'fecha' => self::formatearFechaLarga($inicioDt),
                        'fecha_corta' => TarjetaDeFuncion::diaCorto($inicioDt),
                        'hora_inicio' => $inicioDt->format('H:i'),
                        'hora_fin' => $finDt->format('H:i'),
                        'desde' => $inicioDt->format('H:i'),
                        'hasta' => $finDt->format('H:i'),
                        'votos' => $votosF,
                    ];

                    if ($votosF > $maxVotosFranja) {
                        $maxVotosFranja = $votosF;
                        $franjaLider = $franjaItem;
                    }

                    $franjas[] = $franjaItem;
                }
                $datosFuncion['franjas'] = $franjas;
                $datosFuncion['confirmable'] = $esPropia && ($maxVotosFranja >= $minimoAsistentes);
                $datosFuncion['franja_ganadora'] = $franjaLider;
                $datosFuncion['obra_ganadora'] = $datosFuncion['obras'][0] ?? null;
                // Los votos que la eligió en la votación de títulos: la plantilla mostraba un número
                // fijo (E3-13.F3).
                $datosFuncion['votos_obra_ganadora'] = $conteoVotos[$datosFuncion['obra_ganadora']['id'] ?? ''] ?? 0;
                // «Ganaron la votación con 112 y 92 votos sobre 295 emitidos», como el wireframe.
                $datosFuncion['votos_emitidos'] = array_sum($conteoVotos);
            }
        }

        if ($peticion->aceptaJson()) {
            return $this->json([
                'funcion' => [
                    ...$datosFuncion,
                    'reservable' => $reservable,
                ],
                'acuerdo_fecha' => $acuerdo,
                'voto_usuario' => $votoActual,
                'franjas_del_usuario' => $franjasDelUsuario,
                'conteo_votos' => $conteoVotos,
                'conteo_votos_franjas' => $conteoVotosFranjas,
            ])->conCabecera('Vary', 'Accept');
        }

        return $this->vista('ficha_funcion.html.twig', [
            'funcion' => [
                ...$datosFuncion,
                'reservable' => $reservable,
            ],
            'acuerdo_fecha' => $acuerdo,
            'es_organizador' => $esPropia,
            'voto_usuario' => $votoActual,
            'franjas_del_usuario' => $franjasDelUsuario,
            'conteo_votos' => $conteoVotos,
            'conteo_votos_franjas' => $conteoVotosFranjas,
            'votado' => $peticion->consulta('votado') === '1',
            'franja_votada' => $peticion->consulta('votado') === '1',
            'franja_quitada' => $peticion->consulta('quitado') === '1',
            'error_voto' => $peticion->consulta('error_voto'),
            'error_franja' => $peticion->consulta('error_voto'),
            // Lo que no salió al confirmar o cerrar el acuerdo: sólo se le muestra al organizador.
            'error_acuerdo' => $esPropia ? $peticion->consulta('error_acuerdo') : null,
            'votacion_cerrada' => $esPropia && $peticion->consulta('votacion_cerrada') === '1',
            // El aviso de que se acaba de guardar: sólo para el organizador que la editó. La ficha
            // es pública, y si no, lo vería cualquiera que abra el enlace con el parámetro puesto.
            'guardada' => $esPropia && $peticion->consulta('guardada') === '1',
            'accion_votar_franja' => "/cartelera/{$id}/votar",
        ])->conCabecera('Vary', 'Accept');
    }

    /**
     * GET /cartelera/{id}/lugares: sólo los lugares libres de la función, en JSON, para que la
     * ficha los actualice sola sin recargar (E4-05.1). Es aparte de la ficha en JSON porque se
     * pide cada pocos segundos: sólo busca el estado, la fecha y el cupo, sin las obras, la sala,
     * el acuerdo ni los votos.
     *
     * Es pública, como la ficha, y no dice nada que la ficha no muestre.
     *
     * @throws ExcepcionHttp 404 si la función no existe, su sala no está publicada o el id no es un UUID
     */
    public function lugares(Peticion $peticion): Respuesta
    {
        $cupo = $this->servicio->cupo((string) $peticion->parametro('id'));
        $lugares = $this->lugaresDe($cupo);

        return $this->json([
            ...$lugares,
            'cupo' => $cupo->cupo,
            'reservable' => self::seReservaConLugares($cupo, $lugares['agotada']),
        ]);
    }

    /**
     * Los lugares libres de una función y si está agotada. Sólo de una programada por darse se
     * pregunta: en otro estado no hay reservas que contar, y una que ya pasó no está «agotada»
     * aunque se hayan tomado todos los lugares. Sin el dato de funciones, null.
     *
     * @return array{lugares_libres: ?int, agotada: bool}
     */
    private function lugaresDe(CupoDeFuncion $funcion): array
    {
        $libres = TarjetaDeFuncion::muestraLugares($funcion->estado, $funcion->fechaHora)
            ? TarjetaDeFuncion::lugaresLibres($funcion->estado, $funcion->fechaHora, $funcion->cupo, $this->ocupacion->deUna($funcion->id))
            : null;

        return ['lugares_libres' => $libres, 'agotada' => $libres === 0];
    }

    /**
     * Agotada no se reserva: el formulario no tendría cómo salir bien.
     */
    private static function seReservaConLugares(CupoDeFuncion $funcion, bool $agotada): bool
    {
        return Funcion::seReserva($funcion->estado, $funcion->fechaHora) && !$agotada;
    }

    /**
     * POST /cartelera/{id}/votar: registra o cambia el voto del espectador (E3-12.3).
     *
     * @throws ExcepcionHttp 404 si la función no existe o no es UUID, 403 si no hay sesión
     */
    public function votar(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        $usuario = $peticion->usuario();
        if ($usuario === null) {
            throw ExcepcionHttp::prohibida();
        }

        $cuerpo = $peticion->cuerpo();
        $franjaId = (string) ($cuerpo['franja_id'] ?? '');
        $obraId = (string) ($cuerpo['obra_id'] ?? '');

        try {
            if ($franjaId !== '') {
                // «✓ Me sirve» en una franja ya marcada la quita (E3-13.F7).
                $this->servicio->votarFranjaAcuerdo($id, $usuario->id, $franjaId, ($cuerpo['quitar'] ?? null) !== '1');
            } else {
                $this->servicio->votar($id, $usuario->id, $obraId);
            }
        } catch (ReglaIncumplida $regla) {
            if ($peticion->cabecera('Accept') === 'application/json' || $peticion->cabecera('Content-Type') === 'application/json') {
                return $this->json(['error' => $regla->getMessage()], 422);
            }

            return $this->redirigir("/cartelera/{$id}?error_voto=" . rawurlencode($regla->getMessage()));
        }

        if ($peticion->cabecera('Accept') === 'application/json' || $peticion->cabecera('Content-Type') === 'application/json') {
            return $this->json(['ok' => true]);
        }

        $quitado = $franjaId !== '' && ($cuerpo['quitar'] ?? null) === '1';

        return $this->redirigir("/cartelera/{$id}?" . ($quitado ? 'quitado=1' : 'votado=1'));
    }

    /**
     * Las tarjetas con los lugares libres, pedidos a funciones en una sola llamada para toda la
     * página. Si no responde, van sin ese dato.
     *
     * @param list<FuncionEnCartelera> $funciones
     *
     * @return list<array<string, mixed>>
     */
    private function tarjetas(array $funciones): array
    {
        return TarjetaDeFuncion::todas(
            $funciones,
            $this->servicio->acuerdosDe($funciones),
            $this->ocupacion->deVarias(TarjetaDeFuncion::conLugares($funciones)),
        );
    }

    private function parametros(Peticion $peticion): ParametrosDeCartelera
    {
        return ParametrosDeCartelera::desdeConsulta([
            'q' => $peticion->consulta('q'),
            'localidad' => $peticion->consulta('localidad'),
            'fecha' => $peticion->consulta('fecha'),
            'genero' => $peticion->consulta('genero'),
            'precio_min' => $peticion->consulta('precio_min'),
            'precio_max' => $peticion->consulta('precio_max'),
            'orden' => $peticion->consulta('orden'),
            'pagina' => $peticion->consulta('pagina'),
        ], $this->servicio->generoPorNombre(...));
    }

    /**
     * Lo que muestra programacion/ficha_funcion del paquete front, con la fecha y el precio ya
     * escritos como en la tarjeta. La fecha llega entera («Sáb 19 sep · 20:30») y también en los
     * recuadros del wireframe «funcion-programada» (E4-10.10): el día solo, el horario de inicio a
     * fin y la duración total, la suma de las de sus obras elegidas.
     *
     * @return array{
     *     id: string,
     *     titulo: string,
     *     estado: string,
     *     estado_etiqueta: string,
     *     es_cancelada: bool,
     *     precio: string,
     *     cupo: int,
     *     fecha: ?string,
     *     dia: ?string,
     *     horario: ?string,
     *     duracion_total: ?int,
     *     obras_elegidas: bool,
     *     editable: bool,
     *     cancelable: bool,
     *     cerrable: bool,
     *     sala: array{url: string, nombre: string, descripcion: string, direccion: string, localidad: string, capacidad: int, imagen: ?string},
     *     obras: list<array{id: string, titulo: string, sinopsis: string, anio: int, director: string, duracion: int, generos: list<string>, afiche: ?string}>
     * }
     */
    private static function datosDeDetalle(
        DetalleDeFuncion $detalle,
        bool $editable = false,
        bool $cancelable = false,
        bool $cerrable = false,
    ): array {
        // Una función tiene siempre alguna obra (DetalleDeFuncion), pero mientras los títulos
        // están en votación ésas son las candidatas y no lo que se va a proyectar: sumarlas daría
        // la duración de todo lo que compite. Hasta que la votación cierre no hay duración, y sin
        // duración tampoco hay horario de fin.
        $duracion = $detalle->obrasElegidas
            ? array_sum(array_map(static fn (ObraEnDetalle $obra): int => $obra->duracionMinutos, $detalle->obras))
            : null;

        return [
            'id' => $detalle->id,
            'titulo' => implode(' + ', array_map(static fn (ObraEnDetalle $obra): string => $obra->titulo, $detalle->obras)),
            'estado' => $detalle->estado->value,
            'estado_etiqueta' => $detalle->estado->etiqueta(),
            'es_cancelada' => $detalle->estado === EstadoFuncion::Cancelada,
            'precio' => TarjetaDeFuncion::precio($detalle->precioBase),
            'cupo' => $detalle->cupo,
            'fecha' => $detalle->fechaHora !== null ? TarjetaDeFuncion::fecha($detalle->fechaHora) : null,
            'dia' => $detalle->fechaHora !== null ? TarjetaDeFuncion::dia($detalle->fechaHora) : null,
            // «20:30 a 23:30»: termina cuando termina la última de sus obras.
            'horario' => $detalle->fechaHora !== null && $duracion !== null
                ? $detalle->fechaHora->format('H:i') . ' a ' . $detalle->fechaHora->modify("+{$duracion} minutes")->format('H:i')
                : null,
            'duracion_total' => $duracion,
            'obras_elegidas' => $detalle->obrasElegidas,
            'editable' => $editable,
            'cancelable' => $cancelable,
            'cerrable' => $cerrable,
            'sala' => [
                'url' => "/salas/{$detalle->sala->id}",
                'nombre' => $detalle->sala->nombre,
                'descripcion' => $detalle->sala->descripcion,
                'direccion' => $detalle->sala->direccion,
                'localidad' => $detalle->sala->localidad,
                'capacidad' => $detalle->sala->capacidad,
                'imagen' => $detalle->sala->imagen !== null ? "/salas/{$detalle->sala->id}/imagen" : null,
            ],
            'obras' => array_map(static fn (ObraEnDetalle $obra): array => [
                'id' => $obra->id,
                'titulo' => $obra->titulo,
                'sinopsis' => $obra->sinopsis,
                'anio' => $obra->anio,
                'director' => $obra->director,
                'duracion' => $obra->duracionMinutos,
                'generos' => $obra->generos,
                'afiche' => $obra->afiche,
            ], $detalle->obras),
        ];
    }

    private const DIAS_COMPLETOS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    private const MESES_COMPLETOS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public static function formatearFechaLarga(DateTimeImmutable $fecha): string
    {
        return sprintf(
            '%s %d de %s',
            self::DIAS_COMPLETOS[(int) $fecha->format('w')],
            (int) $fecha->format('j'),
            self::MESES_COMPLETOS[(int) $fecha->format('n') - 1],
        );
    }
}
