<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Dobles;

/**
 * Archivos de imagenes/ como si los hubiera subido el navegador: una copia en un temporal, porque
 * guardarla la mueve.
 */
final class Imagenes
{
    /** @var list<string> */
    private static array $temporales = [];

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    public static function subida(string $archivo, string $nombreQueMandaElNavegador = 'foto.jpg'): array
    {
        $temporal = (string) tempnam(sys_get_temp_dir(), 'subida');
        copy(self::ruta($archivo), $temporal);
        if (self::$temporales === []) {
            // Los que no se movieron al guardar.
            register_shutdown_function(static fn () => array_map(static fn (string $ruta) => @unlink($ruta), self::$temporales));
        }
        self::$temporales[] = $temporal;

        // El tipo que dice el navegador siempre es «image/jpeg»: lo elige quien manda el archivo, y
        // la validación no lo tiene que mirar.
        return ['name' => $nombreQueMandaElNavegador, 'type' => 'image/jpeg', 'tmp_name' => $temporal, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($temporal)];
    }

    public static function ruta(string $archivo): string
    {
        return __DIR__ . "/imagenes/{$archivo}";
    }
}
