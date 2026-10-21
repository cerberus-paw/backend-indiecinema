<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Funciones\Servicios\FuncionesDeProgramacion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Log;

/**
 * Para las pruebas que le piden funciones a programación: levanta una de mentira
 * (Dobles/programacion.php) con `php -S` en un puerto libre, y el cliente le pide por HTTP de
 * verdad, con el ClienteInterno del núcleo y el token de funciones, como en el compose.
 */
trait ConProgramacionDePrueba
{
    /** @var resource|null */
    private static $servidor = null;

    private static string $url;

    public static function setUpBeforeClass(): void
    {
        // Un puerto libre: el sistema da uno al pedir el 0, y se suelta para que lo use php -S.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$url = "http://127.0.0.1:{$puerto}";

        // Con varios procesos, como Apache: las reservas que compiten piden la función a la vez.
        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . '/Dobles/programacion.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
            null,
            [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '4'],
        );
        self::$servidor = $proceso === false ? null : $proceso;

        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return;
            }
            usleep(20_000);
        }
        self::fail('No arrancó la programación de prueba.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$servidor !== null) {
            proc_terminate(self::$servidor);
            proc_close(self::$servidor);
        }
    }

    private static function funcionesDeProgramacion(string $token = 'token-de-funciones', string $url = ''): FuncionesDeProgramacion
    {
        return new FuncionesDeProgramacion(new ClienteInterno(
            'funciones',
            $token,
            ['programacion' => $url === '' ? self::$url : $url],
            new Log('funciones', fopen('php://memory', 'w')),
            500,
        ));
    }
}
