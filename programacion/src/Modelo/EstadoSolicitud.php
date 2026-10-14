<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * En qué quedó una solicitud de sala (E2: EstadoSolicitud). La revisión manual y la suspensión por
 * vencimiento de la póliza son de moderación, que no está: se resuelve aprobándola o rechazándola.
 */
enum EstadoSolicitud: string
{
    case Pendiente = 'pendiente';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';
}
