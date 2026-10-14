<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

use IndieCinema\Nucleo\Seguridad\Rol;

/**
 * Los filtros del listado de usuarios del administrador, como en el wireframe de la E2: el texto
 * (nombre o correo), el rol, el estado de la cuenta y el año de alta. El que es null no filtra.
 */
final readonly class FiltrosDeUsuarios
{
    public function __construct(
        public ?string $texto = null,
        public ?Rol $rol = null,
        public ?EstadoCuenta $estado = null,
        public ?int $alta = null,
    ) {
    }
}
