<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;

/**
 * Una cuenta de IndieCinema y sus roles (E2, modelo de clases de cuentas: Usuario y Rol).
 *
 * No es IndieCinema\Nucleo\Seguridad\Usuario, que es quien hace el pedido según las cabeceras de
 * nginx: esta es la fila de la base, con el hash de la contraseña y el estado.
 */
final class Usuario
{
    /**
     * @param list<Rol> $roles
     */
    public function __construct(
        public readonly string $id,
        public readonly string $nombre,
        public readonly string $correo,
        public readonly string $contrasenaHash,
        public readonly EstadoCuenta $estado,
        public readonly array $roles,
    ) {
    }

    /**
     * Todo usuario nace espectador. Hasta que exista el correo de verificación, la cuenta nace
     * activa; después nacerá pendiente.
     */
    public static function nuevo(string $nombre, string $correo, string $contrasenaHash): self
    {
        return new self(Uuid::nuevo(), $nombre, $correo, $contrasenaHash, EstadoCuenta::Activa, [Rol::Espectador]);
    }
}
