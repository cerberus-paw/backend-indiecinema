<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Cuánto llena una sala a la gorra contra cuánto llena con precio (EF-09.5): dice qué promoción
 * conviene, no sólo cuándo. Si a la gorra llena mucho más, el público es sensible al precio y un
 * descuento puede traerlo; si con precio llena lo mismo, no hace falta regalar la entrada.
 */
final readonly class PrecioDeLaEntrada
{
    /**
     * @param int  $ocupacionGorra  el porcentaje de los lugares vendidos en las funciones a la gorra
     * @param int  $ocupacionPagas  el de las funciones con precio
     * @param bool $sensible        true si a la gorra llena bastante más (un descuento puede servir);
     *                              false si con precio llena igual o más (se puede cobrar)
     */
    public function __construct(
        public string $salaId,
        public string $salaNombre,
        public int $funcionesGorra,
        public int $ocupacionGorra,
        public int $funcionesPagas,
        public int $ocupacionPagas,
        public bool $sensible,
    ) {
    }
}
