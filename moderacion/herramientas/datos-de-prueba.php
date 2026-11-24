<?php

/*
 * Carga las denuncias de prueba (EF-22.11) en la base de moderación, para mostrar las reglas: dos
 * medias tomadas sobre el Alambique, a una del aviso, y una alta abierta sobre una de sus
 * funciones. Va después de los datos de prueba de cuentas y de programación, y pide la sala y la
 * función a programación, que tiene que estar andando:
 *
 *     docker compose exec moderacion php herramientas/datos-de-prueba.php
 *
 * En producción (entorno distinto de "desarrollo" en config.ini) no hace nada sin la confirmación
 * explícita:
 *
 *     docker compose exec moderacion php herramientas/datos-de-prueba.php --confirmar-produccion
 *
 * Correrlo de nuevo agrega sólo las que falten.
 */

declare(strict_types=1);

use IndieCinema\Moderacion\Repositorios\RepositorioDeDenuncias;
use IndieCinema\Moderacion\Servicios\ArchivosDeSolicitudes;
use IndieCinema\Moderacion\Servicios\DatosDePrueba;
use IndieCinema\Moderacion\Servicios\FuncionesDeProgramacion;
use IndieCinema\Moderacion\Servicios\SalasDeProgramacion;
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

// Un argumento mal escrito no tiene que pasar por la confirmación ni quedar ignorado sin aviso.
$argumentos = array_slice($argv, 1);
foreach (array_diff($argumentos, [CONFIRMAR_PRODUCCION]) as $desconocido) {
    fwrite(STDERR, "No conozco el argumento {$desconocido}: el único es " . CONFIRMAR_PRODUCCION . '.' . PHP_EOL);
    exit(1);
}

try {
    $configuracion = Configuracion::desdeArchivo($raiz . '/config/config.ini');
    $cliente = ClienteInterno::desdeConfiguracion($configuracion, new Log('moderacion'));
    $datos = DatosDePrueba::para(
        $configuracion,
        new RepositorioDeDenuncias(BaseDeDatos::conectar($configuracion)),
        new SalasDeProgramacion($cliente, new ArchivosDeSolicitudes($configuracion)),
        new FuncionesDeProgramacion($cliente),
        in_array(CONFIRMAR_PRODUCCION, $argumentos, true),
    );
    if (!$configuracion->enDesarrollo()) {
        echo 'Cargando las denuncias de prueba en producción, con la confirmación.', PHP_EOL;
    }
    foreach ($datos->cargar() as $id => $agregada) {
        echo ($agregada ? '  agregada  ' : '  ya estaba '), $id, PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
