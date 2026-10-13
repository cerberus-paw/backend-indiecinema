<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\TipoObra;

/**
 * Las obras y sus géneros en el esquema de programación. Las dadas de baja no se ven desde acá:
 * para la aplicación ya no existen, aunque la fila siga en la tabla.
 */
final class RepositorioDeObras
{
    private const COLUMNAS = 'id, dueno_id, titulo, sinopsis, anio, director, duracion, tipo, origen_copia, afiche';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function buscar(string $id): ?Obra
    {
        $fila = $this->base->fila(
            'SELECT ' . self::COLUMNAS . ' FROM obra WHERE id = ? AND dada_de_baja_en IS NULL',
            [$id],
        );
        if ($fila === null) {
            return null;
        }
        $generos = $this->base->filas('SELECT genero_id FROM obra_genero WHERE obra_id = ? ORDER BY genero_id', [$id]);

        return self::obra($fila, array_column($generos, 'genero_id'));
    }

    /**
     * Todas las obras activas del catálogo, ordenadas por título.
     *
     * @return list<Obra>
     */
    public function todas(): array
    {
        $filas = $this->base->filas(
            'SELECT ' . self::COLUMNAS . ' FROM obra
             WHERE dada_de_baja_en IS NULL
             ORDER BY titulo, anio, id',
        );
        $generos = [];
        foreach ($this->base->filas(
            'SELECT og.obra_id, og.genero_id FROM obra_genero og
             JOIN obra o ON o.id = og.obra_id
             WHERE o.dada_de_baja_en IS NULL
             ORDER BY og.genero_id',
        ) as $genero) {
            $generos[$genero['obra_id']][] = $genero['genero_id'];
        }

        return array_map(static fn (array $fila): Obra => self::obra($fila, $generos[$fila['id']] ?? []), $filas);
    }

    /**
     * Las obras de un dueño, por título.
     *
     * @return list<Obra>
     */
    public function delDueno(string $duenoId): array
    {
        $filas = $this->base->filas(
            'SELECT ' . self::COLUMNAS . ' FROM obra
             WHERE dueno_id = ? AND dada_de_baja_en IS NULL
             ORDER BY titulo, id',
            [$duenoId],
        );
        // Los géneros de todas en una sola consulta, en vez de una por obra.
        $generos = [];
        foreach ($this->base->filas(
            'SELECT og.obra_id, og.genero_id FROM obra_genero og
             JOIN obra o ON o.id = og.obra_id
             WHERE o.dueno_id = ? AND o.dada_de_baja_en IS NULL
             ORDER BY og.genero_id',
            [$duenoId],
        ) as $genero) {
            $generos[$genero['obra_id']][] = $genero['genero_id'];
        }

        return array_map(static fn (array $fila): Obra => self::obra($fila, $generos[$fila['id']] ?? []), $filas);
    }

    /**
     * La obra y sus géneros juntos: una obra sin géneros no tiene que quedar en la base.
     */
    public function agregar(Obra $obra): void
    {
        $this->base->enTransaccion(function (BaseDeDatos $base) use ($obra): void {
            $base->ejecutar(
                'INSERT INTO obra (id, dueno_id, titulo, sinopsis, anio, director, duracion, tipo, origen_copia, afiche)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $obra->id, $obra->duenoId, $obra->titulo, $obra->sinopsis, $obra->anio, $obra->director,
                    $obra->duracionMinutos, $obra->tipo, $obra->origenCopia, $obra->afiche,
                ],
            );
            foreach ($obra->generosIds as $generoId) {
                $base->ejecutar('INSERT INTO obra_genero (obra_id, genero_id) VALUES (?, ?)', [$obra->id, $generoId]);
            }
        });
    }

    /**
     * @param array<string, mixed> $fila
     * @param list<string>         $generosIds
     */
    private static function obra(array $fila, array $generosIds): Obra
    {
        return new Obra(
            (string) $fila['id'],
            (string) $fila['dueno_id'],
            (string) $fila['titulo'],
            (string) $fila['sinopsis'],
            (int) $fila['anio'],
            (string) $fila['director'],
            (int) $fila['duracion'],
            TipoObra::from((string) $fila['tipo']),
            OrigenCopia::from((string) $fila['origen_copia']),
            $generosIds,
            $fila['afiche'] === null ? null : (string) $fila['afiche'],
        );
    }
}
