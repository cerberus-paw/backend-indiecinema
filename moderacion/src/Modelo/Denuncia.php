<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

use DateTimeImmutable;

/**
 * Una denuncia abierta (EF-07.8): quién denunció qué, de qué se trata, qué tan grave es (la dio el
 * motivo al crearla, EF-22.11) y cuándo (en UTC).
 */
final readonly class Denuncia
{
    public function __construct(
        public string $id,
        public TipoDenuncia $tipo,
        public string $objetoId,
        /** La sala denunciada o la de la función (EF-22.11); null en obras y comentarios. */
        public ?string $salaId,
        public string $titulo,
        public string $usuarioId,
        public CategoriaDenuncia $categoria,
        public GravedadDenuncia $gravedad,
        public ?string $detalle,
        public DateTimeImmutable $creadaEn,
        /** El nombre de la foto guardada (EF-22.11), o null si no mandó. */
        public ?string $foto = null,
    ) {
    }
}
