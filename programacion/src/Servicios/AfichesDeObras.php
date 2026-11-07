<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Programacion\Modelo\ImagenSubida;
use LogicException;
use RuntimeException;

/**
 * Los afiches de las obras, en la carpeta afiches del volumen de archivos de programación (donde
 * los copian los datos de prueba). Como las fotos de las salas, quedan fuera del webroot y los
 * entrega un controlador.
 */
final class AfichesDeObras
{
    private const TIPOS = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    private const NOMBRE = '/^[0-9a-f]{32}\.(jpg|png|webp)$/D';

    private readonly string $carpeta;

    public function __construct(Configuracion $configuracion)
    {
        $this->carpeta = rtrim((string) $configuracion->obtener('archivos.carpeta', '/var/indiecinema/archivos'), '/') . '/afiches';
    }

    /**
     * @return string el nombre con el que quedó guardado el afiche
     */
    public function guardar(ImagenSubida $imagen): string
    {
        if (!isset(self::TIPOS[$imagen->extension])) {
            throw new LogicException("No se guardan afiches .{$imagen->extension}.");
        }
        if (!is_dir($this->carpeta) && !mkdir($this->carpeta, 0750, true) && !is_dir($this->carpeta)) {
            throw new RuntimeException("No se pudo crear {$this->carpeta}.");
        }

        $nombre = bin2hex(random_bytes(16)) . '.' . $imagen->extension;
        if (!rename($imagen->rutaTemporal, $this->ruta($nombre))) {
            throw new RuntimeException("No se pudo guardar el afiche en {$this->carpeta}.");
        }
        chmod($this->ruta($nombre), 0640);

        return $nombre;
    }

    public function borrar(?string $nombre): void
    {
        if ($nombre === null) {
            return;
        }
        $ruta = $this->ruta($nombre);
        if (is_file($ruta)) {
            unlink($ruta);
        }
    }

    /**
     * @return ?string null si no está o si el nombre no tiene la forma de un afiche
     */
    public function contenido(string $nombre): ?string
    {
        // El nombre sale de la base; si no tiene la forma esperada, no se arma una ruta con él.
        if (preg_match(self::NOMBRE, $nombre) !== 1) {
            return null;
        }
        $contenido = @file_get_contents("{$this->carpeta}/{$nombre}");

        return $contenido === false ? null : $contenido;
    }

    public static function tipo(string $nombre): string
    {
        return self::TIPOS[pathinfo($nombre, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    }

    private function ruta(string $nombre): string
    {
        if (preg_match(self::NOMBRE, $nombre) !== 1) {
            throw new LogicException("«{$nombre}» no es el nombre de un afiche.");
        }

        return "{$this->carpeta}/{$nombre}";
    }
}
