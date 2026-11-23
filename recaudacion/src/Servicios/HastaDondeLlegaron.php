<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Recaudacion\Modelo\Monto;
use LogicException;
use RuntimeException;

/**
 * Hasta dónde llegó cada espectador en cada función: reservó, pagó, fue o calificó, y cuánto. Lo
 * sabe funciones (GET /interno/embudo), que manda también las reservas que se cayeron sin pagar.
 * Lo usan la conversión (EF-22.13) y el ranking de espectadores por sala (EF-22.19).
 */
final class HastaDondeLlegaron
{
    /** Lo más que acepta funciones en un pedido. */
    private const MAXIMO_POR_PEDIDO = 100;

    /** Hasta qué paso llegó: 1 reservó, 2 pagó, 3 fue y 4 calificó. */
    public const PASO = ['reservo' => 1, 'pago' => 2, 'fue' => 3, 'califico' => 4];

    public function __construct(private readonly ClienteInterno $cliente)
    {
    }

    /**
     * @param list<string> $funcionesIds
     *
     * @return array<string, array<string, array{paso: int, centavos: int}>> por función y usuario:
     *                                                                       las funciones sin
     *                                                                       reservas no vienen
     *
     * @throws ExcepcionHttp|RuntimeException|LogicException si funciones no responde, responde otra
     *                                                       cosa o falta su dirección en config.ini
     */
    public function enLasFunciones(array $funcionesIds): array
    {
        $llegadas = [];
        foreach (array_chunk($funcionesIds, self::MAXIMO_POR_PEDIDO) as $tanda) {
            $respuesta = $this->cliente->get('funciones', '/interno/embudo', ['funciones' => implode(',', $tanda)]);
            $porFuncion = is_array($respuesta) ? ($respuesta['funciones'] ?? null) : null;
            if (!is_array($porFuncion)) {
                throw new RuntimeException('funciones no respondió el embudo a GET /interno/embudo.');
            }
            foreach ($porFuncion as $funcionId => $filas) {
                if (!is_array($filas)) {
                    throw new RuntimeException('funciones respondió un embudo que no se entiende.');
                }
                foreach ($filas as $fila) {
                    if (!is_array($fila) || !is_string($fila['usuario_id'] ?? null) || !isset(self::PASO[$fila['llego_a'] ?? ''])
                        || !is_string($fila['total'] ?? null) || preg_match('/^\d+\.\d{2}$/D', $fila['total']) !== 1) {
                        throw new RuntimeException('funciones respondió un embudo que no se entiende.');
                    }
                    $llegadas[(string) $funcionId][$fila['usuario_id']] = ['paso' => self::PASO[$fila['llego_a']], 'centavos' => Monto::centavos($fila['total'])];
                }
            }
        }

        return $llegadas;
    }
}
