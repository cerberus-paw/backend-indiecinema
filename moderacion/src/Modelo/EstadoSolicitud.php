<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * En qué quedó una solicitud de sala (E2: EstadoSolicitud). La pendiente y la que está en revisión
 * manual (EF-07.6) esperan al moderador, que las aprueba, las rechaza o, si falta un documento, las
 * devuelve (EF-22.10). La devuelta espera al solicitante: si la completa vuelve a pendiente, y si
 * se le pasa el plazo queda vencida. Las tres siguen abiertas; aprobada, rechazada y vencida son
 * terminales.
 */
enum EstadoSolicitud: string
{
    case Pendiente = 'pendiente';
    case EnRevisionManual = 'en_revision_manual';
    case Devuelta = 'devuelta';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';
    case Vencida = 'vencida';

    /**
     * Las que no terminaron: ocupan el lugar de la única abierta de la sala, y mientras tanto quien
     * la mandó no manda otra.
     *
     * @return list<self>
     */
    public static function abiertas(): array
    {
        return [self::Pendiente, self::EnRevisionManual, self::Devuelta];
    }

    /**
     * Las que esperan que el moderador las resuelva: la devuelta espera al solicitante.
     *
     * @return list<self>
     */
    public static function porResolver(): array
    {
        return [self::Pendiente, self::EnRevisionManual];
    }
}
