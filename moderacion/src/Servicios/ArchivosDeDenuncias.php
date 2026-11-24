<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use IndieCinema\Moderacion\Modelo\ImagenSubida;
use IndieCinema\Nucleo\Configuracion;

/**
 * Las fotos de las denuncias de salas y funciones (EF-22.11): se guardan como las de las
 * solicitudes, con un nombre al azar y fuera del webroot, en su propia carpeta del volumen. Sólo
 * las ve la moderación.
 */
final class ArchivosDeDenuncias
{
    private readonly ArchivosDeSolicitudes $archivos;

    public function __construct(Configuracion $configuracion)
    {
        $this->archivos = new ArchivosDeSolicitudes($configuracion, 'denuncias');
    }

    /**
     * @return string el nombre con el que quedó guardada
     */
    public function guardar(ImagenSubida $imagen): string
    {
        return $this->archivos->guardar($imagen);
    }

    public function borrar(string $nombre): void
    {
        $this->archivos->borrar($nombre);
    }

    public function contenido(string $nombre): ?string
    {
        return $this->archivos->contenido($nombre);
    }
}
