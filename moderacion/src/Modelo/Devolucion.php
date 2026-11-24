<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

use DateTimeImmutable;

/**
 * Por qué el moderador le devolvió la solicitud al solicitante (EF-22.10): qué documentos tiene que
 * volver a subir, el motivo, quién y cuándo, y el último día para completarla. Se guarda la última;
 * las anteriores quedan en el checklist y en los documentos rechazados.
 */
final readonly class Devolucion
{
    /**
     * La zona de la fecha límite y del «hoy» con que se compara: los dos tienen que estar en la
     * misma, o la medianoche de un día cae horas antes que la del otro.
     */
    public const ZONA = 'America/Argentina/Buenos_Aires';

    /**
     * @param list<TipoDocumento> $faltantes los que marcó, en el orden de TipoDocumento
     * @param DateTimeImmutable   $vence     el último día para completarla, en Argentina
     */
    public function __construct(
        public array $faltantes,
        public string $motivo,
        public string $devueltaPor,
        public DateTimeImmutable $devueltaEn,
        public DateTimeImmutable $vence,
    ) {
    }

    /** Para los mensajes: «el seguro», «el seguro y la habilitación». */
    public function queFalta(): string
    {
        return implode(' y ', array_map(static fn (TipoDocumento $tipo): string => $tipo->conArticulo(), $this->faltantes));
    }
}
