<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Los espectadores que más participan en una sala (EF-22.19), del puntaje más alto al más bajo.
 */
final readonly class RankingDeSala
{
    /**
     * @param list<EspectadorDeSala> $espectadores los primeros, ya ordenados
     * @param int                    $participaron  cuántos espectadores hicieron algo en la sala,
     *                                              también los que no entraron en el ranking
     */
    public function __construct(
        public string $salaId,
        public string $salaNombre,
        public array $espectadores,
        public int $participaron,
    ) {
    }
}
