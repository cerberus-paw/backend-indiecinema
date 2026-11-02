<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

use DateTimeImmutable;

/**
 * Una franja horaria propuesta por el organizador para realizar una función.
 */
final class FranjaHoraria
{
    public function __construct(
        public readonly string $id,
        public readonly string $acuerdoId,
        public readonly DateTimeImmutable $inicio,
        public readonly ?DateTimeImmutable $fin = null,
        public readonly ?DateTimeImmutable $creadaEn = null,
        public readonly int $votos = 0,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function paraJson(): array
    {
        return [
            'id' => $this->id,
            'acuerdo_id' => $this->acuerdoId,
            'inicio' => $this->inicio->format('Y-m-d H:i:s'),
            'fin' => $this->fin?->format('Y-m-d H:i:s'),
            'creada_en' => $this->creadaEn?->format('Y-m-d H:i:s'),
            'votos' => $this->votos,
        ];
    }
}
