<?php

/*
 * Suspende las salas con el seguro o la habilitación vencidos (EF-07.5). La corre el cron del
 * servidor una vez por día (ver el README, «Tareas programadas»):
 *
 *     docker compose exec -T moderacion php bin/suspender-vencidas.php
 *
 * Correrla de más no repite nada: una sala ya suspendida por el mismo papel no se vuelve a tocar.
 */

declare(strict_types=1);

use IndieCinema\Moderacion\Repositorios\RepositorioDeDocumentos;
use IndieCinema\Moderacion\Repositorios\RepositorioDeSuspensiones;
use IndieCinema\Moderacion\Servicios\ArchivosDeSolicitudes;
use IndieCinema\Moderacion\Servicios\SalasDeProgramacion;
use IndieCinema\Moderacion\Servicios\ServicioDeSuspensiones;
use IndieCinema\Moderacion\Servicios\SuspensionDeVencidas;
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

$log = new Log('moderacion');
try {
    $configuracion = Configuracion::desdeArchivo($raiz . '/config/config.ini');
    $base = BaseDeDatos::conectar($configuracion);
    $salas = new SalasDeProgramacion(ClienteInterno::desdeConfiguracion($configuracion, $log), new ArchivosDeSolicitudes($configuracion));
    $tarea = new SuspensionDeVencidas(
        new RepositorioDeDocumentos($base),
        new ServicioDeSuspensiones(new RepositorioDeSuspensiones($base), $salas, $log),
        $log,
    );
    $suspendidas = $tarea->correr();
} catch (Throwable $error) {
    $log->error('No corrió la suspensión de las salas vencidas: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);
    exit(1);
}

// Sólo si hizo algo: un log por vuelta no dice nada.
if ($suspendidas > 0) {
    $log->info('Salas suspendidas por un documento vencido: {cuantas}.', ['cuantas' => $suspendidas]);
}
