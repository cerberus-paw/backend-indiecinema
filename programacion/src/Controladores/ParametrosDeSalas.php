<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

/**
 * Valida la búsqueda del listado de salas, que llega en la URL. Como en la cartelera, una que no
 * sirve no rompe la página: se muestran todas las salas y el aviso dice por qué.
 *
 * El nombre es el del formulario de programacion/salas en el paquete front.
 */
final class ParametrosDeSalas
{
    /** Como las columnas sala.nombre y sala.localidad, que es donde se busca. */
    private const LARGO_TEXTO = 100;

    /** Lo que se buscó, sin los espacios de alrededor, o null si no se busca nada. */
    public private(set) ?string $texto = null;

    /** @var list<string> */
    public private(set) array $avisos = [];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $consulta los parámetros de la URL
     */
    public static function desdeConsulta(array $consulta): self
    {
        $parametros = new self();
        $valor = $consulta['q'] ?? null;
        if ($valor === null || $valor === '') {
            return $parametros;
        }
        $problema = TextoDeLaUrl::problema($valor, self::LARGO_TEXTO);
        if ($problema !== null) {
            $parametros->avisos[] = "La búsqueda {$problema}: se muestran todas las salas.";

            return $parametros;
        }
        $texto = trim((string) $valor);
        $parametros->texto = $texto === '' ? null : $texto;

        return $parametros;
    }
}
