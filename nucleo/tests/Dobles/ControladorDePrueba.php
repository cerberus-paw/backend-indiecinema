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

    public function esqueleto(Peticion $peticion): Respuesta
    {
        return $this->vista('esqueleto.html.twig', ['subsistema' => 'prueba']);
    }

    public function redirige(Peticion $peticion): Respuesta
    {
        return $this->redirigir('/destino');
    }

    /**
     * Devuelve lo que recibió, para ver qué le llega a una ruta interna.
     */
    public function eco(Peticion $peticion): Respuesta
    {
        return $this->json([
            'llamador' => $peticion->llamador(),
            'q' => $peticion->consulta('q'),
            'cuerpo' => $peticion->cuerpo(),
        ]);
    }

    public function lento(Peticion $peticion): Respuesta
    {
        usleep(600_000);

        return $this->json(['tarde' => true]);
    }

    public function falla(Peticion $peticion): Respuesta
    {
        throw new RuntimeException('detalle interno que no tiene que ver el usuario');
    }
}
