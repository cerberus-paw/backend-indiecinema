<?php

/*
 * Un espectador que reserva desde su propio proceso, con su propia conexión a la base: lo lanza
 * ServicioDeReservasTest varias veces a la vez para que compitan por el último lugar, como dos
 * pedidos que atienden dos procesos de Apache.
 *
 *   php reservar.php <url de programación> <función> <espectador> <cuándo arrancar (microtime)>
 *
 * Escribe «confirmada» o el mensaje de la regla que no cumplió.
 */

declare(strict_types=1);

use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Funciones\Servicios\FuncionesDeProgramacion;
use IndieCinema\Funciones\Servicios\ServicioDeReservas;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[, $url, $funcionId, $espectadorId, $arranque] = $argv;

$base = BaseDeDatos::conectar(new Configuracion(['base' => [
    'host' => getenv('PRUEBAS_MYSQL_HOST'),
    'puerto' => (int) (getenv('PRUEBAS_MYSQL_PUERTO') ?: 3306),
    'esquema' => getenv('PRUEBAS_MYSQL_ESQUEMA') ?: 'pruebas',
    'usuario' => getenv('PRUEBAS_MYSQL_USUARIO') ?: 'root',
    'clave' => (string) getenv('PRUEBAS_MYSQL_CLAVE'),
]]));
$servicio = new ServicioDeReservas(
    new FuncionesDeProgramacion(new ClienteInterno('funciones', 'token-de-funciones', ['programacion' => $url], new Log('funciones', fopen('php://memory', 'w')), 2000)),
    new RepositorioDeReservas($base),
);

// Todos arrancan juntos, ya conectados: así compiten de verdad.
$espera = (float) $arranque - microtime(true);
if ($espera > 0) {
    usleep((int) ($espera * 1_000_000));
}

try {
    $servicio->reservar(new Usuario($espectadorId, Rol::Espectador), $funcionId, 1, new DateTimeImmutable('2026-10-20 12:00:00'));
    echo 'confirmada';
} catch (ReglaIncumplida $error) {
    echo $error->getMessage();
}
