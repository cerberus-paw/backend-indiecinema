<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

use DateTimeImmutable;

/**
 * Un papel de una sala (EF-07.4): el PDF, guardado en el volumen de moderación con un nombre al
 * azar, y hasta cuándo vale. Cuelga de la sala; la solicitud es null en una renovación.
 */
final readonly class Documento
{
    public function __construct(
        public string $id,
        public string $salaId,
        public ?string $solicitudId,
        public TipoDocumento $tipo,
        public string $archivo,
        public DateTimeImmutable $venceEn,
        public DateTimeImmutable $subidoEn,
        public EstadoDocumento $estado,
    ) {
    }

    /** Vencido si vence ese día o antes: el día que vence ya no vale. */
    public function vencidoAl(DateTimeImmutable $hoy): bool
    {
        return $this->venceEn <= $hoy;
    }
}
