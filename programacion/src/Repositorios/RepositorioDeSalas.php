<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Sala;

/**
 * Las salas en el esquema de programación. Las dadas de baja no se ven desde acá: para la
 * aplicación ya no existen, aunque la fila siga en la tabla.
 */
final class RepositorioDeSalas
{
    private const COLUMNAS = 'id, organizador_id, nombre, descripcion, direccion, localidad, capacidad,
        peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, imagen, habilitada';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function buscar(string $id): ?Sala
    {
        $fila = $this->base->fila(
            'SELECT ' . self::COLUMNAS . ' FROM sala WHERE id = ? AND dada_de_baja_en IS NULL',
            [$id],
        );

        return $fila === null ? null : self::sala($fila);
    }

    /**
     * Las salas de un organizador, de la más nueva a la más vieja.
     *
     * @return list<Sala>
     */
    public function delOrganizador(string $organizadorId): array
    {
        return array_map(self::sala(...), $this->base->filas(
            'SELECT ' . self::COLUMNAS . ' FROM sala
             WHERE organizador_id = ? AND dada_de_baja_en IS NULL
             ORDER BY creada_en DESC, nombre',
            [$organizadorId],
        ));
    }

    /**
     * Las que se ven en el sitio: habilitadas y no dadas de baja, por nombre.
     *
     * @return list<Sala>
     */
    public function publicadas(): array
    {
        return array_map(self::sala(...), $this->base->filas(
            'SELECT ' . self::COLUMNAS . ' FROM sala
             WHERE habilitada = TRUE AND dada_de_baja_en IS NULL
             ORDER BY nombre',
        ));
    }

    public function agregar(Sala $sala): void
    {
        $datos = $sala->datos;
        $this->base->ejecutar(
            'INSERT INTO sala (id, organizador_id, nombre, descripcion, direccion, localidad, capacidad,
                peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, imagen)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $sala->id, $sala->organizadorId, $datos->nombre, $datos->descripcion, $datos->direccion,
                $datos->localidad, $datos->capacidad, $datos->peliculasPorFuncion, $datos->duracionFuncion,
                $datos->tiempoEntreFunciones, $sala->imagen,
            ],
        );
    }

    /**
     * Guarda lo que carga el organizador. El dueño y el flag de habilitada no cambian por acá.
     */
    public function guardar(Sala $sala): void
    {
        $datos = $sala->datos;
        $this->base->ejecutar(
            'UPDATE sala SET nombre = ?, descripcion = ?, direccion = ?, localidad = ?, capacidad = ?,
                peliculas_por_funcion = ?, duracion_funcion = ?, tiempo_entre_funciones = ?, imagen = ?
             WHERE id = ?',
            [
                $datos->nombre, $datos->descripcion, $datos->direccion, $datos->localidad, $datos->capacidad,
                $datos->peliculasPorFuncion, $datos->duracionFuncion, $datos->tiempoEntreFunciones, $sala->imagen,
                $sala->id,
            ],
        );
    }

    /**
     * @param array<string, mixed> $fila
     */
    private static function sala(array $fila): Sala
    {
        return new Sala(
            (string) $fila['id'],
            (string) $fila['organizador_id'],
            new DatosDeSala(
                (string) $fila['nombre'],
                (string) $fila['descripcion'],
                (string) $fila['direccion'],
                (string) $fila['localidad'],
                (int) $fila['capacidad'],
                (int) $fila['peliculas_por_funcion'],
                (int) $fila['duracion_funcion'],
                (int) $fila['tiempo_entre_funciones'],
            ),
            $fila['imagen'] === null ? null : (string) $fila['imagen'],
            (bool) $fila['habilitada'],
        );
    }
}
