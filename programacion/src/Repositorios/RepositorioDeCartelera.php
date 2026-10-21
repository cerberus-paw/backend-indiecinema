<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\DetalleDeFuncion;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\ObraEnCartelera;
use IndieCinema\Programacion\Modelo\ObraEnDetalle;
use IndieCinema\Programacion\Modelo\PaginaDeCartelera;
use IndieCinema\Programacion\Modelo\SalaEnDetalle;
use IndieCinema\Programacion\Modelo\TipoObra;
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
    /** La sala y el id desempatan, así una función no cambia de página entre una consulta y otra. */
    private const ORDEN = 'ORDER BY (f.estado = \'en_votacion_titulos\'), f.fecha_hora, s.nombre, f.id';

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

        $funciones = $this->funciones("{$condiciones} " . self::ORDEN . ' LIMIT ? OFFSET ?', [...$parametros, $tamano, ($numero - 1) * $tamano]);

        return new PaginaDeCartelera($funciones, $numero, $tamano, $total);
    }

    /**
     * Todas las funciones filtradas, sin paginar y en el mismo orden que las páginas: lo que se
     * descarga en CSV.
     *
     * @return list<FuncionEnCartelera>
     */
    public function todas(FiltrosDeCartelera $filtros, DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        [$condiciones, $parametros] = self::condiciones($filtros, $ahora);

        return $this->funciones("{$condiciones} " . self::ORDEN, $parametros);
    }

    /**
     * El detalle de una función, en cualquier estado, con su sala y sus obras: las elegidas si ya
     * se definieron, o las candidatas si está en votación (o se canceló durante la votación).
     *
     * Las obras dadas de baja también: la baja es lógica justamente para que las funciones sigan
     * apuntando a ellas. Si se filtraran, una función cuya elegida se dio de baja mostraría las
     * candidatas que perdieron.
     *
     * Devuelve null si la función no existe o si su sala no está habilitada o está dada de baja.
     */
    public function detalle(string $id): ?DetalleDeFuncion
    {
        $fila = $this->base->fila(
            'SELECT f.id, f.estado, f.fecha_hora, f.precio_base, f.cupo,
                    s.id AS sala_id, s.nombre AS sala_nombre, s.descripcion AS sala_descripcion,
                    s.direccion AS sala_direccion, s.localidad AS sala_localidad,
                    s.capacidad AS sala_capacidad, s.imagen AS sala_imagen, s.organizador_id AS sala_organizador_id '
            . self::DESDE . '
             WHERE f.id = ? AND s.habilitada = TRUE AND s.dada_de_baja_en IS NULL',
            [$id],
        );
        if ($fila === null) {
            return null;
        }

        $filasObras = $this->base->filas(
            "SELECT fo.estado AS estado_obra, o.id, o.titulo, o.sinopsis, o.anio, o.director, o.duracion, o.tipo, o.afiche
             FROM funcion_obra fo
             JOIN obra o ON o.id = fo.obra_id
             WHERE fo.funcion_id = ?
             ORDER BY o.titulo, o.id",
            [$id],
        );

        // Si ya hay elegidas, se muestran sólo ésas (las perdedoras quedan afuera).
        // Si todavía no hay (en votación), se muestran las candidatas.
        $elegidas = array_values(array_filter($filasObras, static fn (array $o): bool => $o['estado_obra'] === 'elegida'));
        $obrasElegidas = $elegidas !== [];
        $obrasAProyectar = $obrasElegidas ? $elegidas : $filasObras;

        $generosPorObra = [];
        if ($obrasAProyectar !== []) {
            $idsObras = array_column($obrasAProyectar, 'id');
            $marcas = implode(', ', array_fill(0, count($idsObras), '?'));
            foreach ($this->base->filas(
                "SELECT og.obra_id, g.nombre AS genero
                 FROM obra_genero og
                 JOIN genero g ON g.id = og.genero_id
                 WHERE og.obra_id IN ({$marcas})
                 ORDER BY g.nombre",
                $idsObras,
            ) as $filaGenero) {
                $generosPorObra[$filaGenero['obra_id']][] = (string) $filaGenero['genero'];
            }
        }

        $obras = array_map(static fn (array $o): ObraEnDetalle => new ObraEnDetalle(
            (string) $o['id'],
            (string) $o['titulo'],
            (string) $o['sinopsis'],
            (int) $o['anio'],
            (string) $o['director'],
            (int) $o['duracion'],
            TipoObra::from((string) $o['tipo']),
            $o['afiche'] === null ? null : (string) $o['afiche'],
            $generosPorObra[$o['id']] ?? [],
        ), $obrasAProyectar);

        $sala = new SalaEnDetalle(
            (string) $fila['sala_id'],
            (string) $fila['sala_nombre'],
            (string) $fila['sala_descripcion'],
            (string) $fila['sala_direccion'],
            (string) $fila['sala_localidad'],
            (int) $fila['sala_capacidad'],
            $fila['sala_imagen'] === null ? null : (string) $fila['sala_imagen'],
            isset($fila['sala_organizador_id']) ? (string) $fila['sala_organizador_id'] : null,
        );

        $fechaHora = $fila['fecha_hora'] === null ? null : new DateTimeImmutable((string) $fila['fecha_hora']);

        return new DetalleDeFuncion(
            (string) $fila['id'],
            EstadoFuncion::from((string) $fila['estado']),
            $fechaHora,
            (float) $fila['precio_base'],
            (int) $fila['cupo'],
            $sala,
            $obras,
            $obrasElegidas,
        );
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
            '((f.estado = ? AND f.fecha_hora > ?) OR f.estado = ?)',
            's.habilitada = TRUE',
            's.dada_de_baja_en IS NULL',
        ];
        $parametros = [EstadoFuncion::Programada, $ahora, EstadoFuncion::EnVotacionTitulos];

        if ($filtros->texto !== null) {
            // Las obras elegidas o, si está en votación, las candidatas; las perdedoras no.
            $condiciones[] = "(s.nombre LIKE ? ESCAPE '!' OR EXISTS (
                SELECT 1 FROM funcion_obra fo JOIN obra o ON o.id = fo.obra_id
                WHERE fo.funcion_id = f.id
                    AND (fo.estado = 'elegida' OR (f.estado = 'en_votacion_titulos' AND fo.estado = 'candidata'))
                    AND (o.titulo LIKE ? ESCAPE '!' OR o.director LIKE ? ESCAPE '!')
            ))";
            $patron = Like::contiene($filtros->texto);
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
                WHERE fo.funcion_id = f.id
                    AND (fo.estado = 'elegida' OR (f.estado = 'en_votacion_titulos' AND fo.estado = 'candidata'))
                    AND og.genero_id = ?
            )";
            $parametros[] = $filtros->generoId;
        }
        if ($filtros->salaId !== null) {
            $condiciones[] = 'f.sala_id = ?';
            $parametros[] = $filtros->salaId;
        }

        return [implode(' AND ', $condiciones), $parametros];
    }

    /**
     * Las funciones con sus obras.
     *
     * @param string      $resto      el WHERE, sin la palabra, y lo que sigue
     * @param list<mixed> $parametros
     *
     * @return list<FuncionEnCartelera>
     */
    private function funciones(string $resto, array $parametros): array
    {
        return $this->conSusObras($this->base->filas(
            'SELECT f.id, f.estado, f.fecha_hora, f.precio_base, s.id AS sala_id, s.nombre AS sala, s.localidad '
            . self::DESDE . " WHERE {$resto}",
            $parametros,
        ));
    }

    /**
     * Las obras de todas las funciones en una sola consulta, en vez de una por función.
     *
     * @param list<array<string, mixed>> $filas
     *
     * @return list<FuncionEnCartelera>
     */
    private function conSusObras(array $filas): array
    {
        if ($filas === []) {
            return [];
        }
        $ids = array_column($filas, 'id');
        $marcas = implode(', ', array_fill(0, count($ids), '?'));
        $obrasElegidas = [];
        $obrasCandidatas = [];
        foreach ($this->base->filas(
            "SELECT fo.funcion_id, fo.estado, o.id, o.titulo, o.afiche FROM funcion_obra fo
             JOIN obra o ON o.id = fo.obra_id
             WHERE fo.estado IN ('elegida', 'candidata') AND fo.funcion_id IN ({$marcas})
             ORDER BY o.titulo, o.id",
            $ids,
        ) as $obra) {
            $obraObj = new ObraEnCartelera(
                (string) $obra['id'],
                (string) $obra['titulo'],
                $obra['afiche'] === null ? null : (string) $obra['afiche'],
            );
            if ($obra['estado'] === 'elegida') {
                $obrasElegidas[$obra['funcion_id']][] = $obraObj;
            } else {
                $obrasCandidatas[$obra['funcion_id']][] = $obraObj;
            }
        }

        return array_map(static fn (array $fila): FuncionEnCartelera => new FuncionEnCartelera(
            (string) $fila['id'],
            $fila['fecha_hora'] !== null ? new DateTimeImmutable((string) $fila['fecha_hora']) : null,
            (float) $fila['precio_base'],
            (string) $fila['sala_id'],
            (string) $fila['sala'],
            (string) $fila['localidad'],
            $obrasElegidas[$fila['id']] ?? $obrasCandidatas[$fila['id']] ?? [],
            EstadoFuncion::from((string) $fila['estado']),
        ), $filas);
    }
}
