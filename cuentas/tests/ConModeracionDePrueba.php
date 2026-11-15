<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

/**
 * Una moderación de mentira (Dobles/moderacion.php) con `php -S` en un puerto libre: cuentas la
 * llama por la red, como en el compose (EF-07.6). Responde las solicitudes que le carga la prueba,
 * y 500 a todo si la prueba la tira.
 */
trait ConModeracionDePrueba
{
    /** @var ?resource */
    private static $moderacion = null;

    private static string $urlDeModeracion;

    /** El JSON con la última solicitud de cada usuario. */
    private static string $solicitudes;

    /** Si existe, moderación responde 500. */
    private static string $caidaDeModeracion;

    private static function iniciarModeracion(): void
    {
        self::$solicitudes = (string) tempnam(sys_get_temp_dir(), 'solicitudes');
        self::$caidaDeModeracion = sys_get_temp_dir() . '/caida-moderacion-' . bin2hex(random_bytes(4));
        // Un puerto libre: el sistema da uno al pedir el 0, y se suelta para que lo use php -S.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$urlDeModeracion = "http://127.0.0.1:{$puerto}";

        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . '/Dobles/moderacion.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
            null,
            ['SOLICITUDES' => self::$solicitudes, 'CAIDA' => self::$caidaDeModeracion] + getenv(),
        );
        self::assertNotFalse($proceso);
        self::$moderacion = $proceso;
        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return;
            }
            usleep(20_000);
        }
        self::fail("No arrancó la moderación de prueba en el puerto {$puerto}.");
    }

    private static function detenerModeracion(): void
    {
        if (self::$moderacion !== null) {
            proc_terminate(self::$moderacion);
            proc_close(self::$moderacion);
            self::$moderacion = null;
        }
        @unlink(self::$solicitudes);
        @unlink(self::$caidaDeModeracion);
    }

    /**
     * De pie y sin solicitudes.
     */
    private static function reiniciarModeracion(): void
    {
        file_put_contents(self::$solicitudes, '{}');
        @unlink(self::$caidaDeModeracion);
    }

    /**
     * La última solicitud de cada usuario, como la devuelve la API interna de moderación.
     *
     * @param array<string, array{estado: string, sala: string, motivo: ?string, fecha: string}> $solicitudes
     */
    private static function solicitudesEnModeracion(array $solicitudes): void
    {
        file_put_contents(self::$solicitudes, json_encode((object) $solicitudes));
    }

    private static function tirarModeracion(): void
    {
        touch(self::$caidaDeModeracion);
    }
}
