<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Programacion\Modelo\ResumenDeFuncion;

/**
 * Las calificaciones que manda funciones, como las reciben las fichas de la sala y de la obra
 * (EF-04.3), y el historial de proyecciones de una sala.
 */
final class CalificacionesParaMostrar
{
    /**
     * Lo que va debajo del nombre y la sección «Lo que dicen los espectadores», o null si
     * funciones no respondió: la ficha lo avisa en lugar de decir que no hay calificaciones.
     *
     * @param ?array{promedio: float, cantidad: int}                                                                        $resumen el de la sala o la obra; null si no tiene
     * @param ?list<array{funcion_id: string, puntaje: int, comentario: ?string, espectador: ?string, fecha: string}> $ultimas
     *
     * @return ?array{promedio: ?float, cantidad: int, ultimas: list<array{puntaje: int, comentario: ?string, espectador: string, fecha: string}>}
     */
    public static function datos(bool $respondio, ?array $resumen, ?array $ultimas): ?array
    {
        if (!$respondio) {
            return null;
        }

        return [
            'promedio' => $resumen['promedio'] ?? null,
            'cantidad' => $resumen['cantidad'] ?? 0,
            'ultimas' => array_map(static fn (array $calificacion): array => [
                'puntaje' => $calificacion['puntaje'],
                'comentario' => $calificacion['comentario'],
                // Si cuentas no respondió, funciones la manda sin el nombre.
                'espectador' => $calificacion['espectador'] ?? 'Un espectador',
                'fecha' => self::fecha(new DateTimeImmutable($calificacion['fecha'])),
            ], $ultimas ?? []),
        ];
    }

    /**
     * El historial de proyecciones de la sala, como la tabla del wireframe «sala-indie»: la fecha,
     * lo que se dio, cuántos entraron de cuántos y el promedio. Sin funciones, los dos últimos van
     * en null.
     *
     * @param list<ResumenDeFuncion>                                                              $funciones
     * @param ?array{funciones: array<string, array{promedio: float, cantidad: int}>, asistentes: array<string, int>} $resumen
     *
     * @return list<array{fecha: string, titulo: string, url: string, asistentes: ?int, cupo: int, promedio: ?float}>
     */
    public static function historial(array $funciones, ?array $resumen): array
    {
        return array_map(static fn (ResumenDeFuncion $funcion): array => [
            'fecha' => $funcion->fechaHora === null ? '' : self::fecha($funcion->fechaHora),
            'titulo' => implode(' + ', $funcion->titulos),
            'url' => "/cartelera/{$funcion->id}",
            'asistentes' => $resumen === null ? null : ($resumen['asistentes'][$funcion->id] ?? 0),
            'cupo' => $funcion->cupo,
            'promedio' => $resumen['funciones'][$funcion->id]['promedio'] ?? null,
        ], $funciones);
    }

    /** «06 sep 2026», como el historial del wireframe. */
    private static function fecha(DateTimeImmutable $fecha): string
    {
        return FechaParaMostrar::diaCorto($fecha) . ' ' . $fecha->format('Y');
    }
}
