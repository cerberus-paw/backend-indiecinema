<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\ObraEnCartelera;
use IndieCinema\Programacion\Modelo\PaginaDeCartelera;
use InvalidArgumentException;

/**
 * La cartelera pública: las funciones programadas que todavía no pasaron, de salas habilitadas y
 * no dadas de baja, de la más próxima a la más lejana.
 *
 * Los filtros arman el WHERE, pero sólo con fragmentos fijos de este archivo: lo que escribe el
 * visitante va siempre como parámetro de la consulta preparada, nunca pegado al SQL. Las
 * búsquedas comparan con la intercalación del esquema (utf8mb4_0900_ai_ci), así que no distinguen
 * mayúsculas ni tildes: «cienaga» encuentra «La ciénaga».
 */
final class RepositorioDeCartelera
{
    private const DESDE = 'FROM funcion f JOIN sala s ON s.id = f.sala_id';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * @param int $numero desde 1
     * @param int $tamano cuántas funciones entran en una página
     *
     * @throws InvalidArgumentException si el número o el tamaño de la página no son positivos
     */
    public function pagina(
        FiltrosDeCartelera $filtros,
        int $numero,
        int $tamano,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): PaginaDeCartelera {
        if ($numero < 1 || $tamano < 1) {
            throw new InvalidArgumentException('El número y el tamaño de la página tienen que ser positivos.');
        }
        [$condiciones, $parametros] = self::condiciones($filtros, $ahora);
        $total = (int) $this->base->valor('SELECT COUNT(*) ' . self::DESDE . " WHERE {$condiciones}", $parametros);
        $pagina = new PaginaDeCartelera([], $numero, $tamano, $total);
        // Una página que no existe no se consulta. Así, además, el OFFSET no se pasa de un entero
        // con un número de página enorme.
        if ($total === 0 || !$pagina->existe()) {
            return $pagina;
        }

        $filas = $this->base->filas(
            'SELECT f.id, f.fecha_hora, f.precio_base, s.id AS sala_id, s.nombre AS sala, s.localidad '
            . self::DESDE . " WHERE {$condiciones}
             ORDER BY f.fecha_hora, s.nombre, f.id
             LIMIT ? OFFSET ?",
            [...$parametros, $tamano, ($numero - 1) * $tamano],
        );

        return new PaginaDeCartelera($this->conSusObras($filas), $numero, $tamano, $total);
    }

    /**
     * Las localidades que tienen alguna función en la cartelera, para el filtro.
     *
     * @return list<string>
     */
    public function localidades(DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        [$condiciones, $parametros] = self::condiciones(new FiltrosDeCartelera(), $ahora);

        return array_column($this->base->filas(
            'SELECT DISTINCT s.localidad ' . self::DESDE . " WHERE {$condiciones} ORDER BY s.localidad",
            $parametros,
        ), 'localidad');
    }

    /**
     * El WHERE de la cartelera con los filtros que vinieron, y sus valores en el mismo orden que
     * los «?».
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private static function condiciones(FiltrosDeCartelera $filtros, DateTimeImmutable $ahora): array
    {
        $condiciones = [
            'f.estado = ?',
            'f.fecha_hora > ?',
            's.habilitada = TRUE',
            's.dada_de_baja_en IS NULL',
        ];
        $parametros = [EstadoFuncion::Programada, $ahora];

        if ($filtros->texto !== null) {
            // Sólo las obras elegidas: las candidatas que perdieron la votación no se proyectan.
            $condiciones[] = "(s.nombre LIKE ? ESCAPE '!' OR EXISTS (
                SELECT 1 FROM funcion_obra fo JOIN obra o ON o.id = fo.obra_id
                WHERE fo.funcion_id = f.id AND fo.estado = 'elegida'
                    AND (o.titulo LIKE ? ESCAPE '!' OR o.director LIKE ? ESCAPE '!')
            ))";
            $patron = '%' . self::literalParaLike($filtros->texto) . '%';
            array_push($parametros, $patron, $patron, $patron);
        }
        if ($filtros->localidad !== null) {
            $condiciones[] = 's.localidad = ?';
            $parametros[] = $filtros->localidad;
        }
        if ($filtros->fecha !== null) {
            // Un rango y no DATE(f.fecha_hora) = ?, que no podría usar el índice por fecha.
            $condiciones[] = 'f.fecha_hora >= ? AND f.fecha_hora < ?';
            array_push($parametros, $filtros->fecha, $filtros->fecha->modify('+1 day'));
        }
        if ($filtros->generoId !== null) {
            $condiciones[] = "EXISTS (
                SELECT 1 FROM funcion_obra fo JOIN obra_genero og ON og.obra_id = fo.obra_id
                WHERE fo.funcion_id = f.id AND fo.estado = 'elegida' AND og.genero_id = ?
            )";
            $parametros[] = $filtros->generoId;
        }

        return [implode(' AND ', $condiciones), $parametros];
    }

    /**
     * El texto para buscarlo tal cual dentro de un LIKE: sin esto, un «%» o un «_» del visitante
     * serían comodines. El escape es «!» y no la barra, que cambia de significado con el modo
     * NO_BACKSLASH_ESCAPES de MySQL.
     */
    private static function literalParaLike(string $texto): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto);
    }

    /**
     * Las obras de todas las funciones de la página en una sola consulta, en vez de una por
     * función.
     *
     * @param list<array<string, mixed>> $filas
     *
     * @return list<FuncionEnCartelera>
     */
    private function conSusObras(array $filas): array
    {
        $ids = array_column($filas, 'id');
        $marcas = implode(', ', array_fill(0, count($ids), '?'));
        $obras = [];
        foreach ($this->base->filas(
            "SELECT fo.funcion_id, o.id, o.titulo, o.afiche FROM funcion_obra fo
             JOIN obra o ON o.id = fo.obra_id
             WHERE fo.estado = 'elegida' AND fo.funcion_id IN ({$marcas})
             ORDER BY o.titulo, o.id",
            $ids,
        ) as $obra) {
            $obras[$obra['funcion_id']][] = new ObraEnCartelera(
                (string) $obra['id'],
                (string) $obra['titulo'],
                $obra['afiche'] === null ? null : (string) $obra['afiche'],
            );
        }

        return array_map(static fn (array $fila): FuncionEnCartelera => new FuncionEnCartelera(
            (string) $fila['id'],
            new DateTimeImmutable((string) $fila['fecha_hora']),
            (float) $fila['precio_base'],
            (string) $fila['sala_id'],
            (string) $fila['sala'],
            (string) $fila['localidad'],
            $obras[$fila['id']] ?? [],
        ), $filas);
    }
}
