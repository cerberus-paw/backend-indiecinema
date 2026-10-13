<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * Lo que necesitan de una función los otros subsistemas (funciones, para el acuerdo de fecha y
 * las reservas): su estado, cuándo, cuánto cuesta, cuántos entran, qué se proyecta, en qué sala
 * y quién la organiza. Viaja por GET /interno/funciones/{id}.
 */
final readonly class ResumenDeFuncion
{
    /**
     * @param ?DateTimeImmutable $fechaHora      la hora de la sala, como la guarda programación;
     *                                           null mientras no tiene fecha
     * @param list<string>       $titulos        las elegidas o, mientras está en votación, las
     *                                           candidatas, por título
     * @param bool               $titulosElegidos si $titulos ya son las que se proyectan
     */
    public function __construct(
        public string $id,
        public EstadoFuncion $estado,
        public ?DateTimeImmutable $fechaHora,
        public float $precioBase,
        public int $cupo,
        public array $titulos,
        public bool $titulosElegidos,
        public string $salaId,
        public string $salaNombre,
        public string $salaLocalidad,
        public bool $salaHabilitada,
        public string $organizadorId,
    ) {
    }
}
