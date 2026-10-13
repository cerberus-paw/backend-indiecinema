<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use Closure;
use DateTimeImmutable;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\Genero;

/**
 * Valida los parámetros de la URL de la cartelera, que cualquiera puede escribir a mano.
 *
 * Un filtro inválido no rompe la página: se deja afuera, la cartelera se muestra sin él y el
 * aviso dice por qué. La página es distinta: una que no es un número positivo no existe, como
 * la que pasa de la última, y la decide el controlador con un 404.
 *
 * Los nombres son los del formulario de programacion/cartelera en el paquete front. El género
 * viaja por su nombre, como la localidad, así la URL se lee y se puede escribir a mano; quién lo
 * busca en la base lo decide quien llama.
 */
final class ParametrosDeCartelera
{
    /** Como el título de una obra, que es lo más largo que tiene sentido buscar. */
    private const LARGO_TEXTO = 150;
    /** Como la columna sala.localidad. */
    private const LARGO_LOCALIDAD = 100;
    /** Como la columna genero.nombre. */
    private const LARGO_GENERO = 50;
    /** Hasta acá, una página más no cambia nada: sólo evita pasar un número enorme a la base. */
    private const PAGINA = '/^[1-9][0-9]{0,8}$/D';

    /** @var array<string, string> los filtros que se aplicaron, para el formulario y los links */
    public private(set) array $valores = [];

    /** @var list<string> qué filtro se dejó afuera y por qué */
    public private(set) array $avisos = [];

    /** El número de página, o null si no es uno válido. */
    public private(set) ?int $pagina = 1;

    public private(set) FiltrosDeCartelera $filtros;

    private function __construct()
    {
        $this->filtros = new FiltrosDeCartelera();
    }

    /**
     * @param array<string, mixed>     $consulta        los parámetros de la URL
     * @param Closure(string): ?Genero $generoPorNombre el género con ese nombre, o null si no hay
     */
    public static function desdeConsulta(array $consulta, Closure $generoPorNombre): self
    {
        $parametros = new self();
        $texto = $parametros->texto($consulta['q'] ?? null, 'La búsqueda', self::LARGO_TEXTO);
        $localidad = $parametros->texto($consulta['localidad'] ?? null, 'La localidad', self::LARGO_LOCALIDAD);
        $fecha = $parametros->fecha($consulta['fecha'] ?? null);
        $genero = $parametros->genero($consulta['genero'] ?? null, $generoPorNombre);
        $parametros->filtros = new FiltrosDeCartelera($texto, $localidad, $fecha, $genero?->id);
        // Lo que se aplicó, como quedó en los filtros (sin los espacios de alrededor), y el género
        // con su nombre como está guardado, así «comedia» en la URL vuelve como «Comedia».
        $parametros->valores = array_filter([
            'q' => $parametros->filtros->texto,
            'localidad' => $parametros->filtros->localidad,
            'fecha' => $parametros->filtros->fecha?->format('Y-m-d'),
            'genero' => $genero?->nombre,
        ], static fn (?string $valor): bool => $valor !== null);
        $parametros->pagina = self::pagina($consulta['pagina'] ?? null);

        return $parametros;
    }

    /**
     * La URL de otra página con los mismos filtros. La primera va sin el número, así es la misma
     * que se arma al filtrar.
     */
    public function urlDePagina(int $numero): string
    {
        return self::url('/cartelera', $this->valores + ($numero > 1 ? ['pagina' => $numero] : []));
    }

    /**
     * La URL del CSV con los mismos filtros. No lleva la página: el archivo las trae todas.
     */
    public function urlDeCsv(): string
    {
        return self::url('/cartelera.csv', $this->valores);
    }

    /**
     * @param array<string, int|string> $consulta
     */
    private static function url(string $ruta, array $consulta): string
    {
        $consulta = http_build_query($consulta, '', '&', PHP_QUERY_RFC3986);

        return $ruta . ($consulta === '' ? '' : "?{$consulta}");
    }

    private function texto(mixed $valor, string $etiqueta, int $maximo): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $problema = TextoDeLaUrl::problema($valor, $maximo);
        if ($problema !== null) {
            $this->avisos[] = "{$etiqueta} {$problema}: se muestra la cartelera sin ese filtro.";

            return null;
        }

        return $valor;
    }

    private function fecha(mixed $valor): ?DateTimeImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        // El formato del <input type="date">. createFromFormat() acepta un 31 de febrero (lo pasa
        // a marzo), así que además la fecha tiene que volver a escribirse igual.
        $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            $this->avisos[] = 'La fecha no es válida: se muestra la cartelera de todos los días.';

            return null;
        }

        return $fecha;
    }

    /**
     * @param Closure(string): ?Genero $generoPorNombre
     */
    private function genero(mixed $valor, Closure $generoPorNombre): ?Genero
    {
        // En blanco es no filtrar, como en los otros filtros.
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }
        // Un texto que no sirve no llega a la base. Uno que sirve pero no es el nombre de ningún
        // género (por ejemplo, un id, como en las URL de antes) tampoco filtra.
        $genero = TextoDeLaUrl::problema($valor, self::LARGO_GENERO) === null ? $generoPorNombre(trim($valor)) : null;
        if ($genero === null) {
            $this->avisos[] = 'El género no es válido: se muestra la cartelera de todos los géneros.';
        }

        return $genero;
    }

    private static function pagina(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return 1;
        }

        return is_string($valor) && preg_match(self::PAGINA, $valor) === 1 ? (int) $valor : null;
    }
}
