<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Servicios;

use RuntimeException;

/**
 * La última solicitud del usuario no está devuelta, o se le pasó el plazo para completarla
 * (EF-22.10): no se guarda nada.
 */
final class NoSePuedeCompletar extends RuntimeException
{
}
