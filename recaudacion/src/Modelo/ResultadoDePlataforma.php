<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Si la plataforma ganó o perdió plata en el período (EF-22.1): su parte de lo que cobraron todas
 * las salas, contra lo que pagó por los servidores y el desarrollo en esos días. En centavos.
 */
final readonly class ResultadoDePlataforma
{
    public function __construct(
        public int $ingresos,
        /** Los días del período que se cuentan, del primero al último. */
        public int $dias,
        public int $servidores,
        public int $desarrollo,
    ) {
    }

    public function costo(): int
    {
        return $this->servidores + $this->desarrollo;
    }

    public function resultado(): int
    {
        return $this->ingresos - $this->costo();
    }
}
