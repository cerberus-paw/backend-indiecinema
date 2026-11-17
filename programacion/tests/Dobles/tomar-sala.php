<?php

/*
 * Otra conexión que programa una función en la sala y la tiene tomada un rato antes de guardarla,
 * como un organizador que programa justo cuando corre la tarea: lo lanza CierresPorFechaTest
 * para ver que la tarea espera a que se suelte la sala (EF-05.6).
 *
 *   php tomar-sala.php <sala> <obra elegida> <cuándo empieza la función> <milisegundos tomada>
 *
 * Escribe «tomada» en cuanto bloquea la sala y la función ya está agregada, sin confirmar.
 */

declare(strict_types=1);

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Corre desde la línea de comandos; $_SERVER['argv'] y no $argv, que PHPStan no ve definida acá.
[, $salaId, $obraId, $cuando, $milisegundos] = $_SERVER['argv'];

$base = BaseDeDatos::conectar(new Configuracion(['base' => [
    'host' => getenv('PRUEBAS_MYSQL_HOST'),
    'puerto' => (int) (getenv('PRUEBAS_MYSQL_PUERTO') ?: 3306),
    'esquema' => getenv('PRUEBAS_MYSQL_ESQUEMA') ?: 'pruebas',
    'usuario' => getenv('PRUEBAS_MYSQL_USUARIO') ?: 'root',
    'clave' => (string) getenv('PRUEBAS_MYSQL_CLAVE'),
]]));
$base->enTransaccion(static function (BaseDeDatos $base) use ($salaId, $obraId, $cuando, $milisegundos): void {
    $base->valor('SELECT id FROM sala WHERE id = ? FOR UPDATE', [$salaId]);
    $funciones = new RepositorioDeFunciones($base);
    $funcion = Funcion::nueva($salaId, [$obraId], 40, 3500.0, new DateTimeImmutable('+10 days'));
    $funciones->agregar($funcion);
    $funcion->cerrarVotacionTitulos([$obraId]);
    $funcion->fijarFechaHora(new DateTimeImmutable($cuando));
    $funciones->guardar($funcion);

    echo "tomada\n";
    fflush(STDOUT);
    usleep((int) $milisegundos * 1000);
});
