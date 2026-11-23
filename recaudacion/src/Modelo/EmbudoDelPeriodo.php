<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * El embudo de todas las salas juntas y el de cada una (EF-22.13), con lo que faltó si funciones
 * no respondió.
 */
final readonly class EmbudoDelPeriodo
{
    /**
     * @param list<Embudo>  $salas  de la que más votantes tuvo a la que menos
     * @param list<string>  $avisos
     */
    public function __construct(
        public Embudo $total,
        public array $salas,
        public array $avisos = [],
    ) {
    }
}
