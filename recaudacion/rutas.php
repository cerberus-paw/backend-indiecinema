<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Ruteo\Router;

/*
 * Las rutas de recaudación, sin el prefijo que saca nginx. Cada una declara su rol mínimo; el
 * middleware del núcleo lo controla antes de llegar al controlador.
 *
 * Por ahora sólo está armado (EF-02): el reparto y las liquidaciones (EF-06), la reportería
 * (EF-10) y los beneficios (EF-16) se escriben acá. /recaudacion/ sola responde la página de error
 * del sitio con un 404.
 */
return static function (Router $router): void {
    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'programacion', 'funciones', 'moderacion']);
};
