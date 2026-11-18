<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Un día de la semana en que una sala se llena poco: el dato que justifica una promoción. La
 * promoción no se implementa (beneficios es EF-17): el reporte muestra por qué convendría.
 *
 * Si el público de esa sala votó una obra que perdió y no se proyectó, también dice cuál dar ese
 * día (EF-09.5): el día flojo y lo que pidió el público, cruzados en una sola decisión.
 */
final readonly class DiaFlojo
{
    /**
     * @param int    $dia            1 es lunes y 7 domingo, como date('N')
     * @param int    $ocupacion      el porcentaje de los lugares que se vendieron ese día
     * @param ?int   $ocupacionResto el de los demás días, o null si la sala sólo tuvo funciones ése
     * @param ?PedidoDelPublico $paraDar la obra más votada que perdió en esa sala, o null si no hay
     */
    public function __construct(
        public string $salaId,
        public string $salaNombre,
        public int $dia,
        public int $funciones,
        public int $ocupacion,
        public ?int $ocupacionResto,
        public ?PedidoDelPublico $paraDar = null,
    ) {
    }
}
