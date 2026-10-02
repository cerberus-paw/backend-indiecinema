<?php

/*
 * El subsistema de prueba atendiendo pedidos HTTP de verdad, con `php -S`, para que
 * ClienteInternoTest lo llame por la red como un subsistema llama a otro.
 */

declare(strict_types=1);

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Log;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$aplicacion = new Aplicacion(__DIR__ . '/subsistema', new Configuracion([
    'app' => ['subsistema' => 'prueba', 'entorno' => 'produccion'],
    'nginx' => ['secreto' => 'secreto-de-nginx'],
    'llamadores' => ['programacion' => hash('sha256', 'token-de-programacion')],
]), new Log('prueba', fopen('php://stderr', 'w')));

$aplicacion->atender(Peticion::desdeGlobales())->enviar();
