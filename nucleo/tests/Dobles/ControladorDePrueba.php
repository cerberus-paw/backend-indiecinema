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
            // Los que llegaron como subidos (Peticion deja pasar sólo ésos), con su contenido.
            'archivos' => array_map(static fn (array $archivo): array => [
                'nombre' => $archivo['name'],
                'sha1' => sha1_file($archivo['tmp_name']),
            ], array_filter(['imagen' => $peticion->archivo('imagen')])),
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
