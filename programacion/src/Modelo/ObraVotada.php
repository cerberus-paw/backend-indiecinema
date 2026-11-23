<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Una obra de una función finalizada, con los votos que sacó: los de las que perdieron también
 * cuentan, porque son lo que el público pidió y no vio (EF-09.1).
 */
final readonly class ObraVotada
{
    /**
     * @param list<string> $generos los nombres de sus géneros, para cruzarlos en los reportes
     *                              (EF-22.18)
     */
    public function __construct(
        public string $id,
        public string $titulo,
        public string $duenoId,
        public bool $elegida,
        public int $votos,
        public array $generos = [],
    ) {
    }
}
