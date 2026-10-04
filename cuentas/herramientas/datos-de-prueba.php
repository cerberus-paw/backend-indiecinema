<?php

/*
 * Carga los usuarios de prueba (un administrador, un organizador y dos espectadores) en la base
 * de cuentas, con la conexión y el usuario del subsistema, como cualquier registro:
 *
 *     docker compose exec cuentas php herramientas/datos-de-prueba.php
 *
 * Sólo con entorno = "desarrollo" en config.ini. Correrlo de nuevo agrega sólo los que falten.
 * Los correos y las contraseñas están en el README.
 */

declare(strict_types=1);

use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Cuentas\Servicios\DatosDePrueba;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;

// Está fuera del webroot, pero por las dudas: no se ejecuta desde un pedido HTTP.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

try {
    $configuracion = Configuracion::desdeArchivo($raiz . '/config/config.ini');
    $datos = DatosDePrueba::para($configuracion, new RepositorioDeUsuarios(BaseDeDatos::conectar($configuracion)));
    foreach ($datos->cargar() as $correo => $agregado) {
        echo ($agregado ? '  agregado  ' : '  ya estaba '), $correo, PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
