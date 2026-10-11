<?php

/*
 * Carga los datos de demostración de programación (los géneros, cuatro salas habilitadas, doce
 * obras con afiche y quince funciones futuras) con la conexión y el usuario del subsistema, por
 * los mismos repositorios que usa la web, y copia las fotos de las salas y los afiches al volumen
 * de archivos:
 *
 *     docker compose exec programacion php herramientas/datos-de-prueba.php
 *
 * Sólo con entorno = "desarrollo" en config.ini. Correrlo de nuevo agrega sólo lo que falte. Las
 * salas y las obras son del organizador de prueba de cuentas, así que conviene cargar antes ésos.
 */

declare(strict_types=1);

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Servicios\DatosDePrueba;

// Está fuera del webroot, pero por las dudas: no se ejecuta desde un pedido HTTP.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

try {
    $configuracion = Configuracion::desdeArchivo($raiz . '/config/config.ini');
    $base = BaseDeDatos::conectar($configuracion);
    $datos = DatosDePrueba::para(
        $configuracion,
        new RepositorioDeGeneros($base),
        new RepositorioDeSalas($base),
        new RepositorioDeObras($base),
        new RepositorioDeFunciones($base),
    );
    foreach ($datos->cargar() as $tipo => $cantidades) {
        printf("  %-10s %2d agregados, %2d ya estaban\n", $tipo, $cantidades['agregadas'], $cantidades['yaEstaban']);
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
