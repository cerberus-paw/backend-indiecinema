<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;

/**
 * GET /acerca-de: «Acerca de IndieCinema», quiénes somos y cómo funciona el sitio, para cualquiera,
 * con o sin sesión. Es estática: el texto está en la plantilla.
 */
final class AcercaDe extends Controlador
{
    public function mostrar(Peticion $peticion): Respuesta
    {
        return $this->vista('acerca_de.html.twig');
    }
}
