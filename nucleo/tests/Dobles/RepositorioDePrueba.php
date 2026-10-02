<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Dobles;

use IndieCinema\Nucleo\BaseDeDatos;

/**
 * Un repositorio como los de los subsistemas: recibe la base por el constructor y todo su SQL va
 * con los valores aparte.
 */
final class RepositorioDePrueba
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function salaPorNombre(string $nombre): ?array
    {
        return $this->base->fila('SELECT id, nombre, capacidad FROM sala WHERE nombre = ?', [$nombre]);
    }
}
