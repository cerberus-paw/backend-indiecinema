<?php

/*
 * Carga las ventas, la asistencia y las calificaciones de las funciones del historial de prueba de
 * programación (EF-09.4), para la demo de los reportes de recaudación:
 *
 *     docker compose exec programacion php herramientas/historial-de-prueba.php
 *     docker compose exec funciones php herramientas/historial-de-prueba.php
 *
 * Primero el de programación, que carga las funciones: éste se las pide por la API interna.
 * Correrlo de nuevo agrega sólo lo que falte. En producción, sólo con --confirmar-produccion.
 */

declare(strict_types=1);

use IndieCinema\Funciones\Servicios\FuncionesDeProgramacion;
use IndieCinema\Funciones\Servicios\HistorialDePrueba;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Log;

// Está fuera del webroot, pero por las dudas: no se ejecuta desde un pedido HTTP.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

const CONFIRMAR_PRODUCCION = '--confirmar-produccion';

$argumentos = array_slice($argv, 1);
foreach (array_diff($argumentos, [CONFIRMAR_PRODUCCION]) as $desconocido) {
    fwrite(STDERR, "No conozco el argumento {$desconocido}: el único es " . CONFIRMAR_PRODUCCION . '.' . PHP_EOL);
    exit(1);
}

try {
    $configuracion = Configuracion::desdeArchivo($raiz . '/config/config.ini');
    $historial = HistorialDePrueba::para(
        $configuracion,
        BaseDeDatos::conectar($configuracion),
        new FuncionesDeProgramacion(ClienteInterno::desdeConfiguracion($configuracion, new Log('funciones'))),
        in_array(CONFIRMAR_PRODUCCION, $argumentos, true),
    );
    $cantidades = $historial->cargar();
    printf("  funciones       %4d agregadas, %2d ya estaban\n", $cantidades['agregadas'], $cantidades['yaEstaban']);
    printf("  reservas        %4d agregadas\n", $cantidades['reservas']);
    printf("  calificaciones  %4d agregadas\n", $cantidades['calificaciones']);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
