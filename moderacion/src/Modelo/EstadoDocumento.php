<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * En qué quedó un documento: pendiente hasta que se resuelve la solicitud, que al aprobarse lo
 * acepta y al rechazarse lo rechaza (grilling de EF-07, Q6).
 */
enum EstadoDocumento: string
{
    case Pendiente = 'pendiente';
    case Aceptado = 'aceptado';
    case Rechazado = 'rechazado';
}
