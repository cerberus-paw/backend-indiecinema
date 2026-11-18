<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Recaudacion\Controladores\Comercios;
use IndieCinema\Recaudacion\Controladores\Liquidaciones;
use IndieCinema\Recaudacion\Controladores\LiquidacionesDelAdministrador;
use IndieCinema\Recaudacion\Controladores\ParametrosDeReparto;
use IndieCinema\Recaudacion\Controladores\Puntos;
use IndieCinema\Recaudacion\Controladores\Reportes;

/*
 * Las rutas de recaudación, sin el prefijo que saca nginx. Cada una declara su rol mínimo; el
 * middleware del núcleo lo controla antes de llegar al controlador.
 *
 * /recaudacion/ sola responde la página de error del sitio
 * con un 404.
 */
return static function (Router $router): void {
    // Con cuánto se reparte lo cobrado entre la sala, el dueño y la plataforma, y desde cuándo
    // (EF-08.1). Sólo el administrador: es el parámetro con el que se le paga a todos. El reparto
    // de cada función no tiene ruta: lo hace la tarea del cron, en bin/repartir.php (EF-08.2).
    $router->get('/administracion/reparto', [ParametrosDeReparto::class, 'mostrar'], Rol::Administrador);
    $router->post('/administracion/reparto', [ParametrosDeReparto::class, 'guardar'], Rol::Administrador);

    // Lo que pasó en las salas en un período y qué conviene hacer (EF-09): el organizador ve las
    // suyas y el administrador, todas.
    $router->get('/reportes', [Reportes::class, 'mostrar'], Rol::Organizador);
    // Los totales de cada mes y las funciones de uno (EF-09.2), y todas en CSV.
    $router->get('/reportes/funciones', [Reportes::class, 'funciones'], Rol::Organizador);
    $router->get('/reportes/funciones.csv', [Reportes::class, 'descargar'], Rol::Organizador);
    // Para el dueño, cómo le fue a cada obra suya en cualquier sala (EF-09.3).
    $router->get('/reportes/obras', [Reportes::class, 'obras'], Rol::Organizador);

    // Lo que le toca a cada uno por mes (EF-08.3): el organizador por sus salas y el dueño por sus
    // obras ven sólo lo suyo, que se busca por el usuario en sesión.
    $router->get('/liquidaciones', [Liquidaciones::class, 'mias'], Rol::Organizador);
    // Todas las del mes y la marca de transferida, con su nota: sólo el administrador, que es
    // quien hace las transferencias fuera del sitio.
    $router->get('/administracion/liquidaciones', [LiquidacionesDelAdministrador::class, 'mostrar'], Rol::Administrador);
    $router->post('/administracion/liquidaciones/{id}/transferida', [LiquidacionesDelAdministrador::class, 'marcarTransferida'], Rol::Administrador);

    // Los comercios asociados y su beneficio (EF-17.3): la página es pública, para que se vean
    // antes de crear una cuenta; el alta, la edición y la baja son del administrador.
    $router->get('/beneficios', [Comercios::class, 'publicos']);
    $router->get('/administracion/comercios', [Comercios::class, 'listar'], Rol::Administrador);
    $router->post('/administracion/comercios', [Comercios::class, 'crear'], Rol::Administrador);
    $router->get('/administracion/comercios/{id}', [Comercios::class, 'editar'], Rol::Administrador);
    $router->post('/administracion/comercios/{id}', [Comercios::class, 'guardar'], Rol::Administrador);
    $router->post('/administracion/comercios/{id}/activo', [Comercios::class, 'cambiarActivo'], Rol::Administrador);

    // Los puntos del espectador y el canje por un cupón de descuento (EF-17.1). El cupón lo
    // consulta funciones al reservar.
    $router->get('/puntos', [Puntos::class, 'mostrar'], Rol::Espectador);
    $router->post('/puntos/canjear', [Puntos::class, 'canjear'], Rol::Espectador);
    $router->interna('GET', '/interno/cupones/{codigo}', [Puntos::class, 'interno'], ['funciones']);

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'programacion', 'funciones', 'moderacion']);
};
