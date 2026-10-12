<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * La ficha o detalle público de una función con su sala y sus obras.
 */
final readonly class DetalleDeFuncion
{
    /**
     * @param non-empty-list<ObraEnDetalle> $obras las obras (elegidas o candidatas)
     */
    public function __construct(
        public string $id,
        public EstadoFuncion $estado,
        public ?DateTimeImmutable $fechaHora,
        public float $precioBase,
        public int $cupo,
        public SalaEnDetalle $sala,
        public array $obras,
        public bool $obrasElegidas = true,
    ) {
    }
}
