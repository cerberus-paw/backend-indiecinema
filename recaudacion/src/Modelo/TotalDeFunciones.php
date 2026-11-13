<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Lo sumado de un grupo de funciones (un mes, o el período entero) para el reporte de totales
 * (EF-09.2). La plata va en centavos enteros, como en CobrosInternos de funciones: sumar los
 * montos como float arrastraría redondeos a los totales.
 */
final class TotalDeFunciones
{
    public int $funciones = 0;
    public int $cupo = 0;
    public int $vendidas = 0;
    public int $asistentes = 0;
    public int $cobradoCentavos = 0;
    public float $puntos = 0.0;
    public int $calificaciones = 0;

    /** Las funciones que se sumaron sin lo de funciones, porque no respondió. */
    public int $sinVentas = 0;

    public function sumar(FuncionFinalizada $funcion, ?VentasDeFuncion $ventas, ?CalificacionDeFuncion $calificacion): void
    {
        ++$this->funciones;
        if ($ventas === null) {
            ++$this->sinVentas;

            return;
        }
        $this->cupo += $funcion->cupo;
        $this->vendidas += $ventas->vendidas;
        $this->asistentes += $ventas->asistentes;
        $this->cobradoCentavos += self::centavos($ventas->cobrado);
        if ($calificacion !== null) {
            $this->puntos += $calificacion->promedio * $calificacion->cantidad;
            $this->calificaciones += $calificacion->cantidad;
        }
    }

    /** Porcentaje de los lugares vendidos, redondeado; null sin lugares contados. */
    public function ocupacion(): ?int
    {
        return $this->cupo === 0 ? null : self::porcentaje($this->vendidas, $this->cupo);
    }

    /** Porcentaje de los que reservaron que fueron; null si nadie reservó. */
    public function asistencia(): ?int
    {
        return $this->vendidas === 0 ? null : self::porcentaje($this->asistentes, $this->vendidas);
    }

    /** El promedio de todas las calificaciones, ponderado por cuántas tuvo cada función. */
    public function promedio(): ?float
    {
        return $this->calificaciones === 0 ? null : round($this->puntos / $this->calificaciones, 1);
    }

    /** «154000.00», con dos decimales como la base. */
    public function cobrado(): string
    {
        return sprintf('%d.%02d', intdiv($this->cobradoCentavos, 100), $this->cobradoCentavos % 100);
    }

    /** «3500.00» → 350000. */
    public static function centavos(string $monto): int
    {
        [$enteros, $decimales] = explode('.', $monto . '.00');

        return (int) $enteros * 100 + (int) substr(str_pad($decimales, 2, '0'), 0, 2);
    }

    private static function porcentaje(int $parte, int $total): int
    {
        return intdiv(200 * $parte + $total, 2 * $total);
    }
}
