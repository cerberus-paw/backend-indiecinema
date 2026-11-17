<?php

/*
 * Reparte lo cobrado de cada función paga que terminó, entre la sala, los dueños de las obras que
 * se proyectaron y la plataforma (EF-08.2). La corre el cron del servidor una vez por hora (ver el
 * README, «Tareas programadas»):
 *
 *     docker compose exec -T recaudacion php bin/repartir.php
 *
 * Correrla de más no duplica nada: una función ya repartida se saltea. Si programación o funciones
 * no responden, no reparte nada, sale con error y la próxima pasada lo vuelve a intentar.
 */

declare(strict_types=1);

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Log;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeParametrosDeReparto;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeRepartos;
use IndieCinema\Recaudacion\Servicios\CobrosDeFunciones;
use IndieCinema\Recaudacion\Servicios\FuncionesFinalizadas;
use IndieCinema\Recaudacion\Servicios\RepartoDeFunciones;
use IndieCinema\Recaudacion\Servicios\ServicioDeParametrosDeReparto;

// Está fuera del webroot, pero por las dudas: no se ejecuta desde un pedido HTTP.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

$log = new Log('recaudacion');
try {
    $configuracion = Configuracion::desdeArchivo($raiz . '/config/config.ini');
    $base = BaseDeDatos::conectar($configuracion);
    $cliente = ClienteInterno::desdeConfiguracion($configuracion, $log);
    $tarea = new RepartoDeFunciones(
        new FuncionesFinalizadas($cliente),
        new CobrosDeFunciones($cliente),
        new ServicioDeParametrosDeReparto(new RepositorioDeParametrosDeReparto($base)),
        new RepositorioDeRepartos($base),
    );
    $hecho = $tarea->repartir();
} catch (Throwable $error) {
    $log->error('No se repartió lo cobrado de las funciones finalizadas: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);
    exit(1);
}

// Sólo si hizo algo: el cron corre cada hora y un log por vuelta no dice nada.
if ($hecho['repartidas'] > 0 || $hecho['sinPlata'] > 0) {
    $log->info('Funciones repartidas: {repartidas}, y sin nada que repartir: {sinPlata}.', [
        'repartidas' => $hecho['repartidas'],
        'sinPlata' => $hecho['sinPlata'],
    ]);
}
// Programación cortó la lista: quedaron más, que entran en la próxima pasada.
if ($hecho['quedanMas']) {
    $log->info('Quedaron funciones finalizadas sin repartir para la próxima pasada.');
}
