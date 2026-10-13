<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Programacion\Controladores\AcercaDe;
use IndieCinema\Programacion\Controladores\AdministracionDeSolicitudes;
use IndieCinema\Programacion\Controladores\Cartelera;
use IndieCinema\Programacion\Controladores\FuncionesDelOrganizador;
use IndieCinema\Programacion\Controladores\FuncionesInternas;
use IndieCinema\Programacion\Controladores\ImagenDeSala;
use IndieCinema\Programacion\Controladores\Inicio;
use IndieCinema\Programacion\Controladores\Salas;
use IndieCinema\Programacion\Controladores\SalasDelOrganizador;
use IndieCinema\Programacion\Controladores\SolicitudDeSala;

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

    // La solicitud para abrir una sala (E2): la manda el espectador que quiere ser organizador y
    // el organizador que suma otra sala. Aprobarla habilita la sala y da el rol.
    $router->get('/solicitar-sala', [SolicitudDeSala::class, 'mostrar'], Rol::Espectador);
    $router->post('/solicitar-sala', [SolicitudDeSala::class, 'enviar'], Rol::Espectador);

    // «Mis salas» y la edición de la sala propia; las nuevas entran por la solicitud. Que la sala
    // sea del que la edita lo controla el servicio.
    $router->get('/organizador/salas', [SalasDelOrganizador::class, 'listar'], Rol::Organizador);
    $router->get('/organizador/salas/{id}/editar', [SalasDelOrganizador::class, 'editar'], Rol::Organizador);
    $router->post('/organizador/salas/{id}/editar', [SalasDelOrganizador::class, 'actualizar'], Rol::Organizador);
    // «Mis funciones»: las de las salas propias, en cualquier estado. Que sean las suyas lo
    // controla el servicio, con el id del que pide.
    $router->get('/organizador/funciones', [FuncionesDelOrganizador::class, 'listar'], Rol::Organizador);
    $router->get('/organizador/funciones/nueva', [FuncionesDelOrganizador::class, 'nueva'], Rol::Organizador);
    $router->post('/organizador/funciones/nueva', [FuncionesDelOrganizador::class, 'crear'], Rol::Organizador);
    // La bandeja de solicitudes de sala del administrador: aprobar una habilita la sala y le da el
    // rol de organizador a quien la mandó. Desde una aprobada, la sala se retira o se vuelve a
    // publicar.
    $router->get('/administracion/solicitudes', [AdministracionDeSolicitudes::class, 'listar'], Rol::Administrador);
    $router->post('/administracion/solicitudes/{id}/resolver', [AdministracionDeSolicitudes::class, 'resolver'], Rol::Administrador);
    $router->post('/administracion/solicitudes/{id}/deshabilitar-sala', [AdministracionDeSolicitudes::class, 'deshabilitarSala'], Rol::Administrador);
    $router->post('/administracion/solicitudes/{id}/habilitar-sala', [AdministracionDeSolicitudes::class, 'habilitarSala'], Rol::Administrador);
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
