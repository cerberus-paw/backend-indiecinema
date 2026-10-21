<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use DateTimeImmutable;

/**
 * La fecha y los montos escritos como se leen, igual que en la cartelera de programación: las
 * plantillas reciben el texto y no los valores.
 */
final class TextoParaMostrar
{
    /** La imagen no tiene intl, así que los nombres van acá. */
    private const DIAS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /**
     * «Sáb 19 sep · 20:30»
     */
    public static function fecha(DateTimeImmutable $fecha): string
    {
        return sprintf(
            '%s %d %s · %s',
            self::DIAS[(int) $fecha->format('w')],
            (int) $fecha->format('j'),
            self::MESES[(int) $fecha->format('n') - 1],
            $fecha->format('H:i'),
        );
    }

    /**
     * «$ 3.500», con los centavos sólo si tiene; 0 es «a la gorra».
     */
    public static function monto(float $monto): string
    {
        if ($monto == 0) {
            return 'A la gorra';
        }
        $decimales = fmod($monto, 1.0) == 0 ? 0 : 2;

        return '$ ' . number_format($monto, $decimales, ',', '.');
    }
}
