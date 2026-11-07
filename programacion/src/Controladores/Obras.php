<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Servicios\ServicioDeObras;

/**
 * GET /obras: el listado público del catálogo de obras, con buscador por título, director y género.
 */
final class Obras extends Controlador
{
    private const PLANTILLA_LISTADO = 'obras.html.twig';

    public function __construct(
        private readonly ServicioDeObras $servicio,
    ) {
    }

    public function listar(Peticion $peticion): Respuesta
    {
        $parametros = ParametrosDeObras::desdeConsulta(
            ['q' => $peticion->consulta('q'), 'genero' => $peticion->consulta('genero')],
            $this->servicio->generoPorNombre(...),
        );

        $obras = $this->servicio->catalogo($parametros->texto, $parametros->genero?->id);
        $mapaGeneros = $this->servicio->mapaGeneros();

        $tarjetas = array_map(
            static fn (Obra $obra): array => TarjetaDeObra::datos($obra, $mapaGeneros),
            $obras,
        );

        if ($peticion->aceptaJson()) {
            return $this->json([
                'obras' => $tarjetas,
                'filtros' => (object) $parametros->valores,
                'avisos' => $parametros->avisos,
            ]);
        }

        return $this->vista(self::PLANTILLA_LISTADO, [
            'obras' => $tarjetas,
            'filtros' => $parametros->valores,
            'filtrada' => $parametros->valores !== [],
            'generos' => array_map(static fn (Genero $g): array => ['id' => $g->id, 'nombre' => $g->nombre], $this->servicio->generos()),
            'avisos' => $parametros->avisos,
        ]);
    }
}
