<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Lo que se vendió de una función, sacado de sus cobros (las reservas que no se cancelaron).
 */
final readonly class VentasDeFuncion
{
    /**
     * @param int          $vendidas       los lugares de las reservas en pie
     * @param int          $asistentes     los lugares de las que pasaron por la puerta
     * @param string       $cobrado        el neto, con dos decimales
     * @param list<string> $compradoresIds quienes reservaron, sin repetir
     */
    public function __construct(
        public int $vendidas,
        public int $asistentes,
        public string $cobrado,
        public array $compradoresIds,
    ) {
    }

    public static function deLosCobros(CobrosDeFuncion $cobros): self
    {
        $vendidas = 0;
        $asistentes = 0;
        $compradores = [];
        foreach ($cobros->reservas as $reserva) {
            $vendidas += $reserva->cantidad;
            if ($reserva->estado === 'asistio') {
                $asistentes += $reserva->cantidad;
            }
            $compradores[$reserva->usuarioId] = true;
        }

        return new self($vendidas, $asistentes, $cobros->neto, array_keys($compradores));
    }
}
