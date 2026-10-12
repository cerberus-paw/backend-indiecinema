<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Una obra de una función de la cartelera, con lo que se muestra de ella.
 */
final readonly class ObraEnCartelera
{
    public function __construct(
        public string $id,
        public string $titulo,
        /** El nombre del archivo en el volumen, o null si no tiene. */
        public ?string $afiche,
    ) {
    }
}
