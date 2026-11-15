<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * En qué quedó una solicitud de sala (E2: EstadoSolicitud). La pendiente y la que está en revisión
 * manual (EF-07.6) siguen abiertas, y se resuelven aprobándolas o rechazándolas.
 */
enum EstadoSolicitud: string
{
    case Pendiente = 'pendiente';
    case EnRevisionManual = 'en_revision_manual';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';

    /**
     * Las que esperan una resolución.
     *
     * @return list<self>
     */
    public static function abiertas(): array
    {
        return [self::Pendiente, self::EnRevisionManual];
    }
}
