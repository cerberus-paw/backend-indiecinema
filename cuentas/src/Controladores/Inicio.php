<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;

final class Inicio extends Controlador
{
    /**
     * Por ahora, la página de prueba del esqueleto (IC-16): muestra que el subsistema arranca con
     * el núcleo, la plantilla base del front y la sesión. La reemplaza la pantalla real.
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        return $this->vista('esqueleto.html.twig', ['subsistema' => 'cuentas']);
    }
}
