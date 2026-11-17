<?php

/*
 * Los cierres por fecha límite (EF-03): las votaciones de títulos que pasaron su fecha de cierre
 * pasan al acuerdo con la obra más votada (EF-03.1), y los acuerdos de fecha vencidos programan la
 * función en la franja que llegó al mínimo o la cancelan avisando a los que marcaron «Me sirve»
 * (EF-03.2). La corre el cron del servidor cada quince minutos (ver el README, «Tareas
 * programadas»):
 *
 *     docker compose exec -T programacion php bin/cerrar-por-fecha.php
 *
 * Correrla de más no hace nada: sólo toca lo que venció y sigue abierto. Si funciones no responde,
 * las votaciones se cierran igual y los acuerdos quedan para la próxima pasada.
 */

declare(strict_types=1);

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Log;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;
use IndieCinema\Programacion\Servicios\AcuerdosDeFunciones;
use IndieCinema\Programacion\Servicios\CierresPorFecha;

// Está fuera del webroot, pero por las dudas: no se ejecuta desde un pedido HTTP.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$raiz = dirname(__DIR__);
require $raiz . '/vendor/autoload.php';

$log = new Log('programacion');
try {
    $configuracion = Configuracion::desdeArchivo($raiz . '/config/config.ini');
    $base = BaseDeDatos::conectar($configuracion);
    $cierres = new CierresPorFecha(
        new RepositorioDeFunciones($base),
        new RepositorioDeVotos($base),
        new RepositorioDeSalas($base),
        new RepositorioDeObras($base),
        new AcuerdosDeFunciones(ClienteInterno::desdeConfiguracion($configuracion, $log)),
        $log,
    );
} catch (Throwable $error) {
    $log->error('No arrancó la tarea de los cierres por fecha: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);
    exit(1);
}

$fallo = false;
// Cada paso por su lado: si funciones no responde, igual se cierran las votaciones, que no lo
// necesitan.
try {
    $votaciones = $cierres->cerrarVotaciones();
    // Sólo si hizo algo: el cron corre seguido y un log por vuelta no dice nada.
    if ($votaciones > 0) {
        $log->info('Votaciones cerradas por fecha: {cuantas}.', ['cuantas' => $votaciones]);
    }
} catch (Throwable $error) {
    $log->error('No se cerraron las votaciones vencidas: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);
    $fallo = true;
}
try {
    $acuerdos = $cierres->cerrarAcuerdos();
    if ($acuerdos['programadas'] + $acuerdos['canceladas'] > 0) {
        $log->info('Acuerdos de fecha vencidos: {programadas} funciones programadas y {canceladas} canceladas.', $acuerdos);
    }
} catch (Throwable $error) {
    $log->error('No se cerraron los acuerdos de fecha vencidos: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);
    $fallo = true;
}

exit($fallo ? 1 : 0);
