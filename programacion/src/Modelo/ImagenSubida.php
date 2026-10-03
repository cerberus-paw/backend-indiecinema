<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Una imagen que mandó el usuario, ya validada por su contenido: el archivo temporal y la
 * extensión que le corresponde según lo que es, no según cómo se llamaba.
 */
final readonly class ImagenSubida
{
    public function __construct(
        public string $rutaTemporal,
        public string $extension,
    ) {
    }
}
