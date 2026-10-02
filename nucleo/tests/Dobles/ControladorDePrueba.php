<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Dobles;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use RuntimeException;

final class ControladorDePrueba extends Controlador
{
    public function __construct(private readonly ServicioDePrueba $servicio)
    {
    }

    public function hola(Peticion $peticion): Respuesta
    {
        return Respuesta::html($this->servicio->saludo());
    }

    public function sala(Peticion $peticion): Respuesta
    {
        return $this->json(['id' => $peticion->parametro('id')]);
    }

    public function plantilla(Peticion $peticion): Respuesta
    {
        return $this->vista('error.html.twig', [
            'estado' => 200,
            'mensaje' => '<script>alert(1)</script>',
            'detalle' => null,
        ]);
    }

    public function redirige(Peticion $peticion): Respuesta
    {
        return $this->redirigir('/destino');
    }

    public function falla(Peticion $peticion): Respuesta
    {
        throw new RuntimeException('detalle interno que no tiene que ver el usuario');
    }
}
