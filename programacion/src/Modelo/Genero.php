<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Un género con el que se clasifican las obras (E2: GeneroObra). Es una lista aparte para que el
 * catálogo filtre por género sin depender de cómo lo escribió cada dueño.
 */
final readonly class Genero
{
    public function __construct(
        public string $id,
        public string $nombre,
        public string $descripcion,
    ) {
    }
}
