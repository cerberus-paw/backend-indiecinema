<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Programacion\Controladores\Inicio;

/*
 * Las rutas de programación, sin el prefijo que saca nginx. Cada una declara su rol mínimo; el
 * middleware del núcleo lo controla antes de llegar al controlador.
 */
return static function (Router $router): void {
    $router->get('/', [Inicio::class, 'mostrar']);

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'funciones']);
};
