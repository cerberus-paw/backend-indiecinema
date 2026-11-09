<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Dobles;

use IndieCinema\Nucleo\Http\Respuesta;

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

    /**
     * Guarda la página de cualquier respuesta en HTML que se dibuje entera (200, o 422 con los
     * errores del formulario), y la devuelve como vino. Va en el helper que usan las pruebas para
     * pedir, así se validan todas las variantes que ya prueban —con sesión de espectador, de
     * organizador o de administrador (F-01.8)— sin elegirlas a mano. El nombre sale de la ruta,
     * sin los ids, más un pedazo del hash del cuerpo: dos estados de la misma página no se pisan.
     */
    public static function anotar(string $metodo, string $url, Respuesta $respuesta): Respuesta
    {
        $tipo = (string) $respuesta->cabecera('Content-Type');
        if (!in_array($respuesta->estado(), [200, 422], true) || !str_starts_with($tipo, 'text/html')) {
            return $respuesta;
        }
        $ruta = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', 'id', $url) ?? $url;
        $nombre = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ruta)), '-');
        $nombre = ($metodo === 'GET' ? '' : strtolower($metodo) . '-') . ($nombre === '' ? 'inicio' : substr($nombre, 0, 80));
        self::guardar("{$nombre}-{$respuesta->estado()}-" . substr(md5($respuesta->cuerpo()), 0, 8), $respuesta->cuerpo());

        return $respuesta;
    }
}
