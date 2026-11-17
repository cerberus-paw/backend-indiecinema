<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\TotalDeFunciones;

/**
 * Los totales por función y por mes del reporte (EF-09.2): lo que se vendió, cuántos fueron, lo
 * cobrado y las calificaciones, sumados por mes y en el período. Los meses van por la fecha de la
 * función, en la hora de la sala.
 */
final class TotalesDelPeriodo
{
    /**
     * Las salas que tuvieron funciones en el período, para el filtro.
     *
     * @return array<string, string> id => nombre, por nombre
     */
    public static function salas(DatosDelReporte $datos): array
    {
        $salas = [];
        foreach ($datos->funciones as $funcion) {
            $salas[$funcion->salaId] = $funcion->salaNombre;
        }
        asort($salas);

        return $salas;
    }

    /**
     * @return list<FuncionFinalizada> las de una sala, o todas con null
     */
    public static function deLaSala(DatosDelReporte $datos, ?string $salaId): array
    {
        return $salaId === null ? $datos->funciones : array_values(array_filter($datos->funciones, static fn (FuncionFinalizada $funcion): bool => $funcion->salaId === $salaId));
    }

    /**
     * @param list<FuncionFinalizada> $funciones
     *
     * @return array<string, TotalDeFunciones> «AAAA-MM» => lo de ese mes, del primero al último
     */
    public static function porMes(DatosDelReporte $datos, array $funciones): array
    {
        $meses = [];
        foreach ($funciones as $funcion) {
            $mes = $funcion->fechaHora->format('Y-m');
            $meses[$mes] ??= new TotalDeFunciones();
            $meses[$mes]->sumar($funcion, $datos->ventasDe($funcion->id), $datos->calificacionDe($funcion->id));
        }
        ksort($meses);

        return $meses;
    }

    /**
     * @param list<FuncionFinalizada> $funciones
     */
    public static function total(DatosDelReporte $datos, array $funciones): TotalDeFunciones
    {
        $total = new TotalDeFunciones();
        foreach ($funciones as $funcion) {
            $total->sumar($funcion, $datos->ventasDe($funcion->id), $datos->calificacionDe($funcion->id));
        }

        return $total;
    }

    /**
     * Una función sola, con el mismo cálculo que un mes.
     */
    public static function deUna(DatosDelReporte $datos, FuncionFinalizada $funcion): TotalDeFunciones
    {
        return self::total($datos, [$funcion]);
    }
}
