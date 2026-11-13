<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Cómo le fue a una sala en el período, para ordenarlas. Lo que no se pudo calcular va en null:
 * sin calificaciones no hay promedio, y sin votaciones no hay cuántos de los que votaron reservaron.
 */
final readonly class RendimientoDeSala
{
    /**
     * @param int   $ocupacion  porcentaje de los lugares vendidos
     * @param ?int  $asistencia porcentaje de los que reservaron que fueron; null si nadie reservó
     * @param ?int  $conversion porcentaje de los que votaron títulos que después reservaron
     */
    public function __construct(
        public string $salaId,
        public string $salaNombre,
        public int $funciones,
        public int $ocupacion,
        public ?int $asistencia,
        public ?float $promedio,
        public ?int $conversion,
    ) {
    }
}
