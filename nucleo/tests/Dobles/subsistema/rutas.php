<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Pruebas\Dobles\ControladorDePrueba;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\Rol;

return static function (Router $router): void {
    $router->get('/', [ControladorDePrueba::class, 'hola']);
    $router->get('/salas/{id}', [ControladorDePrueba::class, 'sala']);
    $router->get('/plantilla', [ControladorDePrueba::class, 'plantilla']);
    $router->get('/redirige', [ControladorDePrueba::class, 'redirige']);
    $router->get('/falla', [ControladorDePrueba::class, 'falla']);
    $router->get('/organizador', [ControladorDePrueba::class, 'hola'], Rol::Organizador);
    $router->post('/formulario', [ControladorDePrueba::class, 'hola']);
    $router->interna('POST', '/interno/prueba', [ControladorDePrueba::class, 'hola'], ['programacion']);
};
