<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Una sala que gana holgado en el período, así que el reporte no le sugiere promociones (EF-22.14).
 * El profe, el 18/11: «a una sala que vende muy por encima no hace falta darle promoción». Va con
 * los números que lo justifican, para mostrar por qué no aparece.
 */
final readonly class SalaSinPromocion
{
    /**
     * @param int $resultado  lo que se llevó menos lo que le costó, en centavos
     * @param int $costo      lo que le costó abrir sus funciones, en centavos
     * @param int $sobreCosto cuánto más que su costo se llevó, en porcentaje del costo
     */
    public function __construct(
        public string $salaId,
        public string $salaNombre,
        public int $resultado,
        public int $costo,
        public int $sobreCosto,
    ) {
    }
}
