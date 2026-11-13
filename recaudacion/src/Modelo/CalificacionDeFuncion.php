<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * El promedio de las calificaciones de una función (de 1 a 5, con un decimal) y cuántas son.
 */
final readonly class CalificacionDeFuncion
{
    public function __construct(
        public float $promedio,
        public int $cantidad,
    ) {
    }
}
