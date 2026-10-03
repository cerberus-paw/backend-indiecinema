<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;

/**
 * GET /salas: el listado público de salas habilitadas, para cualquiera, con o sin sesión.
 */
final class Salas extends Controlador
{
    public function __construct(private readonly ServicioDeSalas $servicio)
    {
    }

    public function listar(Peticion $peticion): Respuesta
    {
        return $this->vista('salas.html.twig', [
            'salas' => array_map(TarjetaDeSala::datos(...), $this->servicio->publicadas()),
        ]);
    }
}
