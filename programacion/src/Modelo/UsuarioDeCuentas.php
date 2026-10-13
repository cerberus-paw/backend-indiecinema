<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

use DateTimeImmutable;

/**
 * Quién mandó una solicitud de sala, como lo cuenta cuentas por su API interna: la bandeja lo
 * muestra en el historial del solicitante.
 */
final readonly class UsuarioDeCuentas
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public string $id,
        public string $nombre,
        public string $correo,
        public string $estado,
        public array $roles,
        public DateTimeImmutable $creadoEn,
    ) {
    }
}
