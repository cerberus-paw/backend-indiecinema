<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Si una sala ganó o perdió plata en el período (EF-22.1): lo cobrado, cómo se repartió y lo que
 * se lleva la sala contra lo que le costó abrir para cada función. En centavos.
 *
 * Las funciones a la gorra entran con su costo y sin plata cobrada: la gorra va directo a la sala
 * y el sitio no la ve, así que el resultado de una sala que da mucho a la gorra es el peor caso.
 */
final readonly class ResultadoDeSala
{
    public function __construct(
        public string $salaId,
        public string $salaNombre,
        public int $funciones,
        public int $cobrado,
        public int $comision,
        public int $sala,
        public int $duenos,
        public int $plataforma,
        public int $costoFuncion,
        /** Si el costo lo cargó la sala; si no, es el de config.ini. */
        public bool $costoPropio,
        /** Las funciones todavía sin repartir, calculadas con los porcentajes de su fecha. */
        public int $estimadas = 0,
        /** Las funciones de las que funciones no dijo cuánto se cobró: no suman plata ni costo. */
        public int $sinDatos = 0,
    ) {
    }

    /** Lo que le costó abrir para las funciones que se cuentan. */
    public function costo(): int
    {
        return ($this->funciones - $this->sinDatos) * $this->costoFuncion;
    }

    /** Lo que se lleva la sala menos lo que le costó: negativo si perdió. */
    public function resultado(): int
    {
        return $this->sala - $this->costo();
    }
}
