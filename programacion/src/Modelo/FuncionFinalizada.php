<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * Una función que ya terminó, con lo que necesita la reportería de recaudación (EF-09): cuándo
 * fue, en qué sala, cuántos lugares tenía y cómo se eligió lo que se proyectó. Viaja por
 * GET /interno/funciones/finalizadas.
 */
final readonly class FuncionFinalizada
{
    /**
     * @param DateTimeImmutable $fechaHora   la hora de la sala, como la guarda programación
     * @param string            $precioBase  con dos decimales, como la base: recaudación no pasa
     *                                       la plata por float
     * @param list<ObraVotada>  $obras       todas las que estuvieron en la función: las elegidas y,
     *                                       si hubo votación, las candidatas que perdieron
     * @param list<string>      $votantesIds quienes votaron títulos, para cruzarlos con las
     *                                       reservas; vacía si se programó sin votación
     */
    public function __construct(
        public string $id,
        public DateTimeImmutable $fechaHora,
        public int $cupo,
        public string $precioBase,
        public string $salaId,
        public string $salaNombre,
        public string $organizadorId,
        public array $obras,
        public array $votantesIds,
    ) {
    }
}
