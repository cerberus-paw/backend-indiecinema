<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Modelo;

use DateTimeImmutable;

/**
 * El acuerdo de fecha con los espectadores para una función (E2 / E3-13).
 */
final class AcuerdoFecha
{
    /**
     * @param list<FranjaHoraria> $franjas
     */
    public function __construct(
        public readonly string $id,
        public readonly string $funcionId,
        public readonly int $minimoAsistentes,
        public readonly EstadoAcuerdo $estado = EstadoAcuerdo::Abierto,
        public readonly ?string $franjaGanadoraId = null,
        public readonly ?DateTimeImmutable $creadoEn = null,
        public readonly ?DateTimeImmutable $cerradoEn = null,
        public readonly array $franjas = [],
    ) {
    }

    public function estaAbierto(): bool
    {
        return $this->estado->estaAbierto();
    }

    /**
     * Devuelve la franja con más votos acumulados, o null si no hay franjas.
     */
    public function franjaMasVotada(): ?FranjaHoraria
    {
        if ($this->franjas === []) {
            return null;
        }

        $max = null;
        foreach ($this->franjas as $franja) {
            if ($max === null || $franja->votos > $max->votos) {
                $max = $franja;
            }
        }

        return $max;
    }

    /**
     * Indica si alguna franja alcanzó o superó el mínimo de asistentes requerido.
     */
    public function alcanzoMinimo(): bool
    {
        $lider = $this->franjaMasVotada();

        return $lider !== null && $lider->votos >= $this->minimoAsistentes;
    }

    /**
     * @return array<string, mixed>
     */
    public function paraJson(): array
    {
        return [
            'id' => $this->id,
            'funcion_id' => $this->funcionId,
            'minimo_asistentes' => $this->minimoAsistentes,
            'estado' => $this->estado->value,
            'estado_etiqueta' => $this->estado->etiqueta(),
            'franja_ganadora_id' => $this->franjaGanadoraId,
            'alcanzo_minimo' => $this->alcanzoMinimo(),
            'creado_en' => $this->creadoEn?->format('Y-m-d H:i:s'),
            'cerrado_en' => $this->cerradoEn?->format('Y-m-d H:i:s'),
            'franjas' => array_map(static fn (FranjaHoraria $f): array => $f->paraJson(), $this->franjas),
        ];
    }
}
