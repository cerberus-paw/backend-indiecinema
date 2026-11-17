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

    /** Lo que dice el precio de una función que no cobra entrada. */
    public const A_LA_GORRA = 'A la gorra';

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
     * «19 sep · 20:30»: igual que fecha() pero sin el día de la semana, para algo que ya pasó —el
     * reembolso— y a lo que nadie tiene que llegar.
     */
    public static function dia(DateTimeImmutable $fecha): string
    {
        return sprintf(
            '%d %s · %s',
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
        return $monto == 0 ? self::A_LA_GORRA : self::suma($monto);
    }

    /**
     * «$ 3.500», igual que monto(), pero un 0 es «$ 0» y no «A la gorra»: una suma de plata puede
     * dar 0 porque todavía no entró nada, y eso no hace que la función sea a la gorra (EF-06.5).
     */
    public static function suma(float $monto): string
    {
        $decimales = fmod($monto, 1.0) == 0 ? 0 : 2;

        return '$ ' . number_format($monto, $decimales, ',', '.');
    }
}
