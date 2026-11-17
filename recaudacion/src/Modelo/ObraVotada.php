<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Una obra de una función finalizada y los votos que sacó en la votación de títulos.
 */
final readonly class ObraVotada
{
    public function __construct(
        public string $id,
        public string $titulo,
        public string $duenoId,
        public bool $elegida,
        public int $votos,
    ) {
    }
}
