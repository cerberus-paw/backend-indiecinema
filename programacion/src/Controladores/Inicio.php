<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Programacion\Servicios\ServicioDeCartelera;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;

/**
 * GET /: la portada, para cualquiera, con o sin sesión. Las próximas funciones de la cartelera,
 * algunas salas y un buscador que lleva a la cartelera filtrada.
 */
final class Inicio extends Controlador
{
    /** Una fila de tarjetas en escritorio. */
    private const FUNCIONES = 4;
    private const SALAS = 3;

    public function __construct(
        private readonly ServicioDeCartelera $cartelera,
        private readonly ServicioDeSalas $salas,
    ) {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        $proximas = $this->cartelera->proximas(self::FUNCIONES);
        $salas = $this->salas->publicadas();

        return $this->vista('inicio.html.twig', [
            'funciones' => array_map(TarjetaDeFuncion::datos(...), $proximas->funciones),
            'total_funciones' => $proximas->total,
            'salas' => array_map(TarjetaDeSala::datos(...), array_slice($salas, 0, self::SALAS)),
            'total_salas' => count($salas),
            'localidades' => $this->cartelera->localidades(),
        ]);
    }
}
