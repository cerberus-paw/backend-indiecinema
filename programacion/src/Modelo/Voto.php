<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * El voto de un espectador por una obra en una función en votación de títulos (E2 / E3-12.2).
 * Hay un único voto por usuario y función.
 */
final readonly class Voto
{
    public function __construct(
        public string $funcionId,
        public string $usuarioId,
        public string $obraId,
        public DateTimeImmutable $votadoEn = new DateTimeImmutable(),
    ) {
    }
}
