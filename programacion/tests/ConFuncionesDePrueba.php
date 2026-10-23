<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Log;
use IndieCinema\Programacion\Servicios\AcuerdosDeFunciones;

trait ConFuncionesDePrueba
{
    /** @var resource|null */
    private static $servidorFunciones = null;

    protected static string $urlFunciones;

    public static function setUpBeforeClassFunciones(): void
    {
        @unlink(sys_get_temp_dir() . '/acuerdo_estado_global.txt');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$urlFunciones = "http://127.0.0.1:{$puerto}";

        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . '/Dobles/funciones.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
            null,
            [...getenv(), 'PHP_CLI_SERVER_WORKERS' => '2'],
        );
        self::$servidorFunciones = $proceso === false ? null : $proceso;

        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return;
            }
            usleep(20_000);
        }
        self::fail('No arrancó el doble de funciones de prueba.');
    }

    public static function tearDownAfterClassFunciones(): void
    {
        @unlink(sys_get_temp_dir() . '/acuerdo_estado_global.txt');
        if (self::$servidorFunciones !== null) {
            proc_terminate(self::$servidorFunciones);
            proc_close(self::$servidorFunciones);
        }
    }

    protected static function acuerdosDeFunciones(string $token = 'token-de-programacion', string $url = ''): AcuerdosDeFunciones
    {
        $cliente = new ClienteInterno(
            'programacion',
            $token,
            ['funciones' => $url !== '' ? $url : self::$urlFunciones],
            new Log('programacion', fopen('php://memory', 'w')),
        );

        return new AcuerdosDeFunciones($cliente);
    }
}
