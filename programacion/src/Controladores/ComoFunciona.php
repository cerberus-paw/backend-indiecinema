<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;

/**
 * GET /como-funciona: «Cómo funciona IndieCinema», el circuito paso a paso de una función y lo
 * que hace cada rol, para cualquiera, con o sin sesión. Es estática: el texto está en la plantilla.
 */
final class ComoFunciona extends Controlador
{
    public function mostrar(Peticion $peticion): Respuesta
    {
        return $this->vista('como_funciona.html.twig');
    }
}
