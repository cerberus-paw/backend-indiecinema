<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

use DateTimeImmutable;

/**
 * Quién mandó una solicitud, como lo ve el administrador al resolverla: su cuenta, no el contacto
 * que dejó en la solicitud.
 */
final readonly class Solicitante
{
    public function __construct(
        public string $id,
        public string $nombre,
        public string $correo,
        public DateTimeImmutable $cuentaCreadaEn,
    ) {
    }
}
