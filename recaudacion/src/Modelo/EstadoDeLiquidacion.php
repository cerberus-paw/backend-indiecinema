<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * En qué anda una liquidación (EF-08.3). Los valores son los de la columna liquidacion.estado.
 *
 * La transferencia se hace fuera del sitio: pasar a «transferida» es la marca del administrador de
 * que ya la pagó, con la fecha y una nota, y no un pago automático.
 */
enum EstadoDeLiquidacion: string
{
    case Pendiente = 'pendiente';

    case Transferida = 'transferida';

    /** Cómo se nombra en la pantalla. */
    public function comoTexto(): string
    {
        return match ($this) {
            self::Pendiente => 'pendiente',
            self::Transferida => 'transferida',
        };
    }
}
