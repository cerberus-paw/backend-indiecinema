<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DomainException;

/**
 * El correo no tiene cuenta o la contraseña no es la suya: a propósito, no se dice cuál de las
 * dos, para que el formulario de ingreso no sirva para averiguar qué correos están registrados.
 */
final class CredencialesInvalidas extends DomainException
{
}
