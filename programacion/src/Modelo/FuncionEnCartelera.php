<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * Una función programada como se muestra en la cartelera: con lo que el visitante necesita para
 * elegirla (obras, sala, localidad, fecha y precio) y no el resto de la función.
 */
final readonly class FuncionEnCartelera
{
    /**
     * @param non-empty-list<ObraEnCartelera> $obras las que se proyectan, por título
     */
    public function __construct(
        public string $id,
        public ?DateTimeImmutable $fechaHora,
        public float $precioBase,
        public string $salaId,
        public string $sala,
        public string $localidad,
        public array $obras,
        public EstadoFuncion $estado = EstadoFuncion::Programada,
    ) {
    }
}
