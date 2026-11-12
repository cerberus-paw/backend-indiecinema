<?php

/*
 * Cierra las votaciones de títulos que pasaron su fecha de cierre, con la obra más votada, como
 * si las cerrara el organizador (EF-03.1). La corre el cron del servidor cada quince minutos
 * (ver el README, «Tareas programadas»):
 *
 *     docker compose exec -T programacion php bin/cerrar-por-fecha.php
 *
 * Correrla de más no hace nada: sólo toca las que vencieron y siguen abiertas.
 */

declare(strict_types=1);

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Log;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;
use IndieCinema\Programacion\Servicios\CierresPorFecha;

// Está fuera del webroot, pero por las dudas: no se ejecuta desde un pedido HTTP.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

$log = new Log('programacion');
try {
    $base = BaseDeDatos::conectar(Configuracion::desdeArchivo($raiz . '/config/config.ini'));
    $cierres = new CierresPorFecha(new RepositorioDeFunciones($base), new RepositorioDeVotos($base));
    $votaciones = $cierres->cerrarVotaciones();
} catch (Throwable $error) {
    $log->error('No se cerraron las votaciones vencidas: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);
    exit(1);
}

// Sólo si hizo algo: el cron corre seguido y un log por vuelta no dice nada.
if ($votaciones > 0) {
    $log->info('Votaciones cerradas por fecha: {cuantas}.', ['cuantas' => $votaciones]);
}
