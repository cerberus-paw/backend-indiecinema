<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Controladores;

use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\ObraVotada;
use IndieCinema\Recaudacion\Servicios\TotalesDelPeriodo;

/**
 * Las funciones de un reporte en CSV (EF-09.2), con el formato del CSV de reservas de funciones
 * (CsvDeReservas): UTF-8 con BOM, para que Excel lea las tildes; punto y coma, que es el
 * separador de una planilla en castellano; y CRLF, como pide RFC 4180. Los números van con coma
 * decimal y sin separador de miles, así la planilla los suma.
 */
final class CsvDeFunciones
{
    private const BOM = "\u{FEFF}";
    private const SEPARADOR = ';';
    private const ENCABEZADOS = ['Fecha', 'Hora', 'Sala', 'Obras', 'Lugares', 'Vendidas', 'Ocupación (%)', 'Asistentes', 'Cobrado', 'Promedio', 'Calificaciones'];

    /**
     * Si una celda empieza con uno de estos caracteres, una planilla la interpreta como fórmula al
     * abrir el archivo. Adelante va un apóstrofo para que quede como texto.
     */
    private const FORMULAS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param list<FuncionFinalizada> $funciones
     */
    public static function armar(DatosDelReporte $datos, array $funciones): string
    {
        $archivo = fopen('php://memory', 'w+');
        self::fila($archivo, self::ENCABEZADOS);
        foreach ($funciones as $funcion) {
            $total = TotalesDelPeriodo::deUna($datos, $funcion);
            $conVentas = $total->sinVentas === 0;
            self::fila($archivo, [
                $funcion->fechaHora->format('Y-m-d'),
                $funcion->fechaHora->format('H:i'),
                // El nombre de la sala y los títulos los cargan personas: pueden empezar como una fórmula.
                self::sinFormulas($funcion->salaNombre),
                self::sinFormulas(implode(' + ', array_map(static fn (ObraVotada $obra): string => $obra->titulo, $funcion->elegidas()))),
                (string) $funcion->cupo,
                $conVentas ? (string) $total->vendidas : '',
                $conVentas ? (string) $total->ocupacion() : '',
                $conVentas ? (string) $total->asistentes : '',
                $conVentas ? str_replace('.', ',', $total->cobrado()) : '',
                $total->promedio() === null ? '' : str_replace('.', ',', sprintf('%.1f', $total->promedio())),
                (string) $total->calificaciones,
            ]);
        }
        rewind($archivo);
        $csv = (string) stream_get_contents($archivo);
        fclose($archivo);

        return self::BOM . $csv;
    }

    private static function sinFormulas(string $valor): string
    {
        return $valor !== '' && in_array($valor[0], self::FORMULAS, true) ? "'{$valor}" : $valor;
    }

    /**
     * @param resource     $archivo
     * @param list<string> $campos
     */
    private static function fila($archivo, array $campos): void
    {
        // Sin carácter de escape: el único escape de RFC 4180 es duplicar las comillas.
        fputcsv($archivo, $campos, self::SEPARADOR, '"', '', "\r\n");
    }
}
