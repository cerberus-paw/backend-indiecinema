<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Dobles;

/**
 * Las páginas que se pasan por el validador del W3C (E4-10.7): las pruebas que ya las dibujan las
 * guardan acá, y después el CI (o herramientas/validar-html.sh) las valida con vnu. Sólo si la
 * variable PAGINAS_PARA_VALIDAR dice en qué carpeta; sin ella no hace nada, así las pruebas
 * corren como siempre.
 */
final class PaginasParaValidar
{
    public static function guardar(string $nombre, string $html): void
    {
        $carpeta = getenv('PAGINAS_PARA_VALIDAR');
        if ($carpeta === false || $carpeta === '') {
            return;
        }
        if (!is_dir($carpeta) && !mkdir($carpeta, 0o777, true) && !is_dir($carpeta)) {
            throw new \RuntimeException("No se pudo crear {$carpeta}.");
        }
        file_put_contents("{$carpeta}/{$nombre}.html", $html);
    }
}
