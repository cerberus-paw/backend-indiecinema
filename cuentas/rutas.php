<?php

declare(strict_types=1);

use IndieCinema\Cuentas\Controladores\Ingreso;
use IndieCinema\Cuentas\Controladores\Inicio;
use IndieCinema\Cuentas\Controladores\Registro;
use IndieCinema\Cuentas\Controladores\SesionInterna;
use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Ruteo\Router;

/*
 * Las rutas de cuentas, sin el prefijo que saca nginx. Cada una declara su rol mínimo; el
 * middleware del núcleo lo controla antes de llegar al controlador.
 */
return static function (Router $router): void {
    $router->get('/', [Inicio::class, 'mostrar']);

    // Sin sesión: es para el visitante que todavía no tiene cuenta.
    $router->get('/registro', [Registro::class, 'mostrar']);
    $router->post('/registro', [Registro::class, 'registrar']);
    $router->get('/ingresar', [Ingreso::class, 'mostrar']);
    $router->post('/ingresar', [Ingreso::class, 'ingresar']);
    // Sin rol mínimo: trabaja con la cookie, y sin sesión válida igual la borra.
    $router->post('/salir', [Ingreso::class, 'salir']);

    // El auth_request de nginx, en cada pedido del sitio: quién es el de la cookie.
    $router->interna('GET', '/interno/sesion', [SesionInterna::class, 'validar'], ['nginx']);

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['programacion', 'funciones']);
};
