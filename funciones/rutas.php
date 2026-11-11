<?php

declare(strict_types=1);

use IndieCinema\Funciones\Controladores\AcuerdosInternos;
use IndieCinema\Funciones\Controladores\AvisosDePago;
use IndieCinema\Funciones\Controladores\CobrosInternos;
use IndieCinema\Funciones\Controladores\OcupacionInterna;
use IndieCinema\Funciones\Controladores\Reservas;
use IndieCinema\Funciones\Controladores\ReservasDeLaFuncion;
use IndieCinema\Funciones\Controladores\ReservasDelAdministrador;
use IndieCinema\Funciones\Controladores\ReservasInternas;
use IndieCinema\Funciones\Servicios\PagosDeReservas;
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
    // Adónde vuelve el espectador desde Mercado Pago (X-01.3): sólo muestra en qué quedó.
    $router->get('/reservas/{id}/regreso', [Reservas::class, 'regreso'], Rol::Espectador);
    // El aviso de Mercado Pago cuando se acredita un pago (X-01.4): lo único que confirma una
    // reserva paga. Sin sesión ni CSRF: lo autentica su firma, en el controlador.
    $router->webhook(PagosDeReservas::RUTA_DEL_AVISO, [AvisosDePago::class, 'recibir']);
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
    // Programación finalizó una función (EF-04.1): las confirmadas sin asistencia quedan ausentes.
    $router->interna('POST', '/interno/funciones/{id}/finalizada', [ReservasInternas::class, 'finalizada'], ['programacion']);

    // Los lugares ocupados, para que programación muestre los libres (E4-02): de una función en
    // su ficha, y de una página entera de tarjetas en un solo pedido.
    $router->interna('GET', '/interno/funciones/{id}/ocupacion', [OcupacionInterna::class, 'deUna'], ['programacion']);
    $router->interna('GET', '/interno/ocupacion', [OcupacionInterna::class, 'deVarias'], ['programacion']);

    // Lo cobrado de cada función, para el reparto de recaudación (EF-02.5).
    $router->interna('GET', '/interno/funciones/{id}/cobros', [CobrosInternos::class, 'deUna'], ['recaudacion']);
    $router->interna('GET', '/interno/cobros', [CobrosInternos::class, 'deVarias'], ['recaudacion']);

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'programacion', 'moderacion', 'recaudacion']);

    // API interna de acuerdos de fecha (E3-13 / IC-154, IC-155, IC-156, IC-157, IC-158).
    $router->interna('POST', '/interno/acuerdos', [AcuerdosInternos::class, 'proponer'], ['programacion']);
    $router->interna('GET', '/interno/acuerdos/por-funcion/{id}', [AcuerdosInternos::class, 'mostrarPorFuncion'], ['programacion']);
    $router->interna('POST', '/interno/acuerdos/{id}/votar', [AcuerdosInternos::class, 'votar'], ['programacion']);
    $router->interna('POST', '/interno/acuerdos/{id}/quitar-voto', [AcuerdosInternos::class, 'quitarVoto'], ['programacion']);
    $router->interna('POST', '/interno/acuerdos/{id}/confirmar', [AcuerdosInternos::class, 'confirmar'], ['programacion']);
    $router->interna('POST', '/interno/acuerdos/{id}/cerrar-sin-minimo', [AcuerdosInternos::class, 'cerrarSinMinimo'], ['programacion']);
};
