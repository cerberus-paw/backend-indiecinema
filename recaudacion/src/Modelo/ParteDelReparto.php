<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Una parte de lo cobrado de una función: cuánto le toca a la sala, a un dueño o a la plataforma
 * (EF-08.2). El monto va en centavos enteros, como todo en recaudación.
 */
final readonly class ParteDelReparto
{
    /**
     * @param ?string $beneficiarioId el organizador o el dueño, los dos de programación; null en la
     *                                parte de la plataforma, que es el sitio
     * @param int     $centavos       lo que le toca; puede ser 0 si lo cobrado fue muy poco
     *
     * @throws ReglaIncumplida si el monto es negativo, o si el beneficiario no corresponde al rol
     */
    public function __construct(
        public RolDelReparto $rol,
        public ?string $beneficiarioId,
        public int $centavos,
    ) {
        if ($centavos < 0) {
            throw new ReglaIncumplida('Ninguna parte del reparto puede ser negativa.');
        }
        if (($rol === RolDelReparto::Plataforma) !== ($beneficiarioId === null)) {
            throw new ReglaIncumplida('La parte de la plataforma no tiene beneficiario, y las otras dos sí.');
        }
    }

    /** «1500.00», con dos decimales como la columna. */
    public function monto(): string
    {
        return Monto::comoDecimal($this->centavos);
    }
}
