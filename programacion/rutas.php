<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Programacion\Controladores\Inicio;

/*
 * Las rutas de programación, sin el prefijo que saca nginx. Cada una declara su rol mínimo; el
 * middleware del núcleo lo controla antes de llegar al controlador.
 */
return static function (Router $router): void {
    $router->get('/', [Inicio::class, 'mostrar']);
};
