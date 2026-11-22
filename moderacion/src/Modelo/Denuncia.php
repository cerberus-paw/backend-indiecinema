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
        public string $titulo,
        public string $usuarioId,
        public CategoriaDenuncia $categoria,
        public GravedadDenuncia $gravedad,
        public ?string $detalle,
        public DateTimeImmutable $creadaEn,
    ) {
    }
}
