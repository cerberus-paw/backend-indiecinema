<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

use IndieCinema\Nucleo\Seguridad\Rol;

/**
 * Un usuario como lo ve el administrador en el listado: sin el hash de la contraseña, que no tiene
 * por qué salir de la consulta del ingreso.
 */
final readonly class UsuarioEnListado
{
    /**
     * @param list<Rol> $roles de menor a mayor
     */
    public function __construct(
        public string $id,
        public string $nombre,
        public string $correo,
        public EstadoCuenta $estado,
        public array $roles,
    ) {
    }
}
