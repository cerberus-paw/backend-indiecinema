<?php

declare(strict_types=1);

use IndieCinema\Funciones\Controladores\OcupacionInterna;
use IndieCinema\Funciones\Controladores\Reservas;
use IndieCinema\Funciones\Controladores\ReservasDeLaFuncion;
use IndieCinema\Funciones\Controladores\ReservasDelAdministrador;
use IndieCinema\Funciones\Controladores\ReservasInternas;
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
    // «Mis reservas» (E4-04.1): las del espectador, con el enlace a cada comprobante.
    $router->get('/reservas', [Reservas::class, 'mias'], Rol::Espectador);
    $router->get('/reservas/{id}', [Reservas::class, 'confirmada'], Rol::Espectador);
    // La cancelación del espectador, con una pantalla que la confirma antes (E4-01.5).
    $router->get('/reservas/{id}/cancelar', [Reservas::class, 'confirmarCancelacion'], Rol::Espectador);
    $router->post('/reservas/{id}/cancelar', [Reservas::class, 'cancelar'], Rol::Espectador);

    // El seguimiento de las reservas (E4-03). El organizador, las de sus funciones: que la función
    // sea suya lo controla el servicio, con el id del que pide.
    $router->get('/organizador/funciones/{id}/reservas', [ReservasDeLaFuncion::class, 'listar'], Rol::Organizador);
    $router->post('/organizador/funciones/{id}/asistencia', [ReservasDeLaFuncion::class, 'registrarAsistencia'], Rol::Organizador);
    $router->get('/organizador/funciones/{id}/reservas.csv', [ReservasDeLaFuncion::class, 'descargar'], Rol::Organizador);
    // El administrador, todas, y anula la que haga falta.
    $router->get('/administracion/reservas', [ReservasDelAdministrador::class, 'listar'], Rol::Administrador);
    $router->post('/administracion/reservas/{id}/anular', [ReservasDelAdministrador::class, 'anular'], Rol::Administrador);
    // Programación canceló una función: se cancelan sus reservas y se avisa a los espectadores.
    $router->interna('POST', '/interno/funciones/{id}/cancelar-reservas', [ReservasInternas::class, 'cancelarDeLaFuncion'], ['programacion']);

    // Los lugares ocupados, para que programación muestre los libres (E4-02): de una función en
    // su ficha, y de una página entera de tarjetas en un solo pedido.
    $router->interna('GET', '/interno/funciones/{id}/ocupacion', [OcupacionInterna::class, 'deUna'], ['programacion']);
    $router->interna('GET', '/interno/ocupacion', [OcupacionInterna::class, 'deVarias'], ['programacion']);

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'programacion']);
};
