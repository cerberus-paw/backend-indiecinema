<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\FuncionParaProgramar;
use IndieCinema\Recaudacion\Modelo\Monto;

/**
 * El enlace del botón «Programarla» de cada recomendación (EF-22.17): abre el alta de función de
 * programación con todo cargado, y al organizador le alcanza con revisar y confirmar. El profe, el
 * 18/11: «precargás el formulario, le das enter»; y a todos: mostrar el efecto, no el formulario.
 *
 * Lo que la recomendación no dice lo completa con lo que la sala hizo en el período, que es lo
 * que su público ya conoce:
 *
 * - la fecha: el próximo día de la semana que dice la recomendación; si no dice, el día que más
 *   llena la sala;
 * - la hora: la más repetida de la sala ese día (dentro de la franja, si la recomendación la dice);
 * - el precio: el de siempre de la sala (o de esa obra en la sala), con el descuento de la
 *   promoción si es una;
 * - el cupo: el más repetido de la sala.
 *
 * Sólo el organizador de la sala recibe el botón: el administrador ve todas las salas, pero no
 * programa en las ajenas, y programación igual lo rechazaría.
 */
final class Precargas
{
    /** Lo que pide el alta de programación; se sirve en la raíz. */
    private const ALTA = '/organizador/funciones/nueva';

    /** @var array<string, list<FuncionFinalizada>> por sala */
    private readonly array $porSala;

    public function __construct(
        private readonly DatosDelReporte $datos,
        private readonly string $usuarioId,
        private readonly int $franjaCorteHora,
        private readonly DateTimeImmutable $hoy = new DateTimeImmutable('today'),
    ) {
        $porSala = [];
        foreach ($datos->funciones as $funcion) {
            $porSala[$funcion->salaId][] = $funcion;
        }
        $this->porSala = $porSala;
    }

    /** El enlace al alta cargada, o null si la sala no es de quien mira el reporte. */
    public function url(?FuncionParaProgramar $funcion): ?string
    {
        $funciones = $funcion === null ? [] : ($this->porSala[$funcion->salaId] ?? []);
        if ($funcion === null || $funciones === [] || $funciones[0]->organizadorId !== $this->usuarioId) {
            return null;
        }

        $dia = $funcion->dia ?? $this->elDiaQueMasLlena($funciones);
        $centavos = $funcion->precio ?? $this->precioDeSiempre($funciones, $funcion->obrasIds);
        if ($funcion->descuento > 0) {
            $centavos = self::conDescuento($centavos, $funcion->descuento);
        }

        return self::ALTA . '?' . http_build_query([
            'modo' => 'programada',
            'sala_id' => $funcion->salaId,
            'obras' => $funcion->obrasIds,
            'fecha' => $this->proximo($dia)->format('Y-m-d'),
            'hora' => $this->horaDeSiempre($funciones, $dia, $funcion->franja),
            'precio' => intdiv($centavos, 100) . ($centavos % 100 === 0 ? '' : sprintf('.%02d', $centavos % 100)),
            'cupo' => (string) self::laMasRepetida(array_map(static fn (FuncionFinalizada $f): int => $f->cupo, $funciones)),
            'desde' => 'reporte',
        ]);
    }

    /** El próximo día de la semana que se pide, después de hoy: mañana como muy pronto. */
    private function proximo(int $dia): DateTimeImmutable
    {
        $faltan = ($dia - (int) $this->hoy->format('N') + 7) % 7;

        return $this->hoy->modify('+' . ($faltan === 0 ? 7 : $faltan) . ' days');
    }

    /**
     * @param list<FuncionFinalizada> $funciones
     */
    private function elDiaQueMasLlena(array $funciones): int
    {
        $porDia = [];
        foreach ($funciones as $funcion) {
            $ventas = $this->datos->ventasDe($funcion->id);
            $dia = (int) $funcion->fechaHora->format('N');
            [$vendidas, $cupo] = $porDia[$dia] ?? [0, 0];
            $porDia[$dia] = [$vendidas + ($ventas === null ? 0 : $ventas->vendidas), $cupo + $funcion->cupo];
        }
        $mejor = array_key_first($porDia);
        foreach ($porDia as $dia => [$vendidas, $cupo]) {
            // En cruz, en enteros: el que vendió más en proporción a su cupo.
            if ($vendidas * $porDia[$mejor][1] > $porDia[$mejor][0] * $cupo) {
                $mejor = $dia;
            }
        }

        return (int) $mejor;
    }

    /**
     * La hora más repetida de la sala ese día y en esa franja; si ese día no tuvo, la de la franja
     * en cualquier día; y si tampoco, la de la sala.
     *
     * @param list<FuncionFinalizada> $funciones
     */
    private function horaDeSiempre(array $funciones, int $dia, ?string $franja): string
    {
        $enLaFranja = array_values(array_filter($funciones, fn (FuncionFinalizada $f): bool => $franja === null
            || ($franja === 'noche') === ((int) $f->fechaHora->format('G') >= $this->franjaCorteHora)));
        $ese = array_values(array_filter($enLaFranja, static fn (FuncionFinalizada $f): bool => (int) $f->fechaHora->format('N') === $dia));
        $candidatas = $ese !== [] ? $ese : $enLaFranja;
        if ($candidatas === []) {
            // Ninguna función de la sala en esa franja: la primera hora de la franja.
            return $franja === 'noche' ? sprintf('%02d:00', $this->franjaCorteHora) : sprintf('%02d:00', max(0, $this->franjaCorteHora - 2));
        }

        return (string) self::laMasRepetida(array_map(static fn (FuncionFinalizada $f): string => $f->fechaHora->format('H:i'), $candidatas));
    }

    /**
     * El precio más repetido de las funciones con precio de la sala: de las de esa obra si las
     * hubo, que es lo que el público ya pagó por verla. 0 si la sala sólo dio a la gorra.
     *
     * @param list<FuncionFinalizada> $funciones
     * @param list<string>            $obrasIds
     */
    private function precioDeSiempre(array $funciones, array $obrasIds): int
    {
        $conPrecio = array_values(array_filter($funciones, static fn (FuncionFinalizada $f): bool => !$f->esALaGorra()));
        $deLaObra = array_values(array_filter($conPrecio, static function (FuncionFinalizada $f) use ($obrasIds): bool {
            foreach ($f->elegidas() as $obra) {
                if (in_array($obra->id, $obrasIds, true)) {
                    return true;
                }
            }

            return false;
        }));
        $candidatas = $deLaObra !== [] ? $deLaObra : $conPrecio;

        return $candidatas === [] ? 0 : (int) self::laMasRepetida(array_map(static fn (FuncionFinalizada $f): int => Monto::centavos($f->precioBase), $candidatas));
    }

    /** Con el descuento, redondeado hacia abajo a $ 100 (si queda algo): un precio que se pueda decir. */
    private static function conDescuento(int $centavos, int $descuento): int
    {
        $conDescuento = intdiv($centavos * (100 - $descuento), 100);
        $redondo = intdiv($conDescuento, 10000) * 10000;

        return $redondo > 0 ? $redondo : $conDescuento;
    }

    /**
     * El valor que más se repite; ante un empate, el primero que apareció.
     *
     * @template T of int|string
     *
     * @param non-empty-list<T> $valores
     *
     * @return T
     */
    private static function laMasRepetida(array $valores): int|string
    {
        $cuantas = array_count_values($valores);
        $mejor = $valores[0];
        foreach ($cuantas as $valor => $cuantasVeces) {
            if ($cuantasVeces > $cuantas[$mejor]) {
                $mejor = $valor;
            }
        }

        return $mejor;
    }
}
