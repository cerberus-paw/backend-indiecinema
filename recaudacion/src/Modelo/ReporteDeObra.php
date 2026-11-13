<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Cómo le fue a una obra en el período, para su dueño (EF-09.3): dónde y cuándo se proyectó, con
 * cuánto público y qué calificación, y cómo le fue en las votaciones de títulos, también en las
 * que perdió. Lo que le corresponde sale con el reparto.
 */
final class ReporteDeObra
{
    /** Lo sumado de las funciones en las que se proyectó. */
    public readonly TotalDeFunciones $proyecciones;

    /** @var list<FuncionFinalizada> las funciones en las que se proyectó, de la primera a la última */
    public array $funciones = [];

    /** @var list<array{funcion: FuncionFinalizada, votos: int, total: int, gano: bool}> */
    public array $votaciones = [];

    public function __construct(
        public readonly string $obraId,
        public readonly string $titulo,
    ) {
        $this->proyecciones = new TotalDeFunciones();
    }

    public function ganadas(): int
    {
        return count(array_filter($this->votaciones, static fn (array $votacion): bool => $votacion['gano']));
    }

    /** Los votos que sacó, sumados en todas sus votaciones. */
    public function votos(): int
    {
        return array_sum(array_column($this->votaciones, 'votos'));
    }
}
