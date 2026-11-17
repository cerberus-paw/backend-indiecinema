<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * Un espectador bloqueado en una sala por su organizador (EF-12.1).
 */
final readonly class BloqueoDeSala
{
    public function __construct(
        public string $salaId,
        public string $salaNombre,
        public string $usuarioId,
        public string $motivo,
        public string $bloqueadoPor,
        public DateTimeImmutable $bloqueadoEn,
    ) {
    }
}
