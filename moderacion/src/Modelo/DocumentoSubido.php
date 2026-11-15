<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

use DateTimeImmutable;

/**
 * Un PDF que mandó el usuario, ya validado por su contenido, con el vencimiento que escribió: el
 * archivo temporal, todavía sin guardar.
 */
final readonly class DocumentoSubido
{
    public function __construct(
        public TipoDocumento $tipo,
        public string $rutaTemporal,
        public DateTimeImmutable $venceEn,
    ) {
    }
}
