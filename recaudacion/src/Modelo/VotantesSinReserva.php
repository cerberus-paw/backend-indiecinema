<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * La gente que votó en las funciones de una sala y no reservó ninguna (EF-09.5): ya mostró
 * interés, así que es el público más barato de traer. Es lo que mide «votaron y reservaron» pero
 * contado en personas, para que el organizador sepa a cuántos apunta una promoción.
 */
final readonly class VotantesSinReserva
{
    /**
     * @param int $votantes   las personas que votaron en alguna función de la sala del período
     * @param int $sinReserva las que, de ésas, no reservaron ninguna función de la sala
     */
    public function __construct(
        public string $salaId,
        public string $salaNombre,
        public int $votantes,
        public int $sinReserva,
    ) {
    }
}
