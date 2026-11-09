<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use DateTimeZone;
use IndieCinema\Funciones\Modelo\Reserva;

/**
 * Las reservas de una función en CSV, para que el organizador las abra en una planilla (E4-03.5):
 * el mismo formato que el CSV de la cartelera de programación (CsvDeCartelera). UTF-8 con BOM,
 * porque si no Excel lee las tildes mal; punto y coma, que es el separador que espera una planilla
 * en castellano; y CRLF, como pide RFC 4180.
 */
final class CsvDeReservas
{
    private const BOM = "\u{FEFF}";
    private const SEPARADOR = ';';
    private const ENCABEZADOS = ['Código', 'Espectador', 'Lugares', 'Total', 'Estado', 'Reservada el', 'Hora'];

    /**
     * Si una celda empieza con uno de estos caracteres, una planilla (Excel, Calc, Google Sheets) la
     * interpreta como fórmula al abrir el archivo. Adelante va un apóstrofo para que quede como texto.
     */
    private const FORMULAS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param list<Reserva>         $reservas
     * @param DateTimeZone          $zona     la de la sala: la base guarda en UTC, y el organizador lee su hora
     * @param array<string, string> $nombres  usuario_id => nombre del espectador
     */
    public static function armar(array $reservas, DateTimeZone $zona, array $nombres = []): string
    {
        $archivo = fopen('php://memory', 'w+');
        self::fila($archivo, self::ENCABEZADOS);
        foreach ($reservas as $reserva) {
            $creada = $reserva->creadaEn->setTimezone($zona);
            self::fila($archivo, [
                $reserva->codigo,
                self::sinFormulas($nombres[$reserva->usuarioId] ?? ''),
                (string) $reserva->cantidad,
                // Un número que la planilla puede sumar: coma decimal y sin separador de miles.
                str_replace('.', ',', $reserva->total),
                $reserva->estado->etiqueta(),
                // Así las reconoce como fecha y hora cualquier planilla, en cualquier idioma.
                $creada->format('Y-m-d'),
                $creada->format('H:i'),
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
     * Salvo el nombre del espectador, que carga una persona al registrarse y se escapa con
     * sinFormulas(), todos los campos los arma el sistema: no hay texto que pueda empezar como una
     * fórmula de la planilla.
     *
     * @param resource     $archivo
     * @param list<string> $campos
     */
    private static function fila($archivo, array $campos): void
    {
        // Sin carácter de escape: el único escape de RFC 4180 es duplicar las comillas.
        fputcsv($archivo, $campos, self::SEPARADOR, '"', '', "\r\n");
    }
}
