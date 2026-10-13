<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Las fechas se guardan en UTC y se muestran con la hora de la Argentina, que es donde están las
 * salas y el público.
 */
final class FechaParaMostrar
{
    private const ZONA = 'America/Argentina/Buenos_Aires';

    public static function dia(DateTimeImmutable $fecha): string
    {
        return $fecha->setTimezone(new DateTimeZone(self::ZONA))->format('d/m/Y');
    }

    public static function diaYHora(DateTimeImmutable $fecha): string
    {
        return $fecha->setTimezone(new DateTimeZone(self::ZONA))->format('d/m/Y H:i');
    }
}
