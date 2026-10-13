<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use RuntimeException;

/**
 * El usuario ya tiene una solicitud sin resolver: hasta que el administrador la resuelva no puede
 * mandar otra. Lo dice la base, con el índice único de solicitud.pendiente_de.
 */
final class SolicitudPendiente extends RuntimeException
{
}
