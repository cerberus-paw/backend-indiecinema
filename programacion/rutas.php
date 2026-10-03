<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Programacion\Controladores\ImagenDeSala;
use IndieCinema\Programacion\Controladores\Inicio;
use IndieCinema\Programacion\Controladores\SalasDelOrganizador;

/*
 * Las rutas de programación, sin el prefijo que saca nginx. Cada una declara su rol mínimo; el
 * middleware del núcleo lo controla antes de llegar al controlador.
 */
return static function (Router $router): void {
    $router->get('/', [Inicio::class, 'mostrar']);

    // «Mis salas», alta y edición de la sala propia. Que la sala sea del que la edita lo
    // controla el servicio.
    $router->get('/organizador/salas', [SalasDelOrganizador::class, 'listar'], Rol::Organizador);
    $router->get('/organizador/salas/nueva', [SalasDelOrganizador::class, 'nueva'], Rol::Organizador);
    $router->post('/organizador/salas/nueva', [SalasDelOrganizador::class, 'crear'], Rol::Organizador);
    $router->get('/organizador/salas/{id}/editar', [SalasDelOrganizador::class, 'editar'], Rol::Organizador);
    $router->post('/organizador/salas/{id}/editar', [SalasDelOrganizador::class, 'actualizar'], Rol::Organizador);
    // Pública si la sala está habilitada; si no, sólo para su organizador (lo decide el servicio).
    $router->get('/salas/{id}/imagen', [ImagenDeSala::class, 'mostrar']);

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'funciones']);
};
