<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Pruebas\Dobles\ControladorDePrueba;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\Rol;

return static function (Router $router): void {
    $router->get('/', [ControladorDePrueba::class, 'hola']);
    $router->get('/salas/{id}', [ControladorDePrueba::class, 'sala']);
    $router->get('/plantilla', [ControladorDePrueba::class, 'plantilla']);
    $router->get('/redirige', [ControladorDePrueba::class, 'redirige']);
    $router->get('/falla', [ControladorDePrueba::class, 'falla']);
    $router->get('/esqueleto', [ControladorDePrueba::class, 'esqueleto']);
    $router->get('/organizador', [ControladorDePrueba::class, 'hola'], Rol::Organizador);
    $router->post('/formulario', [ControladorDePrueba::class, 'hola']);
    $router->interna('POST', '/interno/prueba', [ControladorDePrueba::class, 'hola'], ['programacion']);
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['programacion', 'funciones']);
    $router->interna('GET', '/interno/eco', [ControladorDePrueba::class, 'eco'], ['programacion']);
    $router->interna('POST', '/interno/eco', [ControladorDePrueba::class, 'eco'], ['programacion']);
    $router->interna('GET', '/interno/lento', [ControladorDePrueba::class, 'lento'], ['programacion']);
    $router->interna('GET', '/interno/falla', [ControladorDePrueba::class, 'falla'], ['programacion']);
};
