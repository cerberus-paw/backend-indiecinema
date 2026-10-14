<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Programacion\Servicios\ServicioDeCartelera;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;

/**
 * GET /: la portada, para cualquiera, con o sin sesión. Las próximas funciones de la cartelera,
 * algunas salas y un buscador que lleva a la cartelera filtrada.
 *
 * El administrador no usa el sitio público (su encabezado no tiene la cartelera ni las salas):
 * va a su panel. Como el ingreso lleva a la portada, así entra directo ahí.
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
        if ($peticion->usuario()?->alcanza(Rol::Administrador)) {
            return Respuesta::redireccion('/cuenta/administracion/usuarios');
        }

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
