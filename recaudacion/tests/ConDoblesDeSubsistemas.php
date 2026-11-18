<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas;

/**
 * Levanta los subsistemas de mentira de Dobles/ con `php -S`, cada uno en un puerto libre, para
 * probar lo que recaudación les pide por la red y no con un doble de la clase: así se prueba
 * también el cliente interno, como en el compose.
 */
trait ConDoblesDeSubsistemas
{
    /** @var list<resource> */
    private static array $servidores = [];

    /** Un puerto libre, para un doble o para hacer de subsistema caído. */
    private static function puertoLibre(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $puerto;
    }

    /**
     * @param string $doble el archivo de Dobles/, por ejemplo «programacion.php»
     *
     * @return string la URL donde quedó atendiendo
     */
    private static function levantar(string $doble): string
    {
        $puerto = self::puertoLibre();
        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . "/Dobles/{$doble}"],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
        );
        if ($proceso !== false) {
            self::$servidores[] = $proceso;
        }
        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return "http://127.0.0.1:{$puerto}";
            }
            usleep(20_000);
        }
        self::fail("No arrancó el doble {$doble}.");
    }

    private static function apagarDobles(): void
    {
        foreach (self::$servidores as $servidor) {
            proc_terminate($servidor);
            proc_close($servidor);
        }
        self::$servidores = [];
    }
}
