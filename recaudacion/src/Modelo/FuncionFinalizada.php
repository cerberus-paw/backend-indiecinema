<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use DateTimeImmutable;

/**
 * Una función que ya terminó, como la cuenta programación (GET /interno/funciones/finalizadas):
 * lo que la reportería cruza con las ventas y las calificaciones de funciones (EF-09).
 */
final readonly class FuncionFinalizada
{
    /**
     * @param DateTimeImmutable $fechaHora   la hora de la sala
     * @param list<ObraVotada>  $obras       las elegidas y las candidatas que perdieron
     * @param list<string>      $votantesIds quienes votaron títulos; vacía sin votación
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $fechaHora,
        public int $cupo,
        public string $salaId,
        public string $salaNombre,
        public string $organizadorId,
        public array $obras,
        public array $votantesIds,
    ) {
    }

    /** Los votos de la votación de títulos, entre todas las obras: 0 si no hubo. */
    public function votos(): int
    {
        return array_sum(array_map(static fn (ObraVotada $obra): int => $obra->votos, $this->obras));
    }

    /**
     * Las que se proyectaron.
     *
     * @return list<ObraVotada>
     */
    public function elegidas(): array
    {
        return array_values(array_filter($this->obras, static fn (ObraVotada $obra): bool => $obra->elegida));
    }
}
