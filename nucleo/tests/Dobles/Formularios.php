<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Dobles;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * Lo que un formulario le da al navegador y al lector de pantalla (E4-10.5): qué dato es cada
 * campo (autocomplete) y el resumen de errores que aparece arriba cuando el envío vuelve con
 * errores. Lo usan las pruebas de los formularios de los subsistemas.
 */
final class Formularios
{
    /**
     * El autocomplete de cada campo de la página, por su name; null si no lo tiene.
     *
     * @return array<string, ?string>
     */
    public static function autocompletado(string $html): array
    {
        $campos = [];
        foreach (self::documento($html)->querySelectorAll('input:not([type="hidden"]), select, textarea') as $campo) {
            $campos[(string) $campo->getAttribute('name')] = $campo->getAttribute('autocomplete');
        }

        return $campos;
    }

    /**
     * El resumen de errores de la página, o null si no hay: su título, un link por cada campo
     * (href => texto), lo que dice sin link, y si se puede enfocar y se anuncia.
     *
     * @return ?array{titulo: string, enlaces: array<string, string>, textos: list<string>, anunciado: bool}
     */
    public static function resumen(string $html): ?array
    {
        $resumen = self::documento($html)->querySelector('[data-resumen-de-errores]');
        if ($resumen === null) {
            return null;
        }

        $enlaces = [];
        foreach ($resumen->querySelectorAll('a') as $enlace) {
            $enlaces[(string) $enlace->getAttribute('href')] = self::texto($enlace);
        }
        $textos = [];
        foreach ($resumen->querySelectorAll('.resumen-de-errores-texto, li:not(:has(a))') as $texto) {
            $textos[] = self::texto($texto);
        }

        return [
            'titulo' => self::texto($resumen->querySelector('.alerta-titulo')),
            'enlaces' => $enlaces,
            'textos' => $textos,
            'anunciado' => $resumen->getAttribute('role') === 'alert' && $resumen->getAttribute('tabindex') === '-1',
        ];
    }

    private static function documento(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString($html, LIBXML_NOERROR);
    }

    private static function texto(?Element $elemento): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $elemento?->textContent));
    }
}
