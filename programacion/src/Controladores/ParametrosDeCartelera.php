<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use Closure;
use DateTimeImmutable;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\OrdenDeCartelera;

/**
 * Valida los parámetros de la URL de la cartelera, que cualquiera puede escribir a mano.
 *
 * Un filtro o un orden inválido no rompe la página: se deja afuera, la cartelera se muestra sin él
 * (u ordenada por fecha) y el aviso dice por qué. La página es distinta: una que no es un número
 * positivo no existe, como la que pasa de la última, y la decide el controlador con un 404.
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
    /** Hasta ocho cifras y dos decimales, incluido el 0: lo que entra en funcion.precio_base. */
    private const PRECIO = '/^[0-9]{1,8}([.,][0-9]{1,2})?$/D';
    /** Hasta acá, una página más no cambia nada: sólo evita pasar un número enorme a la base. */
    private const PAGINA = '/^[1-9][0-9]{0,8}$/D';

    /** @var array<string, string> los filtros y el orden que se aplicaron, para el formulario y los links */
    public private(set) array $valores = [];

    /** @var list<string> qué filtro u orden se dejó afuera y por qué */
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
        $precioMinimo = $parametros->precio($consulta['precio_min'] ?? null, 'El precio mínimo');
        $precioMaximo = $parametros->precio($consulta['precio_max'] ?? null, 'El precio máximo');
        if ($precioMinimo !== null && $precioMaximo !== null && $precioMinimo > $precioMaximo) {
            $parametros->avisos[] = 'El precio mínimo no puede ser mayor que el máximo: se muestra la cartelera de todos los precios.';
            $precioMinimo = null;
            $precioMaximo = null;
        }
        $orden = $parametros->orden($consulta['orden'] ?? null);
        $parametros->filtros = new FiltrosDeCartelera(
            $texto,
            $localidad,
            $fecha,
            $genero?->id,
            orden: $orden,
            precioMinimo: $precioMinimo,
            precioMaximo: $precioMaximo,
        );
        // Lo que se aplicó, como quedó en los filtros (sin los espacios de alrededor), y el género
        // con su nombre como está guardado, así «comedia» en la URL vuelve como «Comedia». El 0 de
        // un tope de precio sí viaja («precio_min=0» o «precio_max=0»), por eso se filtra sólo null.
        // El orden por defecto no viaja en los links ni en el formulario, como la página 1.
        $parametros->valores = array_filter([
            'q' => $parametros->filtros->texto,
            'localidad' => $parametros->filtros->localidad,
            'fecha' => $parametros->filtros->fecha?->format('Y-m-d'),
            'genero' => $genero?->nombre,
            'precio_min' => self::precioParaUrl($parametros->filtros->precioMinimo),
            'precio_max' => self::precioParaUrl($parametros->filtros->precioMaximo),
            'orden' => $orden !== OrdenDeCartelera::FechaAscendente ? $orden->value : null,
        ], static fn (?string $valor): bool => $valor !== null);
        $parametros->pagina = self::pagina($consulta['pagina'] ?? null);

        return $parametros;
    }

    /**
     * La URL de otra página con los mismos filtros y orden. La primera va sin el número, así es la
     * misma que se arma al filtrar.
     */
    public function urlDePagina(int $numero): string
    {
        return self::url('/cartelera', $this->valores + ($numero > 1 ? ['pagina' => $numero] : []));
    }

    /**
     * Si se recortó la cartelera. El orden no cuenta: no saca funciones, así que con él solo no
     * hay nada que limpiar y una cartelera vacía es porque no hay funciones programadas.
     */
    public function filtrada(): bool
    {
        return array_diff_key($this->valores, ['orden' => true]) !== [];
    }

    /**
     * La URL del CSV con los mismos filtros y orden. No lleva la página: el archivo las trae todas.
     */
    public function urlDeCsv(): string
    {
        return self::url('/cartelera.csv', $this->valores);
    }

    /**
     * Resumen de la búsqueda actual para recuperarla entre las últimas búsquedas (E4-06.4), o null
     * si no recorta la cartelera: un orden solo no es una búsqueda, como para filtrada(). La URL
     * lleva siempre a la primera página, así paginar una búsqueda no la duplica en el historial.
     *
     * @return ?array{url: string, etiqueta: string, filtros: array<string, string>}
     */
    public function busqueda(): ?array
    {
        if (!$this->filtrada()) {
            return null;
        }
        $partes = [];
        if (isset($this->valores['q'])) {
            $partes[] = "«{$this->valores['q']}»";
        }
        if (isset($this->valores['localidad'])) {
            $partes[] = $this->valores['localidad'];
        }
        if ($this->filtros->fecha !== null) {
            $partes[] = $this->filtros->fecha->format('d/m/Y');
        }
        if (isset($this->valores['genero'])) {
            $partes[] = $this->valores['genero'];
        }
        $rangoDePrecio = self::etiquetaDePrecio($this->filtros->precioMinimo, $this->filtros->precioMaximo);
        if ($rangoDePrecio !== null) {
            $partes[] = $rangoDePrecio;
        }
        if ($this->filtros->orden !== OrdenDeCartelera::FechaAscendente) {
            $partes[] = $this->filtros->orden->etiqueta();
        }

        return [
            'url' => $this->urlDePagina(1),
            'etiqueta' => implode(' · ', $partes),
            'filtros' => $this->valores,
        ];
    }

    private static function etiquetaDePrecio(?float $minimo, ?float $maximo): ?string
    {
        if ($minimo === null && $maximo === null) {
            return null;
        }
        if ($maximo !== null && $maximo == 0.0) {
            return 'A la gorra';
        }
        if ($minimo !== null && $maximo !== null) {
            $desde = $minimo == 0.0 ? '$ 0' : TarjetaDeFuncion::precio($minimo);

            return $minimo == $maximo ? TarjetaDeFuncion::precio($maximo) : "{$desde} a " . TarjetaDeFuncion::precio($maximo);
        }
        if ($minimo !== null) {
            return 'Desde ' . ($minimo == 0.0 ? '$ 0' : TarjetaDeFuncion::precio($minimo));
        }

        return 'Hasta ' . TarjetaDeFuncion::precio((float) $maximo);
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

    private function precio(mixed $valor, string $etiqueta): ?float
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return null;
        }
        $limpio = is_string($valor) && mb_check_encoding($valor, 'UTF-8') ? trim($valor) : '';
        if (preg_match(self::PRECIO, $limpio) !== 1) {
            $this->avisos[] = "{$etiqueta} no es válido: se muestra la cartelera sin ese filtro.";

            return null;
        }

        return 0.0 + round((float) str_replace(',', '.', $limpio), 2);
    }

    private static function precioParaUrl(?float $precio): ?string
    {
        if ($precio === null) {
            return null;
        }
        if (fmod($precio, 1.0) == 0.0) {
            return number_format($precio, 0, '.', '');
        }

        return rtrim(rtrim(number_format($precio, 2, '.', ''), '0'), '.');
    }

    private function orden(mixed $valor): OrdenDeCartelera
    {
        if ($valor === null || (is_string($valor) && trim($valor) === '')) {
            return OrdenDeCartelera::FechaAscendente;
        }
        $normalizado = is_string($valor) && mb_check_encoding($valor, 'UTF-8')
            ? str_replace('-', '_', mb_strtolower(trim($valor)))
            : '';
        $orden = OrdenDeCartelera::tryFrom($normalizado);
        if ($orden === null) {
            $this->avisos[] = 'El orden no es válido: se muestra la cartelera ordenada por fecha.';

            return OrdenDeCartelera::FechaAscendente;
        }

        return $orden;
    }

    private static function pagina(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return 1;
        }

        return is_string($valor) && preg_match(self::PAGINA, $valor) === 1 ? (int) $valor : null;
    }
}
