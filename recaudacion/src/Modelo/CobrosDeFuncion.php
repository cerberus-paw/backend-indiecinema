<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Lo cobrado de una función: cada reserva en pie y el neto, que es lo que se reparte (EF-08).
 */
final readonly class CobrosDeFuncion
{
    /**
     * @param list<CobroDeReserva> $reservas
     * @param string               $neto     lo cobrado menos lo reembolsado, con dos decimales
     */
    public function __construct(
        public string $funcionId,
        public array $reservas,
        public string $neto,
    ) {
    }
}
