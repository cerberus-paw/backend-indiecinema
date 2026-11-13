<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

/**
 * Cuentas y programación de mentira (Dobles/cuentas.php y Dobles/programacion.php), cada una con
 * `php -S` en un puerto libre: moderación las llama por la red, como en el compose (EF-07.2).
 * Anotan lo que se les pide en un archivo por servidor, y responden 500 a todo si la prueba crea
 * su archivo de caída.
 */
trait ConServidoresDePrueba
{
    /** @var array<string, resource> */
    private static array $procesos = [];

    /** @var array<string, string> servidor => URL */
    private static array $urls = [];

    /** @var array<string, string> servidor => archivo donde anota lo que le piden */
    private static array $registros = [];

    /** @var array<string, string> servidor => archivo que, si existe, lo hace fallar */
    private static array $caidas = [];

    /** El JSON con las salas que conoce programación. */
    private static string $salas;

    private static function iniciarServidores(): void
    {
        self::$salas = (string) tempnam(sys_get_temp_dir(), 'salas');
        foreach (['cuentas', 'programacion'] as $servidor) {
            self::$registros[$servidor] = (string) tempnam(sys_get_temp_dir(), "registro-{$servidor}");
            self::$caidas[$servidor] = sys_get_temp_dir() . "/caida-{$servidor}-" . bin2hex(random_bytes(4));
            // Un puerto libre: el sistema da uno al pedir el 0, y se suelta para que lo use php -S.
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
            fclose($socket);
            self::$urls[$servidor] = "http://127.0.0.1:{$puerto}";

            $proceso = proc_open(
                [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . "/Dobles/{$servidor}.php"],
                [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
                $tuberias,
                null,
                ['REGISTRO' => self::$registros[$servidor], 'CAIDA' => self::$caidas[$servidor], 'SALAS' => self::$salas] + getenv(),
            );
            self::assertNotFalse($proceso);
            self::$procesos[$servidor] = $proceso;
            self::esperarA($puerto);
        }
    }

    private static function detenerServidores(): void
    {
        foreach (self::$procesos as $proceso) {
            proc_terminate($proceso);
            proc_close($proceso);
        }
        self::$procesos = [];
        array_map(static fn (string $archivo) => @unlink($archivo), [...self::$registros, ...self::$caidas, self::$salas]);
    }

    /**
     * Sin pedidos anotados, de pie, y programación sin salas.
     */
    private static function reiniciarServidores(): void
    {
        foreach (self::$registros as $servidor => $registro) {
            file_put_contents($registro, '');
            @unlink(self::$caidas[$servidor]);
        }
        file_put_contents(self::$salas, '{}');
    }

    private static function tirar(string $servidor): void
    {
        touch(self::$caidas[$servidor]);
    }

    /**
     * Las salas que programación ya tiene, como las devuelve su API interna.
     *
     * @param array<string, array{nombre: string, localidad: string, organizador_id: string, habilitada: bool}> $salas
     */
    private static function salasEnProgramacion(array $salas): void
    {
        file_put_contents(self::$salas, json_encode((object) $salas));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function salasDeProgramacion(): array
    {
        return (array) json_decode((string) file_get_contents(self::$salas), true);
    }

    /**
     * @return list<array<string, mixed>> lo que se le pidió a ese servidor, en orden
     */
    private static function pedidosA(string $servidor): array
    {
        return array_map(
            static fn (string $linea): array => (array) json_decode($linea, true),
            array_values(array_filter(explode("\n", (string) file_get_contents(self::$registros[$servidor])))),
        );
    }

    private static function esperarA(int $puerto): void
    {
        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return;
            }
            usleep(20_000);
        }
        self::fail("No arrancó el servidor de prueba en el puerto {$puerto}.");
    }
}
