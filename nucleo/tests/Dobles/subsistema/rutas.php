<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Pruebas\Dobles\ControladorDePrueba;
use IndieCinema\Nucleo\Ruteo\Router;

return static function (Router $router): void {
    $router->get('/', [ControladorDePrueba::class, 'hola']);
    $router->get('/salas/{id}', [ControladorDePrueba::class, 'sala']);
    $router->get('/plantilla', [ControladorDePrueba::class, 'plantilla']);
    $router->get('/redirige', [ControladorDePrueba::class, 'redirige']);
    $router->get('/falla', [ControladorDePrueba::class, 'falla']);
};
