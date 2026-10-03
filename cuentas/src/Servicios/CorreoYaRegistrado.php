<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DomainException;

/**
 * El correo ya es de otra cuenta. Lo decide la clave única de la base, no una consulta previa:
 * entre la consulta y el INSERT podría colarse otro registro.
 */
final class CorreoYaRegistrado extends DomainException
{
}
