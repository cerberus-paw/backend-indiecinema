<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Las fechas de las solicitudes se guardan en UTC y se muestran con la hora de la Argentina, que
 * es donde están las salas y el público.
 */
final class FechaParaMostrar
{
    private const ZONA = 'America/Argentina/Buenos_Aires';
    /** Sin depender de la extensión intl, que la imagen de PHP no trae. */
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /** «09 sep», como la lista de la bandeja del wireframe. */
    public static function diaCorto(DateTimeImmutable $fecha): string
    {
        $local = $fecha->setTimezone(new DateTimeZone(self::ZONA));

        return $local->format('d') . ' ' . self::MESES[(int) $local->format('n') - 1];
    }

    public static function dia(DateTimeImmutable $fecha): string
    {
        return $fecha->setTimezone(new DateTimeZone(self::ZONA))->format('d/m/Y');
    }

    public static function diaYHora(DateTimeImmutable $fecha): string
    {
        return $fecha->setTimezone(new DateTimeZone(self::ZONA))->format('d/m/Y H:i');
    }
}
