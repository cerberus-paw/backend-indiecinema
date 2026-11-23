<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Cómo le va a un género en una sala (EF-22.18): cuánto llenan sus funciones, qué le pareció al
 * público y cuánto lo vota. El profe, el 18/11: «porcentaje de sala más tipo de película».
 */
final readonly class GeneroDeSala
{
    /**
     * @param int  $funciones las funciones con alguna obra de ese género
     * @param int  $ocupacion de esas funciones, en porcentaje de los lugares
     * @param ?int $votos     de los votos de las votaciones de la sala, el porcentaje que se llevaron
     *                        las obras de ese género; null si en la sala no se votó
     */
    public function __construct(
        public string $salaId,
        public string $salaNombre,
        public string $genero,
        public int $funciones,
        public int $ocupacion,
        public ?int $votos,
        public ?float $promedio,
    ) {
    }
}
