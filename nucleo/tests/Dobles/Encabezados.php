<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Dobles;

use Dom\HTMLDocument;

/**
 * El orden de los encabezados de una página como lo recorre un lector de pantalla (E4-10.2): un
 * único h1, que va primero, y nunca un salto de más de un nivel hacia abajo (de h1 a h3). Volver a
 * subir sí se puede (de h3 a h2). Lo usan las pruebas de páginas de los subsistemas, cada una con
 * su lista de rutas.
 */
final class Encabezados
{
    /**
     * Los problemas de la página, uno por línea, con el texto del encabezado para encontrarlo; una
     * lista vacía si está bien.
     *
     * @return list<string>
     */
    public static function problemas(string $html): array
    {
        $documento = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        $encabezados = $documento->querySelectorAll('h1, h2, h3, h4, h5, h6');

        $problemas = [];
        $h1 = 0;
        $anterior = 0;
        foreach ($encabezados as $encabezado) {
            // Lo de un <template> no está en la página hasta que el JS lo copia.
            if ($encabezado->closest('template') !== null) {
                continue;
            }
            $nivel = (int) substr($encabezado->localName, 1);
            $texto = trim((string) preg_replace('/\s+/', ' ', (string) $encabezado->textContent));
            if ($nivel === 1) {
                ++$h1;
            }
            if ($nivel > $anterior + 1) {
                $problemas[] = $anterior === 0
                    ? "empieza con h{$nivel} «{$texto}», sin un h1 antes"
                    : "salta de h{$anterior} a h{$nivel} en «{$texto}»";
            }
            $anterior = $nivel;
        }
        if ($h1 !== 1) {
            $problemas[] = "tiene {$h1} h1 en vez de uno";
        }

        return $problemas;
    }
}
