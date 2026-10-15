<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * La información de la sala que se muestra en el detalle público de una función.
 */
final readonly class SalaEnDetalle
{
    public function __construct(
        public string $id,
        public string $nombre,
        public string $descripcion,
        public string $direccion,
        public string $localidad,
        public int $capacidad,
        /** El nombre del archivo en el volumen, o null si no tiene. */
        public ?string $imagen,
        public ?string $organizadorId = null,
    ) {
    }
}
