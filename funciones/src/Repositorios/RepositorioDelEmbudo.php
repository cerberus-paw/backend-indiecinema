<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Repositorios;

use IndieCinema\Nucleo\BaseDeDatos;

/**
 * Hasta dónde llegó cada espectador en cada función, para el embudo de conversión de los reportes
 * (EF-22.13): reservó, pagó, fue y calificó. A diferencia de los cobros (/interno/cobros), que sólo
 * mandan las reservas que cuentan para el reparto, acá van también las que se cancelaron sin pagar:
 * son justamente las que se cayeron entre reservar y pagar.
 */
final class RepositorioDelEmbudo
{
    /** Cómo se llama cada paso, del primero al último. */
    public const PASOS = ['reservo', 'pago', 'fue', 'califico'];

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * @param list<string> $funcionesIds
     *
     * @return array<string, list<array{usuario_id: string, llego_a: string, total: string}>> por
     *         función, una fila por espectador; las funciones sin reservas no vienen
     */
    public function deLasFunciones(array $funcionesIds): array
    {
        if ($funcionesIds === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($funcionesIds), '?'));

        /** @var array<string, array<string, array{paso: int, centavos: int, centavos_en_pie: int}>> $porFuncion */
        $porFuncion = [];
        foreach ($this->base->filas(
            "SELECT funcion_id, usuario_id, estado, total FROM reserva WHERE funcion_id IN ({$marcas})",
            $funcionesIds,
        ) as $fila) {
            $funcion = (string) $fila['funcion_id'];
            $usuario = (string) $fila['usuario_id'];
            $centavos = (int) round((float) $fila['total'] * 100);
            // Pagó quien tiene una reserva en pie: pendiente de pago no llegó, y cancelada se cayó
            // (o se devolvió). A la gorra, o con el cobro apagado, nace confirmada: cuenta igual,
            // porque el paso es «se comprometió», con plata o sin ella.
            $paso = match ((string) $fila['estado']) {
                'asistio' => 2,
                'confirmada', 'ausente' => 1,
                default => 0,
            };
            $actual = $porFuncion[$funcion][$usuario] ?? ['paso' => 0, 'centavos' => 0, 'centavos_en_pie' => 0];
            $porFuncion[$funcion][$usuario] = [
                'paso' => max($actual['paso'], $paso),
                // Lo que quiso comprar: la reserva más grande, aunque haya reintentado varias veces.
                'centavos' => max($actual['centavos'], $centavos),
                'centavos_en_pie' => $actual['centavos_en_pie'] + ($paso > 0 ? $centavos : 0),
            ];
        }
        if ($porFuncion === []) {
            return [];
        }

        foreach ($this->base->filas(
            "SELECT funcion_id, usuario_id FROM calificacion WHERE funcion_id IN ({$marcas})",
            $funcionesIds,
        ) as $fila) {
            $funcion = (string) $fila['funcion_id'];
            $usuario = (string) $fila['usuario_id'];
            // Sólo califica quien fue (lo controla la reserva): igual se mira, por si acaso.
            if (($porFuncion[$funcion][$usuario]['paso'] ?? null) === 2) {
                $porFuncion[$funcion][$usuario]['paso'] = 3;
            }
        }

        $resultado = [];
        foreach ($porFuncion as $funcion => $usuarios) {
            foreach ($usuarios as $usuario => $datos) {
                // Desde que pagó, lo que pagó; si no llegó, lo que quiso comprar.
                $centavos = $datos['paso'] > 0 ? $datos['centavos_en_pie'] : $datos['centavos'];
                $resultado[$funcion][] = [
                    'usuario_id' => $usuario,
                    'llego_a' => self::PASOS[$datos['paso']],
                    'total' => sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100),
                ];
            }
        }

        return $resultado;
    }
}
