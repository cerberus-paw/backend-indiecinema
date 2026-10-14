<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use RuntimeException;

/**
 * El usuario ya tiene una solicitud de sala sin resolver: tiene que esperar la respuesta antes de
 * mandar otra.
 */
final class SolicitudPendiente extends RuntimeException
{
}
