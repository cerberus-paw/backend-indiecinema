<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use Closure;
use IndieCinema\Programacion\Modelo\Genero;

/**
 * Valida los parámetros de búsqueda del catálogo público de obras (texto y género).
 */
final class ParametrosDeObras
{
    private const LARGO_TEXTO = 150;
    private const LARGO_GENERO = 50;

    public private(set) ?string $texto = null;

    public private(set) ?Genero $genero = null;

    /** @var array<string, string> */
    public private(set) array $valores = [];

    /** @var list<string> */
    public private(set) array $avisos = [];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>     $consulta        los parámetros de la URL
     * @param Closure(string): ?Genero $generoPorNombre función para buscar el género
     */
    public static function desdeConsulta(array $consulta, Closure $generoPorNombre): self
    {
        $parametros = new self();

        // 1. Texto de búsqueda (título o director)
        $valorTexto = $consulta['q'] ?? null;
        if ($valorTexto !== null && $valorTexto !== '') {
            $problemaTexto = TextoDeLaUrl::problema($valorTexto, self::LARGO_TEXTO);
            if ($problemaTexto !== null) {
                $parametros->avisos[] = "La búsqueda {$problemaTexto}: se muestra el catálogo sin ese filtro.";
            } else {
                $limpio = trim((string) $valorTexto);
                if ($limpio !== '') {
                    $parametros->texto = $limpio;
                    $parametros->valores['q'] = $limpio;
                }
            }
        }

        // 2. Género
        $valorGenero = $consulta['genero'] ?? null;
        if ($valorGenero !== null && (is_string($valorGenero) && trim($valorGenero) !== '')) {
            $problemaGenero = TextoDeLaUrl::problema($valorGenero, self::LARGO_GENERO);
            $generoEncontrado = $problemaGenero === null ? $generoPorNombre(trim($valorGenero)) : null;
            if ($generoEncontrado === null) {
                $parametros->avisos[] = 'El género no es válido: se muestra el catálogo de todos los géneros.';
            } else {
                $parametros->genero = $generoEncontrado;
                $parametros->valores['genero'] = $generoEncontrado->nombre;
            }
        }

        return $parametros;
    }
}
