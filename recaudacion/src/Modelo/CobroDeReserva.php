<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Lo que se cobró de una reserva, como lo cuenta funciones (EF-02.5). Recaudación no lee la base
 * de funciones ni tiene la credencial de Mercado Pago: reparte con esto.
 */
final readonly class CobroDeReserva
{
    /**
     * @param string  $total   el de la reserva, con dos decimales, como lo guarda la base
     * @param string  $cobrado lo que pagó de verdad: 0.00 a la gorra o con el cobro apagado
     * @param ?string $pagoId  el pago de Mercado Pago, o null si no hubo
     */
    public function __construct(
        public string $id,
        public string $usuarioId,
        public int $cantidad,
        public string $total,
        public string $cobrado,
        public ?string $pagoId,
        public string $estado,
        public bool $reembolsada,
    ) {
    }
}
