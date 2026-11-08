<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * Lo que hace falta de una función para contar sus lugares libres y saber si se reserva: sin las
 * obras ni la sala, porque la ficha lo pide cada pocos segundos (E4-05.1).
 */
final readonly class CupoDeFuncion
{
    public const MAXIMO_POR_PERSONA = Funcion::MAXIMO_POR_PERSONA;

    public function __construct(
        public string $id,
        public EstadoFuncion $estado,
        public ?DateTimeImmutable $fechaHora,
        public int $cupo,
    ) {
    }
}
