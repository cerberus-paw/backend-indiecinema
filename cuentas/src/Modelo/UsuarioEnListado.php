<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

use DateTimeImmutable;
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
        public DateTimeImmutable $creadoEn,
    ) {
    }

    /**
     * El mayor de sus roles, el que muestra la tabla: se acumulan, y el organizador también es
     * espectador. Todo usuario nace espectador, así que la lista nunca está vacía.
     */
    public function mayorRol(): Rol
    {
        return $this->roles[array_key_last($this->roles)] ?? Rol::Espectador;
    }
}
