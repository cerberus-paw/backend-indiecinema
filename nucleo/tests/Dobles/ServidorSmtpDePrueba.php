<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Dobles;

use RuntimeException;

/**
 * Arranca servidor-smtp.php en un puerto libre y devuelve los correos que recibió. Lo usan las
 * pruebas del núcleo y las de los subsistemas que mandan correos (cuentas lo carga en su
 * autoload-dev).
 */
final class ServidorSmtpDePrueba
{
    /** @var resource */
    private $proceso;

    private function __construct(public readonly int $puerto, private readonly string $archivo)
    {
    }

    public static function iniciar(): self
    {
        // Un puerto libre: el sistema da uno al pedir el 0, y se suelta para que lo use el servidor.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $servidor = new self($puerto, (string) tempnam(sys_get_temp_dir(), 'smtp'));
        $proceso = proc_open(
            [PHP_BINARY, __DIR__ . '/servidor-smtp.php', (string) $puerto, $servidor->archivo],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
        );
        if ($proceso === false) {
            throw new RuntimeException('No arrancó el servidor SMTP de prueba.');
        }
        $servidor->proceso = $proceso;

        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return $servidor;
            }
            usleep(20_000);
        }
        $servidor->detener();

        throw new RuntimeException('No arrancó el servidor SMTP de prueba.');
    }

    public function url(): string
    {
        return "smtp://127.0.0.1:{$this->puerto}";
    }

    /**
     * Los correos recibidos desde el último vaciar(), en orden.
     *
     * @return list<array{de: string, para: list<string>, mensaje: string}>
     */
    public function correos(): array
    {
        $lineas = file($this->archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(static fn (string $linea): array => json_decode($linea, true, 512, JSON_THROW_ON_ERROR), $lineas);
    }

    public function vaciar(): void
    {
        file_put_contents($this->archivo, '');
    }

    public function detener(): void
    {
        proc_terminate($this->proceso);
        proc_close($this->proceso);
        @unlink($this->archivo);
    }
}
