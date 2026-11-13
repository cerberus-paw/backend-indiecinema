<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * En qué quedó una solicitud de sala (E2: EstadoSolicitud). Por ahora se resuelve aprobándola o
 * rechazándola; la revisión manual y la suspensión por vencimiento llegan con el resto de EF-07.
 */
enum EstadoSolicitud: string
{
    case Pendiente = 'pendiente';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';
}
