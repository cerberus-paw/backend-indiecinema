<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\Genero;

/**
 * Los géneros de las obras en el esquema de programación.
 */
final class RepositorioDeGeneros
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function buscar(string $id): ?Genero
    {
        $fila = $this->base->fila('SELECT id, nombre, descripcion FROM genero WHERE id = ?', [$id]);

        return $fila === null ? null : self::genero($fila);
    }

    /**
     * Todos, por nombre: son pocos, y el filtro de la cartelera los ofrece a todos.
     *
     * @return list<Genero>
     */
    public function todos(): array
    {
        return array_map(self::genero(...), $this->base->filas('SELECT id, nombre, descripcion FROM genero ORDER BY nombre'));
    }

    public function agregar(Genero $genero): void
    {
        $this->base->ejecutar(
            'INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)',
            [$genero->id, $genero->nombre, $genero->descripcion],
        );
    }

    /**
     * @param array<string, mixed> $fila
     */
    private static function genero(array $fila): Genero
    {
        return new Genero((string) $fila['id'], (string) $fila['nombre'], (string) $fila['descripcion']);
    }
}
