<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Una obra que se llenó, a la que fue la gente que reservó y que gustó: conviene volver a darla.
 */
final readonly class TituloParaRepetir
{
    /**
     * @param int   $ocupacion  porcentaje de los lugares vendidos, en sus funciones del período
     * @param int   $asistencia porcentaje de los que reservaron que fueron
     */
    public function __construct(
        public string $obraId,
        public string $titulo,
        public int $funciones,
        public int $ocupacion,
        public int $asistencia,
        public float $promedio,
    ) {
    }
}
