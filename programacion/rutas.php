<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Programacion\Controladores\AcercaDe;
use IndieCinema\Programacion\Controladores\AdministracionDeSalas;
use IndieCinema\Programacion\Controladores\Cartelera;
use IndieCinema\Programacion\Controladores\FuncionesInternas;
use IndieCinema\Programacion\Controladores\ImagenDeSala;
use IndieCinema\Programacion\Controladores\Inicio;
use IndieCinema\Programacion\Controladores\Salas;
use IndieCinema\Programacion\Controladores\SalasDelOrganizador;

/*
 * Las rutas de programación, sin el prefijo que saca nginx. Cada una declara su rol mínimo; el
 * middleware del núcleo lo controla antes de llegar al controlador.
 */
return static function (Router $router): void {
    $router->get('/', [Inicio::class, 'mostrar']);
    // Quiénes somos y cómo funciona: el encabezado y el pie enlazan a sus secciones.
    $router->get('/acerca-de', [AcercaDe::class, 'mostrar']);
    // La cartelera pública, sin sesión. Los filtros y la página van en la URL.
    $router->get('/cartelera', [Cartelera::class, 'listar']);
    // La misma, con los mismos filtros, para abrir en una planilla.
    $router->get('/cartelera.csv', [Cartelera::class, 'descargar']);
    // La ficha de una función programada con sus obras y su sala.
    $router->get('/cartelera/{id}', [Cartelera::class, 'detalle']);

    // «Mis salas», alta y edición de la sala propia. Que la sala sea del que la edita lo
    // controla el servicio.
    $router->get('/organizador/salas', [SalasDelOrganizador::class, 'listar'], Rol::Organizador);
    $router->get('/organizador/salas/nueva', [SalasDelOrganizador::class, 'nueva'], Rol::Organizador);
    $router->post('/organizador/salas/nueva', [SalasDelOrganizador::class, 'crear'], Rol::Organizador);
    $router->get('/organizador/salas/{id}/editar', [SalasDelOrganizador::class, 'editar'], Rol::Organizador);
    $router->post('/organizador/salas/{id}/editar', [SalasDelOrganizador::class, 'actualizar'], Rol::Organizador);
    // Todas las salas para el administrador, que las publica o las retira del sitio.
    $router->get('/administracion/salas', [AdministracionDeSalas::class, 'listar'], Rol::Administrador);
    $router->post('/administracion/salas/{id}/habilitar', [AdministracionDeSalas::class, 'habilitar'], Rol::Administrador);
    $router->post('/administracion/salas/{id}/deshabilitar', [AdministracionDeSalas::class, 'deshabilitar'], Rol::Administrador);
    // El listado público: sólo las habilitadas, sin sesión.
    $router->get('/salas', [Salas::class, 'listar']);
    // La ficha de una, con sus próximas funciones. Una sin habilitar sólo la ve su organizador.
    $router->get('/salas/{id}', [Salas::class, 'mostrar']);
    // Pública si la sala está habilitada; si no, sólo para su organizador (lo decide el servicio).
    $router->get('/salas/{id}/imagen', [ImagenDeSala::class, 'mostrar']);

    // Una función para el subsistema funciones (acuerdo de fecha y reservas): sólo la pide él.
    $router->interna('GET', '/interno/funciones/{id}', [FuncionesInternas::class, 'mostrar'], ['funciones']);

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'funciones']);
};
