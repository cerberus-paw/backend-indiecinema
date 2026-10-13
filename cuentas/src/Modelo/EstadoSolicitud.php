<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

/**
 * Dónde está una solicitud para ser organizador: la manda el espectador y la resuelve el
 * administrador. Los valores son los de la columna solicitud.estado.
 */
enum EstadoSolicitud: string
{
    case Pendiente = 'pendiente';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';
}
