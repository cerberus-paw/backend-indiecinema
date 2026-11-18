<?php

/*
 * Cancela las reservas que vencieron sin pagar (X-01.5). La corre el cron del servidor cada cinco
 * minutos (ver el README, «Mercado Pago»):
 *
 *     docker compose exec -T funciones php bin/liberar-vencidas.php
 *
 * Los lugares ya quedaron libres al vencer; esto deja el estado al día en las pantallas. Correrla
 * de más no hace nada: sólo toca las pendientes que ya vencieron.
 */

declare(strict_types=1);

use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Funciones\Servicios\LiberacionDeVencidas;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
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
    $canceladas = (new LiberacionDeVencidas(new RepositorioDeReservas(BaseDeDatos::conectar($configuracion))))->liberar();
} catch (Throwable $error) {
    $log->error('No se liberaron las reservas vencidas: {mensaje}', ['mensaje' => $error->getMessage(), 'exception' => $error]);
    exit(1);
}

// Sólo si hizo algo: el cron corre seguido y un log por vuelta no dice nada.
if ($canceladas > 0) {
    $log->info('Reservas vencidas canceladas: {cuantas}.', ['cuantas' => $canceladas]);
}
