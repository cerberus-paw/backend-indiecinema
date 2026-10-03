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
        peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, habilitada';

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

    public function agregar(Sala $sala): void
    {
        $datos = $sala->datos;
        $this->base->ejecutar(
            'INSERT INTO sala (id, organizador_id, nombre, descripcion, direccion, localidad, capacidad,
                peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $sala->id, $sala->organizadorId, $datos->nombre, $datos->descripcion, $datos->direccion,
                $datos->localidad, $datos->capacidad, $datos->peliculasPorFuncion, $datos->duracionFuncion,
                $datos->tiempoEntreFunciones,
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
                peliculas_por_funcion = ?, duracion_funcion = ?, tiempo_entre_funciones = ?
             WHERE id = ?',
            [
                $datos->nombre, $datos->descripcion, $datos->direccion, $datos->localidad, $datos->capacidad,
                $datos->peliculasPorFuncion, $datos->duracionFuncion, $datos->tiempoEntreFunciones, $sala->id,
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
            (bool) $fila['habilitada'],
        );
    }
}
