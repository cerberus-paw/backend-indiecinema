<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Repositorios;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Recaudacion\Modelo\Comercio;

/**
 * Los comercios asociados (EF-17.3).
 */
final class RepositorioDeComercios
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * Los activos, para la página pública, por localidad y nombre.
     *
     * @return list<Comercio>
     */
    public function activos(): array
    {
        return array_map(self::comercio(...), $this->base->filas(
            'SELECT * FROM comercio WHERE activo ORDER BY localidad, nombre',
        ));
    }

    /**
     * Todos, para el administrador: primero los activos.
     *
     * @return list<Comercio>
     */
    public function todos(): array
    {
        return array_map(self::comercio(...), $this->base->filas(
            'SELECT * FROM comercio ORDER BY activo DESC, localidad, nombre',
        ));
    }

    public function buscar(string $id): ?Comercio
    {
        $fila = $this->base->fila('SELECT * FROM comercio WHERE id = ?', [$id]);

        return $fila === null ? null : self::comercio($fila);
    }

    public function guardar(Comercio $comercio): void
    {
        $this->base->ejecutar(
            'INSERT INTO comercio (id, nombre, beneficio, direccion, localidad, activo) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), beneficio = VALUES(beneficio),
                 direccion = VALUES(direccion), localidad = VALUES(localidad), activo = VALUES(activo)',
            [$comercio->id, $comercio->nombre, $comercio->beneficio, $comercio->direccion, $comercio->localidad, $comercio->activo ? 1 : 0],
        );
    }

    /**
     * @param array<string, mixed> $fila
     */
    private static function comercio(array $fila): Comercio
    {
        return new Comercio(
            (string) $fila['id'],
            (string) $fila['nombre'],
            (string) $fila['beneficio'],
            (string) $fila['direccion'],
            (string) $fila['localidad'],
            (bool) $fila['activo'],
        );
    }
}
