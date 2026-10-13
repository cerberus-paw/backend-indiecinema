<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Programacion\Modelo\DetalleDeFuncion;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\ObraEnDetalle;
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
    public function __construct(private readonly ServicioDeCartelera $servicio)
    {
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

        if ($peticion->aceptaJson()) {
            return $this->json([
                // Con el link a la ficha, que en HTML arma la plantilla de la tarjeta.
                'funciones' => array_map(
                    static fn (FuncionEnCartelera $funcion): array => TarjetaDeFuncion::datos($funcion) + ['url' => "/cartelera/{$funcion->id}"],
                    $pagina->funciones,
                ),
                // Un objeto aunque no haya filtros: vacío, json_encode() lo escribiría como lista.
                'filtros' => (object) $parametros->valores,
                'avisos' => $parametros->avisos,
                'paginado' => $paginado,
                'csv' => $parametros->urlDeCsv(),
            ])->conCabecera('Vary', 'Accept');
        }

        return $this->vista('cartelera.html.twig', [
            'funciones' => array_map(TarjetaDeFuncion::datos(...), $pagina->funciones),
            'filtros' => $parametros->valores,
            'filtrada' => $parametros->valores !== [],
            'avisos' => $parametros->avisos,
            'localidades' => $this->servicio->localidades(),
            'generos' => array_map(static fn (Genero $genero): array => ['id' => $genero->id, 'nombre' => $genero->nombre], $this->servicio->generos()),
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

        return $this->vista('ficha_funcion.html.twig', [
            'funcion' => self::datosDeDetalle($detalle),
        ]);
    }

    private function parametros(Peticion $peticion): ParametrosDeCartelera
    {
        return ParametrosDeCartelera::desdeConsulta([
            'q' => $peticion->consulta('q'),
            'localidad' => $peticion->consulta('localidad'),
            'fecha' => $peticion->consulta('fecha'),
            'genero' => $peticion->consulta('genero'),
            'pagina' => $peticion->consulta('pagina'),
        ], $this->servicio->generoPorNombre(...));
    }

    /**
     * Lo que muestra programacion/ficha_funcion del paquete front, con la fecha y el precio ya
     * escritos como en la tarjeta.
     *
     * @return array{
     *     titulo: string,
     *     estado: string,
     *     estado_etiqueta: string,
     *     es_cancelada: bool,
     *     precio: string,
     *     cupo: int,
     *     fecha: ?string,
     *     obras_elegidas: bool,
     *     sala: array{url: string, nombre: string, descripcion: string, direccion: string, localidad: string, capacidad: int, imagen: ?string},
     *     obras: list<array{titulo: string, sinopsis: string, anio: int, director: string, duracion: int, generos: list<string>}>
     * }
     */
    private static function datosDeDetalle(DetalleDeFuncion $detalle): array
    {
        return [
            'titulo' => implode(' + ', array_map(static fn (ObraEnDetalle $obra): string => $obra->titulo, $detalle->obras)),
            'estado' => $detalle->estado->value,
            'estado_etiqueta' => $detalle->estado->etiqueta(),
            'es_cancelada' => $detalle->estado === EstadoFuncion::Cancelada,
            'precio' => TarjetaDeFuncion::precio($detalle->precioBase),
            'cupo' => $detalle->cupo,
            'fecha' => $detalle->fechaHora !== null ? TarjetaDeFuncion::fecha($detalle->fechaHora) : null,
            'obras_elegidas' => $detalle->obrasElegidas,
            'sala' => [
                'url' => "/salas/{$detalle->sala->id}",
                'nombre' => $detalle->sala->nombre,
                'descripcion' => $detalle->sala->descripcion,
                'direccion' => $detalle->sala->direccion,
                'localidad' => $detalle->sala->localidad,
                'capacidad' => $detalle->sala->capacidad,
                'imagen' => $detalle->sala->imagen !== null ? "/salas/{$detalle->sala->id}/imagen" : null,
            ],
            // Sin el afiche: todavía no hay una ruta que lo sirva.
            'obras' => array_map(static fn (ObraEnDetalle $obra): array => [
                'titulo' => $obra->titulo,
                'sinopsis' => $obra->sinopsis,
                'anio' => $obra->anio,
                'director' => $obra->director,
                'duracion' => $obra->duracionMinutos,
                'generos' => $obra->generos,
            ], $detalle->obras),
        ];
    }
}
