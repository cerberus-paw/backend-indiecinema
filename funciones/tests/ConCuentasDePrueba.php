<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

/**
 * Para las pruebas en las que funciones le pide a cuentas la dirección de un espectador (los
 * avisos por correo): levanta un cuentas de mentira (Dobles/cuentas.php) con `php -S` en un
 * puerto libre. Se llama desde el setUpBeforeClass() de la prueba, que ya usa el de
 * ConProgramacionDePrueba.
 */
trait ConCuentasDePrueba
{
    /** @var resource|null */
    private static $cuentas = null;

    private static string $urlCuentas;

    private static function iniciarCuentas(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$urlCuentas = "http://127.0.0.1:{$puerto}";
        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . '/Dobles/cuentas.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
        );
        self::$cuentas = $proceso === false ? null : $proceso;
        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return;
            }
            usleep(20_000);
        }
        self::fail('No arrancó el cuentas de prueba.');
    }

    private static function detenerCuentas(): void
    {
        if (self::$cuentas !== null) {
            proc_terminate(self::$cuentas);
            proc_close(self::$cuentas);
            self::$cuentas = null;
        }
    }
}
