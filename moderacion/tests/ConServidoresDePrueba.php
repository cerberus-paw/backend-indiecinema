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

    /** El JSON con las obras que conoce programación (EF-07.7). */
    private static string $obras;

    /** El JSON con las funciones que conoce programación (EF-07.8). */
    private static string $funciones;

    private static function iniciarServidores(): void
    {
        self::$salas = (string) tempnam(sys_get_temp_dir(), 'salas');
        self::$obras = (string) tempnam(sys_get_temp_dir(), 'obras');
        self::$funciones = (string) tempnam(sys_get_temp_dir(), 'funciones');
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
                ['REGISTRO' => self::$registros[$servidor], 'CAIDA' => self::$caidas[$servidor], 'SALAS' => self::$salas, 'OBRAS' => self::$obras, 'FUNCIONES' => self::$funciones] + getenv(),
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
        array_map(static fn (string $archivo) => @unlink($archivo), [...self::$registros, ...self::$caidas, self::$salas, self::$obras, self::$funciones]);
    }

    /**
     * Sin pedidos anotados, de pie, y programación sin salas ni obras.
     */
    private static function reiniciarServidores(): void
    {
        foreach (self::$registros as $servidor => $registro) {
            file_put_contents($registro, '');
            @unlink(self::$caidas[$servidor]);
        }
        file_put_contents(self::$salas, '{}');
        file_put_contents(self::$obras, '{}');
        file_put_contents(self::$funciones, '{}');
    }

    /**
     * Las funciones que programación ya tiene, como las devuelve GET /interno/funciones/{id}
     * (EF-07.8).
     *
     * @param array<string, array<string, mixed>> $funciones id => datos
     */
    private static function funcionesEnProgramacion(array $funciones): void
    {
        $completas = [];
        foreach ($funciones as $id => $funcion) {
            $completas[$id] = ['id' => $id] + $funcion;
        }
        file_put_contents(self::$funciones, json_encode((object) $completas));
    }

    /**
     * Las obras que programación ya tiene, como las devuelve su API interna (EF-07.7): lo que no se
     * pase toma el valor de una pendiente cualquiera.
     *
     * @param array<string, array<string, mixed>> $obras id => datos
     */
    private static function obrasEnProgramacion(array $obras): void
    {
        $completas = [];
        foreach ($obras as $id => $obra) {
            $completas[$id] = ['id' => $id] + $obra + [
                'titulo' => 'Una obra',
                'tipo' => 'pelicula',
                'anio' => 2020,
                'director' => 'Rocío Paz',
                'duracion' => 95,
                'sinopsis' => 'Un pueblo y su río.',
                'generos' => ['Drama'],
                'origen_copia' => 'obra_propia',
                'afiche' => true,
                'dueno_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'estado' => 'pendiente',
                'motivo' => null,
                'creada_en' => '2026-10-01T10:00:00',
            ];
        }
        file_put_contents(self::$obras, json_encode((object) $completas));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function obrasDeProgramacion(): array
    {
        return (array) json_decode((string) file_get_contents(self::$obras), true);
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
