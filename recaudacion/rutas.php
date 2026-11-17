<?php

declare(strict_types=1);

use IndieCinema\Nucleo\Http\EstadoInterno;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Recaudacion\Controladores\Reportes;

/*
 * Las rutas de recaudación, sin el prefijo que saca nginx. Cada una declara su rol mínimo; el
 * middleware del núcleo lo controla antes de llegar al controlador.
 *
 * El reparto y las liquidaciones (EF-08) y los beneficios (EF-17) se escriben acá. /recaudacion/
 * sola responde la página de error del sitio con un 404.
 */
return static function (Router $router): void {
    // Lo que pasó en las salas en un período y qué conviene hacer (EF-09): el organizador ve las
    // suyas y el administrador, todas.
    $router->get('/reportes', [Reportes::class, 'mostrar'], Rol::Organizador);
    // Los totales de cada mes y las funciones de uno (EF-09.2), y todas en CSV.
    $router->get('/reportes/funciones', [Reportes::class, 'funciones'], Rol::Organizador);
    $router->get('/reportes/funciones.csv', [Reportes::class, 'descargar'], Rol::Organizador);
    // Para el dueño, cómo le fue a cada obra suya en cualquier sala (EF-09.3).
    $router->get('/reportes/obras', [Reportes::class, 'obras'], Rol::Organizador);

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'programacion', 'funciones', 'moderacion']);
};
