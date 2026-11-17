<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DomainException;

/**
 * La contraseña actual no vino o no coincide con la de la cuenta al cambiar el correo o la clave.
 */
final class ContrasenaActualIncorrecta extends DomainException
{
    public static function faltanteParaCambiarCorreo(): self
    {
        return new self('Ingresá tu contraseña actual para cambiar el correo.');
    }

    public static function noCoincide(): self
    {
        return new self('La contraseña actual no es correcta.');
    }
}
