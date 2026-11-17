<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use DateTimeImmutable;

/**
 * Lo que se le liquida a un beneficiario por un mes (EF-08.3): la suma de sus partes de los
 * repartos de ese mes, con el estado de la transferencia.
 *
 * Mientras está pendiente, la tarea del reparto la vuelve a armar cada vez que reparte algo de ese
 * mes, así el monto sigue a lo que se repartió. Una vez transferida no se toca más: el monto es el
 * que se pagó.
 */
final readonly class Liquidacion
{
    /**
     * @param int     $centavos      lo que le toca en el mes
     * @param ?string $nota          lo que escribió el administrador al marcarla transferida
     *
     * @throws ReglaIncumplida si el monto es negativo, o si el estado y la fecha se contradicen
     */
    public function __construct(
        public string $id,
        public string $beneficiarioId,
        public Mes $periodo,
        public int $centavos,
        public EstadoDeLiquidacion $estado = EstadoDeLiquidacion::Pendiente,
        public ?DateTimeImmutable $transferidaEn = null,
        public ?string $nota = null,
    ) {
        if ($centavos < 0) {
            throw new ReglaIncumplida('Una liquidación no puede ser negativa.');
        }
        if (($estado === EstadoDeLiquidacion::Transferida) !== ($transferidaEn !== null)) {
            throw new ReglaIncumplida('Una liquidación transferida tiene fecha, y una pendiente no.');
        }
    }

    /** «2450.00», con dos decimales como la columna. */
    public function monto(): string
    {
        return Monto::comoDecimal($this->centavos);
    }

    public function estaTransferida(): bool
    {
        return $this->estado === EstadoDeLiquidacion::Transferida;
    }
}
