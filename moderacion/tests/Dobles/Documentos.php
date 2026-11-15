<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas\Dobles;

/**
 * PDF de documentos/ como si los hubiera subido el navegador, igual que Imagenes: una copia en un
 * temporal, porque guardarlo lo mueve. poliza.pdf y habilitacion.pdf son PDF chicos de verdad;
 * programa.pdf es un ejecutable con la extensión cambiada.
 */
final class Documentos
{
    /** @var list<string> */
    private static array $temporales = [];

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    public static function subida(string $archivo): array
    {
        return self::temporal((string) file_get_contents(self::ruta($archivo)), $archivo);
    }

    /**
     * Un PDF de más de 5 MB que PHP dejó pasar (el límite del pedido entero es mayor): empieza
     * como un PDF, así que sólo lo frena el tamaño.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    public static function grande(): array
    {
        return self::temporal("%PDF-1.4\n" . str_repeat('0', 5 * 1024 * 1024), 'grande.pdf');
    }

    /**
     * Uno que PHP cortó por pasarse de upload_max_filesize: no llega el archivo, sólo el error.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    public static function cortadoPorPhp(): array
    {
        return ['name' => 'enorme.pdf', 'type' => 'application/pdf', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0];
    }

    public static function ruta(string $archivo): string
    {
        return __DIR__ . "/documentos/{$archivo}";
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private static function temporal(string $contenido, string $nombre): array
    {
        $temporal = (string) tempnam(sys_get_temp_dir(), 'subida');
        file_put_contents($temporal, $contenido);
        if (self::$temporales === []) {
            register_shutdown_function(static fn () => array_map(static fn (string $ruta) => @unlink($ruta), self::$temporales));
        }
        self::$temporales[] = $temporal;

        // El tipo que dice el navegador es siempre el de un PDF: lo elige quien manda el archivo.
        return ['name' => $nombre, 'type' => 'application/pdf', 'tmp_name' => $temporal, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($temporal)];
    }
}
