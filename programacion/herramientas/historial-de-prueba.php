<?php

/*
 * Carga dos meses de funciones que ya pasaron, con sus votaciones, en las salas de los datos de
 * prueba (EF-09.4), para que los reportes de recaudación tengan qué mostrar en la demo:
 *
 *     docker compose exec programacion php herramientas/historial-de-prueba.php
 *     docker compose exec funciones php herramientas/historial-de-prueba.php
 *
 * Primero éste y después el de funciones, que carga las ventas, la asistencia y las calificaciones
 * de estas mismas funciones. Antes, los datos de prueba (herramientas/datos-de-prueba.php), que
 * cargan las salas y las obras. Correrlo de nuevo agrega sólo lo que falte. En producción, como los
 * datos de prueba, sólo con --confirmar-produccion.
 */

declare(strict_types=1);

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;
use IndieCinema\Programacion\Servicios\HistorialDePrueba;

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
    $base = BaseDeDatos::conectar($configuracion);
    $historial = HistorialDePrueba::para(
        $configuracion,
        new RepositorioDeSalas($base),
        new RepositorioDeFunciones($base),
        new RepositorioDeVotos($base),
        in_array(CONFIRMAR_PRODUCCION, $argumentos, true),
    );
    $cantidades = $historial->cargar();
    printf("  funciones  %2d agregadas, %2d ya estaban\n", $cantidades['agregadas'], $cantidades['yaEstaban']);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
