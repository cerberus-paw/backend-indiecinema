<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DomainException;

/**
 * Un token de verificación o recuperación que no existe, pertenece a otro propósito, ya se usó o
 * venció (X-02).
 */
final class TokenInvalido extends DomainException
{
    public function __construct(string $mensaje, public readonly int $estadoHttp)
    {
        parent::__construct($mensaje);
    }

    public static function inexistente(): self
    {
        return new self('El enlace no es válido o no existe.', 400);
    }

    public static function yaUsado(): self
    {
        return new self('El enlace ya se usó.', 410);
    }

    public static function vencido(): self
    {
        return new self('El enlace ya venció.', 410);
    }
}
