<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\ObraEnCartelera;

/**
 * La cartelera filtrada como CSV, para abrirla en una planilla.
 *
 * Está armado para Excel en español, que es el que menos adivina: UTF-8 con BOM (sin él, las
 * tildes salen mal), «;» como separador (la coma es la de los decimales) y fin de línea CRLF, con
 * las comillas de RFC 4180. LibreOffice y Google Sheets lo abren igual.
 */
final class CsvDeCartelera
{
    private const BOM = "\u{FEFF}";
    private const SEPARADOR = ';';
    private const ENCABEZADOS = ['Fecha', 'Hora', 'Obras', 'Sala', 'Localidad', 'Precio'];
    /** Con estos, una planilla lee el campo como una fórmula y la ejecuta al abrir el archivo. */
    private const INICIO_DE_FORMULA = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param list<FuncionEnCartelera> $funciones
     */
    public static function armar(array $funciones): string
    {
        $archivo = fopen('php://memory', 'w+');
        self::fila($archivo, self::ENCABEZADOS);
        foreach ($funciones as $funcion) {
            self::fila($archivo, [
                // Así las reconoce como fecha y hora cualquier planilla, en cualquier idioma.
                $funcion->fechaHora->format('Y-m-d'),
                $funcion->fechaHora->format('H:i'),
                implode(' + ', array_map(static fn (ObraEnCartelera $obra): string => $obra->titulo, $funcion->obras)),
                $funcion->sala,
                $funcion->localidad,
                self::precio($funcion->precioBase),
            ]);
        }
        rewind($archivo);
        $csv = (string) stream_get_contents($archivo);
        fclose($archivo);

        return self::BOM . $csv;
    }

    /**
     * Un número que la planilla puede sumar y ordenar, sin «$» ni separador de miles; 0 es «a la
     * gorra», como en la tarjeta.
     */
    public static function precio(float $precio): string
    {
        if ($precio == 0) {
            return 'A la gorra';
        }

        return number_format($precio, fmod($precio, 1.0) == 0 ? 0 : 2, ',', '');
    }

    /**
     * Los títulos, las salas y las localidades los escriben los organizadores: uno que empiece
     * como una fórmula va con un apóstrofo adelante, así la planilla lo muestra como texto.
     */
    public static function sinFormulas(string $campo): string
    {
        return in_array(substr($campo, 0, 1), self::INICIO_DE_FORMULA, true) ? "'{$campo}" : $campo;
    }

    /**
     * @param resource     $archivo
     * @param list<string> $campos
     */
    private static function fila($archivo, array $campos): void
    {
        // Sin carácter de escape: el único escape de RFC 4180 es duplicar las comillas.
        fputcsv($archivo, array_map(self::sinFormulas(...), $campos), self::SEPARADOR, '"', '', "\r\n");
    }
}
