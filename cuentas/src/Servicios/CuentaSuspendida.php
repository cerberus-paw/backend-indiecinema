<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DomainException;

/**
 * La contraseña es correcta pero la cuenta no está activa. Se puede decir: quien lo lee ya probó
 * que la cuenta es suya.
 */
final class CuentaSuspendida extends DomainException
{
}
