<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * La función que propone una recomendación de los reportes, para abrir el alta de programación ya
 * cargada (EF-22.17): el profe, el 18/11, «precargás el formulario, le das enter». Dice lo que la
 * recomendación sabe; lo que no (la hora, el cupo, el precio de siempre) lo completa
 * Precargas con lo que la sala hizo en el período.
 */
final readonly class FuncionParaProgramar
{
    /**
     * @param list<string> $obrasIds  lo que se da; vacía si la recomendación no dice qué
     * @param ?int         $dia       el día de la semana (1 es lunes), o null para el que más llena
     *                                en la sala
     * @param ?string      $franja    'tarde' o 'noche' si la recomendación la dice, o null
     * @param ?int         $precio    en centavos (0 es a la gorra), o null para el de siempre de la
     *                                sala
     * @param int          $descuento el porcentaje que se le saca al de siempre: la promoción del
     *                                día flojo
     */
    public function __construct(
        public string $salaId,
        public array $obrasIds = [],
        public ?int $dia = null,
        public ?string $franja = null,
        public ?int $precio = null,
        public int $descuento = 0,
    ) {
    }
}
