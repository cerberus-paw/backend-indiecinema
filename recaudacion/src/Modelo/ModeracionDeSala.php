<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Cómo se portó una sala y su público en el período (EF-22.4): las denuncias contra la sala o sus
 * funciones, si está suspendida, y la conducta de quienes reservaron.
 */
final readonly class ModeracionDeSala
{
    /**
     * @param array{abierta: int, desestimada: int, resuelta: int} $denuncias
     * @param ?string $suspension la causa, si está suspendida hoy: retiro, vencimiento o denuncia
     */
    public function __construct(
        public string $salaId,
        public string $salaNombre,
        public array $denuncias,
        public ?string $suspension,
        public int $ausentes,
        public int $negativas,
        /** De los que reservaron en el período, cuántos están bajo el umbral de conducta. */
        public int $bajoUmbral,
        public int $espectadores,
    ) {
    }

    public function totalDeDenuncias(): int
    {
        return array_sum($this->denuncias);
    }
}
