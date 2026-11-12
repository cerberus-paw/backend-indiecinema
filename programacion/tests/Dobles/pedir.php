<?php

/*
 * Un pedido a programación atendido en su propio proceso, con su propia conexión a la base, como
 * lo atiende otro proceso de Apache: lo lanza FuncionesDelOrganizadorTest para que dos
 * programaciones compitan por la misma sala (EF-05.6).
 *
 *   php pedir.php <configuración (JSON)> <método> <ruta> <cuerpo (JSON)> <cabeceras (JSON)> <csrf>
 *
 * Escribe la respuesta en JSON: estado, Location y cuerpo.
 */

declare(strict_types=1);

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Log;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Corre desde la línea de comandos; $_SERVER['argv'] y no $argv, que PHPStan no ve definida acá.
[, $configuracion, $metodo, $ruta, $cuerpo, $cabeceras, $csrf] = $_SERVER['argv'];

$aplicacion = new Aplicacion(
    dirname(__DIR__, 2),
    new Configuracion((array) json_decode($configuracion, true)),
    new Log('programacion', fopen('php://memory', 'w')),
);
$respuesta = $aplicacion->atender(new Peticion(
    $metodo,
    $ruta,
    [],
    (array) json_decode($cuerpo, true),
    (array) json_decode($cabeceras, true),
    ['csrf' => $csrf],
));

echo json_encode([
    'estado' => $respuesta->estado(),
    'location' => $respuesta->cabecera('Location'),
    'cuerpo' => $respuesta->cuerpo(),
]);
