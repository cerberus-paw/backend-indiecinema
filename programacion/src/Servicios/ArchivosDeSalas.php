<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Programacion\Modelo\ImagenSubida;
use LogicException;
use RuntimeException;

/**
 * Las imágenes de las salas, en el volumen de archivos de programación: fuera del webroot, así
 * que Apache nunca las ejecuta ni las sirve por su cuenta. Las entrega un controlador después de
 * ver si la sala se puede mostrar.
 *
 * Cada una se guarda con un nombre al azar y la extensión que corresponde a su contenido, nunca
 * con el nombre que mandó el usuario, que podría pisar otro archivo o salirse de la carpeta.
 */
final class ArchivosDeSalas
{
    private const TIPOS = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    private const NOMBRE = '/^[0-9a-f]{32}\.(jpg|png|webp)$/D';

    private readonly string $carpeta;

    public function __construct(Configuracion $configuracion)
    {
        // El volumen que monta el compose (archivos-programacion).
        $this->carpeta = rtrim((string) $configuracion->obtener('archivos.carpeta', '/var/indiecinema/archivos'), '/') . '/salas';
    }

    /**
     * @return string el nombre con el que quedó guardada
     */
    public function guardar(ImagenSubida $imagen): string
    {
        if (!isset(self::TIPOS[$imagen->extension])) {
            throw new LogicException("No se guardan imágenes .{$imagen->extension}.");
        }
        if (!is_dir($this->carpeta) && !mkdir($this->carpeta, 0750, true) && !is_dir($this->carpeta)) {
            throw new RuntimeException("No se pudo crear {$this->carpeta}.");
        }

        $nombre = bin2hex(random_bytes(16)) . '.' . $imagen->extension;
        // rename y no move_uploaded_file: la petición ya dejó pasar sólo archivos subidos en
        // este pedido (Peticion::desdeGlobales).
        if (!rename($imagen->rutaTemporal, $this->ruta($nombre))) {
            throw new RuntimeException("No se pudo guardar la imagen en {$this->carpeta}.");
        }
        chmod($this->ruta($nombre), 0640);

        return $nombre;
    }

    public function borrar(string $nombre): void
    {
        $ruta = $this->ruta($nombre);
        if (is_file($ruta)) {
            unlink($ruta);
        }
    }

    public function contenido(string $nombre): ?string
    {
        $contenido = @file_get_contents($this->ruta($nombre));

        return $contenido === false ? null : $contenido;
    }

    public static function tipo(string $nombre): string
    {
        return self::TIPOS[pathinfo($nombre, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    }

    private function ruta(string $nombre): string
    {
        // El nombre sale de la base, pero si no tiene la forma de los que se generan acá, no se usa.
        if (preg_match(self::NOMBRE, $nombre) !== 1) {
            throw new LogicException("«{$nombre}» no es el nombre de una imagen de sala.");
        }

        return "{$this->carpeta}/{$nombre}";
    }
}
