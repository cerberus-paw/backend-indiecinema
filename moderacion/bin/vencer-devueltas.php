<?php

/*
 * Cierra como vencidas las solicitudes de sala devueltas a las que se les pasó el plazo para
 * completarlas, y le recuerda al solicitante, unos días antes, que se termina (EF-22.10). La corre
 * el cron del servidor una vez por día, después de suspender-vencidas (ver el README, «Tareas
 * programadas»):
 *
 *     docker compose exec -T moderacion php bin/vencer-devueltas.php
 *
 * Correrla de más no repite nada: ni los cierres ni los correos.
 */

declare(strict_types=1);

use IndieCinema\Moderacion\Repositorios\RepositorioDeAvisos;
use IndieCinema\Moderacion\Repositorios\RepositorioDeSolicitudes;
use IndieCinema\Moderacion\Servicios\UsuariosDeCuentas;
use IndieCinema\Moderacion\Servicios\VencimientoDeDevueltas;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
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
    $tarea = new VencimientoDeDevueltas(
        new RepositorioDeSolicitudes($base),
        new RepositorioDeAvisos($base),
        new UsuariosDeCuentas(ClienteInterno::desdeConfiguracion($configuracion, $log)),
        ClienteSmtp::desdeConfiguracion($configuracion, $log),
        $configuracion,
        $log,
    );
    $vencidas = $tarea->correr();
} catch (Throwable $error) {
    $log->error('No corrió el vencimiento de las solicitudes devueltas: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);
    exit(1);
}

// Sólo si hizo algo: un log por vuelta no dice nada.
if ($vencidas > 0) {
    $log->info('Solicitudes devueltas que vencieron: {cuantas}.', ['cuantas' => $vencidas]);
}
