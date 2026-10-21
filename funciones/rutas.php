<?php

declare(strict_types=1);

use IndieCinema\Funciones\Controladores\Reservas;
use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\Rol;

/*
 * Las rutas de funciones, sin el prefijo que saca nginx. Cada una declara su rol mínimo; el
 * middleware del núcleo lo controla antes de llegar al controlador.
 *
 * /funcion/ sola responde la página de error del sitio con un 404.
 */
return static function (Router $router): void {
    // La reserva de una función (E4-01): el formulario está en la ficha, que es de programación;
    // esta página es la misma con los errores. Que la reserva vista sea del espectador lo
    // controla el servicio.
    $router->get('/reservar/{id}', [Reservas::class, 'formulario'], Rol::Espectador);
    $router->post('/reservar/{id}', [Reservas::class, 'reservar'], Rol::Espectador);
    $router->get('/reservas/{id}', [Reservas::class, 'confirmada'], Rol::Espectador);

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'programacion']);
};
