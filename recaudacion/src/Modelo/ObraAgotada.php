<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Una obra que casi agotó sus funciones en una sala (EF-09.5): la demanda llegó al cupo, así que
 * es probable que haya gente que no consiguió lugar. Distinta de «para repetir», que mira si
 * gustó: ésta dice que falta oferta y conviene sumar otra función.
 */
final readonly class ObraAgotada
{
    /**
     * @param int $funciones las de la obra en esa sala en el período
     * @param int $agotadas  las que vendieron el umbral o más de sus lugares
     * @param int $ocupacion la de todas sus funciones en esa sala juntas
     */
    public function __construct(
        public string $obraId,
        public string $titulo,
        public string $salaId,
        public string $salaNombre,
        public int $funciones,
        public int $agotadas,
        public int $ocupacion,
    ) {
    }
}
