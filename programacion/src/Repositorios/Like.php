<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

/**
 * El patrón para buscar un texto del visitante con LIKE, siempre con «ESCAPE '!'». Lo comparten
 * la cartelera y el listado de salas.
 */
final class Like
{
    /**
     * El patrón de «contiene» para el texto, tal cual: sin esto, un «%» o un «_» del visitante
     * serían comodines. El escape es «!» y no la barra, que cambia de significado con el modo
     * NO_BACKSLASH_ESCAPES de MySQL.
     */
    public static function contiene(string $texto): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto) . '%';
    }
}
