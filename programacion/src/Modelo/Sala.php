<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use IndieCinema\Nucleo\Uuid;

/**
 * Una sala indie y el organizador que la administra.
 *
 * Hasta que exista moderación (E4), la publica el administrador poniendo habilitada en verdadero;
 * el organizador no la puede habilitar desde el formulario.
 */
final class Sala
{
    public function __construct(
        public readonly string $id,
        public readonly string $organizadorId,
        public private(set) DatosDeSala $datos,
        public readonly bool $habilitada = false,
    ) {
    }

    public static function nueva(string $organizadorId, DatosDeSala $datos): self
    {
        return new self(Uuid::nuevo(), $organizadorId, $datos);
    }

    public function esDe(string $usuarioId): bool
    {
        return hash_equals($this->organizadorId, $usuarioId);
    }

    public function cambiarDatos(DatosDeSala $datos): void
    {
        $this->datos = $datos;
    }
}
