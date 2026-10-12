<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Servicios\ServicioDeCartelera;

/**
 * GET /cartelera: las funciones programadas que todavía no pasaron, para cualquiera, con o sin
 * sesión. Los filtros y la página viajan en la URL (un formulario GET), así funcionan sin
 * JavaScript y se pueden compartir.
 */
final class Cartelera extends Controlador
{
    public function __construct(private readonly ServicioDeCartelera $servicio)
    {
    }

    /**
     * @throws ExcepcionHttp 404 si la página no es un número o no existe
     */
    public function listar(Peticion $peticion): Respuesta
    {
        $parametros = ParametrosDeCartelera::desdeConsulta([
            'q' => $peticion->consulta('q'),
            'localidad' => $peticion->consulta('localidad'),
            'fecha' => $peticion->consulta('fecha'),
            'genero' => $peticion->consulta('genero'),
            'pagina' => $peticion->consulta('pagina'),
        ]);
        // «pagina=abc» o «pagina=0» no es una página que exista, igual que una después de la última.
        if ($parametros->pagina === null) {
            throw ExcepcionHttp::noEncontrada();
        }
        $pagina = $this->servicio->pagina($parametros->filtros, $parametros->pagina);
        $total = $pagina->totalDePaginas();

        return $this->vista('cartelera.html.twig', [
            'funciones' => array_map(TarjetaDeFuncion::datos(...), $pagina->funciones),
            'filtros' => $parametros->valores,
            'filtrada' => $parametros->valores !== [],
            'avisos' => $parametros->avisos,
            'localidades' => $this->servicio->localidades(),
            'generos' => array_map(static fn (Genero $genero): array => ['id' => $genero->id, 'nombre' => $genero->nombre], $this->servicio->generos()),
            'paginado' => [
                'numero' => $pagina->numero,
                'total_paginas' => $total,
                'total_funciones' => $pagina->total,
                'anterior' => $pagina->numero > 1 ? $parametros->urlDePagina($pagina->numero - 1) : null,
                'siguiente' => $pagina->numero < $total ? $parametros->urlDePagina($pagina->numero + 1) : null,
            ],
        ]);
    }
}
