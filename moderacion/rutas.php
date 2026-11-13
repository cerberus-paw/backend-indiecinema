<?php

declare(strict_types=1);

use IndieCinema\Moderacion\Controladores\AdministracionDeSolicitudes;
use IndieCinema\Moderacion\Controladores\FotoDeSolicitud;
use IndieCinema\Moderacion\Controladores\SolicitudDeSala;
use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\Rol;

/*
 * Las rutas de moderación, sin el prefijo que saca nginx. Cada una declara su rol mínimo; el
 * middleware del núcleo lo controla antes de llegar al controlador.
 *
 * Las solicitudes de sala se mudaron de programación (EF-07.2); la documentación de las salas, el
 * resto de EF-07 y los términos y condiciones (EF-16) se escriben acá. /moderacion/ sola responde
 * la página de error del sitio con un 404.
 */
return static function (Router $router): void {
    // La solicitud para abrir una sala (E2): la manda el espectador que quiere ser organizador y
    // el organizador que suma otra sala. La dirección es la de siempre, sin el prefijo: nginx la
    // trae acá tal cual.
    $router->get('/solicitar-sala', [SolicitudDeSala::class, 'mostrar'], Rol::Espectador);
    $router->post('/solicitar-sala', [SolicitudDeSala::class, 'enviar'], Rol::Espectador);
    // La foto que mandó, para quien la mandó y el administrador (lo decide el servicio).
    $router->get('/solicitudes/{id}/foto', [FotoDeSolicitud::class, 'mostrar'], Rol::Espectador);
    // La bandeja de solicitudes de sala: aprobar una crea la sala en programación y le da el rol
    // de organizador a quien la mandó. La resuelve el administrador hasta que haya moderador
    // (EF-07.3).
    $router->get('/solicitudes', [AdministracionDeSolicitudes::class, 'listar'], Rol::Administrador);
    $router->post('/solicitudes/{id}/resolver', [AdministracionDeSolicitudes::class, 'resolver'], Rol::Administrador);

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'programacion', 'funciones', 'recaudacion']);
};
