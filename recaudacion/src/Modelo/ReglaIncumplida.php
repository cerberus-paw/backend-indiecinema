<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use DomainException;

/**
 * Un dato que no se puede usar, con el mensaje para mostrarle a quien lo cargó.
 */
final class ReglaIncumplida extends DomainException
{
}
