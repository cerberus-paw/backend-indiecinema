<?php

/*
 * Manda el recordatorio por correo a cada reserva confirmada de una función que empieza en las
 * próximas 24 horas (EF-03.3). La corre el cron del servidor cada hora (ver el README, «Tareas
 * programadas»):
 *
 *     docker compose exec -T funciones php bin/recordar-funciones.php
 *
 * Correrla de más no repite correos: cada reserva queda marcada al recibir el suyo.
 */

declare(strict_types=1);

use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Funciones\Servicios\AvisosDeReservas;
use IndieCinema\Funciones\Servicios\FuncionesDeProgramacion;
use IndieCinema\Funciones\Servicios\RecordatoriosDeFunciones;
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

$log = new Log('funciones');
try {
    $configuracion = Configuracion::desdeArchivo($raiz . '/config/config.ini');
    $cliente = ClienteInterno::desdeConfiguracion($configuracion, $log);
    $recordatorios = new RecordatoriosDeFunciones(
        new FuncionesDeProgramacion($cliente),
        new RepositorioDeReservas(BaseDeDatos::conectar($configuracion)),
        new AvisosDeReservas($cliente, ClienteSmtp::desdeConfiguracion($configuracion, $log), $log, $configuracion),
    );
    $hechos = $recordatorios->recordar();
} catch (Throwable $error) {
    $log->error('No salieron los recordatorios de las funciones: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);
    exit(1);
}

// Sólo si hizo algo: el cron corre seguido y un log por vuelta no dice nada.
if ($hechos['enviados'] + $hechos['fallidos'] > 0) {
    $log->info('Recordatorios: {enviados} enviados y {fallidos} para reintentar.', $hechos);
}
