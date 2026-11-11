<?php

/*
 * Pasa a finalizada cada función programada que ya terminó, y le pide a funciones que deje
 * ausentes las reservas sin asistencia (EF-04.1). La corre el cron del servidor cada quince
 * minutos (ver el README, «Tareas programadas»):
 *
 *     docker compose exec -T programacion php bin/finalizar-funciones.php
 *
 * Correrla de más no hace nada: sólo toca las programadas que ya terminaron.
 */

declare(strict_types=1);

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Log;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Servicios\FinalizacionDeFunciones;
use IndieCinema\Programacion\Servicios\ReservasDeFunciones;

// Está fuera del webroot, pero por las dudas: no se ejecuta desde un pedido HTTP.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

$log = new Log('programacion');
try {
    $configuracion = Configuracion::desdeArchivo($raiz . '/config/config.ini');
    $finalizacion = new FinalizacionDeFunciones(
        new RepositorioDeFunciones(BaseDeDatos::conectar($configuracion)),
        new ReservasDeFunciones($configuracion, $log),
    );
    $finalizadas = $finalizacion->finalizar();
} catch (Throwable $error) {
    $log->error('No se finalizaron las funciones terminadas: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);
    exit(1);
}

// Sólo si hizo algo: el cron corre seguido y un log por vuelta no dice nada.
if ($finalizadas > 0) {
    $log->info('Funciones finalizadas: {cuantas}.', ['cuantas' => $finalizadas]);
}
