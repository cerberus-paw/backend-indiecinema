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

    // Para comprobar que los otros subsistemas llegan hasta acá con su token.
    $router->interna('GET', '/interno/estado', [EstadoInterno::class, 'mostrar'], ['cuentas', 'programacion', 'funciones', 'moderacion']);
};
