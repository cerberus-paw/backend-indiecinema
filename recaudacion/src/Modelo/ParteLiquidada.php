<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use DateTimeImmutable;

/**
 * Una línea del detalle de una liquidación (EF-08.3): qué función fue, cuánto se cobró en ella y
 * qué parte le tocó al beneficiario, como sala o como dueño de una de las obras. La comisión es lo
 * que se quedó Mercado Pago de toda la función, que se descontó antes de repartir (F-03.6): 0 en los
 * repartos anteriores a la comisión.
 *
 * La función viene de lo que el reparto guardó en su momento, no de programación: por eso la fecha,
 * la sala y los títulos pueden faltar en los repartos anteriores a la migración que los agregó.
 */
final readonly class ParteLiquidada
{
    public function __construct(
        public string $funcionId,
        public ?DateTimeImmutable $fechaHora,
        public ?string $salaNombre,
        public ?string $titulos,
        public int $cobradoCentavos,
        public int $comisionCentavos,
        public RolDelReparto $rol,
        public int $centavos,
    ) {
    }

    /** «2450.00», con dos decimales como la columna. */
    public function monto(): string
    {
        return Monto::comoDecimal($this->centavos);
    }
}
