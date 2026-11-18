<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

use DateTimeImmutable;

/**
 * Una causa por la que una sala está apagada (EF-07.5). El tipo de documento sólo en las de
 * vencimiento; quién la abrió es null si fue la tarea que suspende las vencidas.
 */
final readonly class Suspension
{
    public function __construct(
        public string $id,
        public string $salaId,
        public CausaSuspension $causa,
        public ?TipoDocumento $tipoDocumento,
        public string $motivo,
        public ?string $abiertaPor,
        public DateTimeImmutable $desde,
    ) {
    }
}
