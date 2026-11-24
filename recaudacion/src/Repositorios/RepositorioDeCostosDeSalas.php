<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Recaudacion\Modelo\Monto;

/**
 * El costo por función que cargó cada sala (EF-22.1), en la tabla costo_sala.
 */
final class RepositorioDeCostosDeSalas
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * @param list<string> $salasIds
     *
     * @return array<string, int> sala => centavos por función; las que no cargaron no vienen
     */
    public function deLasSalas(array $salasIds): array
    {
        if ($salasIds === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($salasIds), '?'));
        $costos = [];
        foreach ($this->base->filas("SELECT sala_id, costo_funcion FROM costo_sala WHERE sala_id IN ({$marcas})", $salasIds) as $fila) {
            $costos[(string) $fila['sala_id']] = Monto::centavos((string) $fila['costo_funcion']);
        }

        return $costos;
    }

    /** Lo carga o lo reemplaza: la sala tiene un solo costo, el último. */
    public function guardar(string $salaId, int $centavos, string $autorId, DateTimeImmutable $cuando): void
    {
        $this->base->ejecutar(
            // Con el alias de la fila nueva, como en las liquidaciones: VALUES() quedó deprecado.
            'INSERT INTO costo_sala (sala_id, costo_funcion, cargado_por, cargado_en) VALUES (?, ?, ?, ?) AS nueva
             ON DUPLICATE KEY UPDATE costo_funcion = nueva.costo_funcion, cargado_por = nueva.cargado_por, cargado_en = nueva.cargado_en',
            [$salaId, Monto::comoDecimal($centavos), $autorId, $cuando],
        );
    }
}
