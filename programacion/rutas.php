<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Programacion\Controladores\AcercaDe;
use IndieCinema\Programacion\Controladores\AficheDeObra;
use IndieCinema\Programacion\Controladores\Cartelera;
use IndieCinema\Programacion\Controladores\FuncionesDelOrganizador;
use IndieCinema\Programacion\Controladores\FuncionesInternas;
use IndieCinema\Programacion\Controladores\ImagenDeSala;
use IndieCinema\Programacion\Controladores\Inicio;
use IndieCinema\Programacion\Controladores\Obras;
use IndieCinema\Programacion\Controladores\ObrasDelOrganizador;
use IndieCinema\Programacion\Controladores\Salas;
use IndieCinema\Programacion\Controladores\SalasInternas;
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
    // Sólo los lugares libres, en JSON: la ficha los consulta cada pocos segundos (E4-05.1).
    $router->get('/cartelera/{id}/lugares', [Cartelera::class, 'lugares']);
    $router->post('/cartelera/{id}/votar', [Cartelera::class, 'votar'], Rol::Espectador);
    $router->post('/cartelera/{id}/votar-franja', [Cartelera::class, 'votar'], Rol::Espectador);
    $router->post('/cartelera/{id}/acuerdo/votar', [Cartelera::class, 'votar'], Rol::Espectador);

    // «Mis salas» y la edición de la sala propia; las nuevas entran por la solicitud. Que la sala
    // sea del que la edita lo controla el servicio.
    $router->get('/organizador/salas', [SalasDelOrganizador::class, 'listar'], Rol::Organizador);
    $router->get('/organizador/salas/{id}/editar', [SalasDelOrganizador::class, 'editar'], Rol::Organizador);
    $router->post('/organizador/salas/{id}/editar', [SalasDelOrganizador::class, 'actualizar'], Rol::Organizador);

    // «Mis obras», alta y edición de obras del catálogo.
    $router->get('/organizador/obras', [ObrasDelOrganizador::class, 'listar'], Rol::Organizador);
    $router->get('/organizador/obras/nueva', [ObrasDelOrganizador::class, 'nueva'], Rol::Organizador);
    $router->post('/organizador/obras/nueva', [ObrasDelOrganizador::class, 'crear'], Rol::Organizador);
    $router->get('/organizador/obras/{id}/editar', [ObrasDelOrganizador::class, 'editar'], Rol::Organizador);
    $router->post('/organizador/obras/{id}/editar', [ObrasDelOrganizador::class, 'actualizar'], Rol::Organizador);
    $router->post('/organizador/obras/{id}/baja', [ObrasDelOrganizador::class, 'darDeBaja'], Rol::Organizador);

    // «Mis funciones»: las de las salas propias, en cualquier estado. Que sean las suyas lo
    // controla el servicio, con el id del que pide.
    $router->get('/organizador/funciones', [FuncionesDelOrganizador::class, 'listar'], Rol::Organizador);
    $router->get('/organizador/funciones/nueva', [FuncionesDelOrganizador::class, 'nueva'], Rol::Organizador);
    $router->post('/organizador/funciones/nueva', [FuncionesDelOrganizador::class, 'crear'], Rol::Organizador);
    $router->get('/organizador/funciones/{id}/editar', [FuncionesDelOrganizador::class, 'editar'], Rol::Organizador);
    $router->post('/organizador/funciones/{id}/editar', [FuncionesDelOrganizador::class, 'actualizar'], Rol::Organizador);
    $router->get('/organizador/funciones/{id}/cancelar', [FuncionesDelOrganizador::class, 'confirmarCancelacion'], Rol::Organizador);
    $router->post('/organizador/funciones/{id}/cancelar', [FuncionesDelOrganizador::class, 'cancelar'], Rol::Organizador);
    $router->post('/organizador/funciones/{id}/cerrar-votacion', [FuncionesDelOrganizador::class, 'cerrarVotacion'], Rol::Organizador);
    // El organizador propone franjas y mínimo para el acuerdo de fecha (E3-13.2 / IC-154).
    $router->post('/organizador/funciones/{id}/acuerdo', [FuncionesDelOrganizador::class, 'proponerAcuerdo'], Rol::Organizador);
    $router->post('/organizador/funciones/{id}/proponer-acuerdo', [FuncionesDelOrganizador::class, 'proponerAcuerdo'], Rol::Organizador);
    // El organizador confirma la franja ganadora cuando alcanza el mínimo (E3-13.4 / IC-156).
    $router->post('/organizador/funciones/{id}/confirmar-acuerdo', [FuncionesDelOrganizador::class, 'confirmarAcuerdo'], Rol::Organizador);
    // El organizador cierra el acuerdo sin alcanzar el mínimo de asistentes (E3-13.5 / IC-157).
    $router->post('/organizador/funciones/{id}/cerrar-sin-minimo', [FuncionesDelOrganizador::class, 'cerrarSinMinimo'], Rol::Organizador);
    $router->post('/organizador/funciones/{id}/proponer-franjas', [FuncionesDelOrganizador::class, 'proponerAcuerdo'], Rol::Organizador);
    $router->get('/organizador/funciones/{id}/proponer-franjas', [FuncionesDelOrganizador::class, 'mostrarProponerFranjas'], Rol::Organizador);
    $router->get('/organizador/funciones/{id}/franjas', [FuncionesDelOrganizador::class, 'mostrarProponerFranjas'], Rol::Organizador);
    $router->post('/organizador/funciones/{id}/franjas', [FuncionesDelOrganizador::class, 'proponerAcuerdo'], Rol::Organizador);
    // El listado público: sólo las habilitadas, sin sesión.
    $router->get('/salas', [Salas::class, 'listar']);
    // La ficha de una, con sus próximas funciones. Una sin habilitar sólo la ve su organizador.
    $router->get('/salas/{id}', [Salas::class, 'mostrar']);
    // Pública si la sala está habilitada; si no, sólo para su organizador (lo decide el servicio).
    $router->get('/salas/{id}/imagen', [ImagenDeSala::class, 'mostrar']);
    // El listado público de obras: sólo las que no se dieron de baja, sin sesión.
    $router->get('/obras', [Obras::class, 'listar']);
    // La ficha pública de una obra con sus datos y sus próximas funciones en cartelera (E4-08.3).
    $router->get('/obras/{id}', [Obras::class, 'mostrar']);
    // El afiche de una obra, para las tarjetas de sus funciones: público como el catálogo.
    $router->get('/obras/{id}/afiche', [AficheDeObra::class, 'mostrar']);

    // Las programadas que empiezan en un rango, para el recordatorio del día antes (EF-03.3). Antes
    // que la de a una, para que «proximas» no se tome como un id.
    $router->interna('GET', '/interno/funciones/proximas', [FuncionesInternas::class, 'proximas'], ['funciones']);
    // Una función para el subsistema funciones (acuerdo de fecha y reservas): sólo la pide él.
    $router->interna('GET', '/interno/funciones/{id}', [FuncionesInternas::class, 'mostrar'], ['funciones']);
    // Las de una página de «Mis reservas» en un solo pedido (E4-04.1).
    $router->interna('GET', '/interno/funciones', [FuncionesInternas::class, 'varias'], ['funciones']);

    // Las salas de la bandeja de moderación (EF-07.1), y la de una solicitud: la habilita o la
    // suspende.
    $router->interna('GET', '/interno/salas', [SalasInternas::class, 'varias'], ['moderacion']);
    $router->interna('POST', '/interno/salas', [SalasInternas::class, 'crear'], ['moderacion']);
    $router->interna('POST', '/interno/salas/{id}/habilitacion', [SalasInternas::class, 'habilitacion'], ['moderacion']);

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'funciones', 'moderacion', 'recaudacion']);
};
