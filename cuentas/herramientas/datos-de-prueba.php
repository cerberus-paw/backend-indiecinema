<?php

/*
 * Carga los usuarios de prueba (un administrador, un organizador y dos espectadores) en la base
 * de cuentas, con la conexión y el usuario del subsistema, como cualquier registro:
 *
 *     docker compose exec cuentas php herramientas/datos-de-prueba.php
 *
 * En desarrollo corre así, con las contraseñas del README. En producción (entorno distinto de
 * "desarrollo" en config.ini) no hace nada sin la confirmación explícita, y entonces carga sólo el
 * administrador, el organizador y un espectador, con las contraseñas de [datos_de_prueba]:
 *
 *     docker compose exec cuentas php herramientas/datos-de-prueba.php --confirmar-produccion
 *
 * Correrlo de nuevo agrega sólo los que falten.
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

const CONFIRMAR_PRODUCCION = '--confirmar-produccion';

// Un argumento mal escrito no tiene que pasar por la confirmación ni quedar ignorado sin aviso.
$argumentos = array_slice($argv, 1);
foreach (array_diff($argumentos, [CONFIRMAR_PRODUCCION]) as $desconocido) {
    fwrite(STDERR, "No conozco el argumento {$desconocido}: el único es " . CONFIRMAR_PRODUCCION . '.' . PHP_EOL);
    exit(1);
}

try {
    $configuracion = Configuracion::desdeArchivo($raiz . '/config/config.ini');
    $datos = DatosDePrueba::para(
        $configuracion,
        new RepositorioDeUsuarios(BaseDeDatos::conectar($configuracion)),
        in_array(CONFIRMAR_PRODUCCION, $argumentos, true),
    );
    if (!$configuracion->enDesarrollo()) {
        echo 'Cargando los usuarios de prueba en producción, con la confirmación.', PHP_EOL;
    }
    foreach ($datos->cargar() as $correo => $agregado) {
        echo ($agregado ? '  agregado  ' : '  ya estaba '), $correo, PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
