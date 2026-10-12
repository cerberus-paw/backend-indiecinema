<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Modelo;

/**
 * Lo que muestra la ficha pública de una sala: la sala y sus próximas funciones.
 */
final readonly class FichaDeSala
{
    /**
     * @param list<FuncionEnCartelera> $funciones las de la cartelera en esta sala, por fecha
     */
    public function __construct(
        public Sala $sala,
        public array $funciones,
    ) {
    }
}
