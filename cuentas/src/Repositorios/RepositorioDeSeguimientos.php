<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\TipoDeSeguimiento;
use IndieCinema\Nucleo\BaseDeDatos;

/**
 * Las salas y los organizadores que sigue cada espectador (EF-15.4).
 */
final class RepositorioDeSeguimientos
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function sigue(string $usuarioId, TipoDeSeguimiento $tipo, string $objetoId): bool
    {
        return $this->base->fila(
            'SELECT 1 FROM seguimiento WHERE usuario_id = ? AND tipo = ? AND objeto_id = ?',
            [$usuarioId, $tipo->value, $objetoId],
        ) !== null;
    }

    /**
     * INSERT IGNORE: seguir lo que ya se sigue no es un error, ni siquiera con dos pedidos a la vez.
     */
    public function seguir(string $usuarioId, TipoDeSeguimiento $tipo, string $objetoId): void
    {
        $this->base->ejecutar(
            'INSERT IGNORE INTO seguimiento (usuario_id, tipo, objeto_id, creado_en) VALUES (?, ?, ?, ?)',
            [$usuarioId, $tipo->value, $objetoId, new DateTimeImmutable('now', new DateTimeZone('UTC'))],
        );
    }

    public function dejar(string $usuarioId, TipoDeSeguimiento $tipo, string $objetoId): void
    {
        $this->base->ejecutar(
            'DELETE FROM seguimiento WHERE usuario_id = ? AND tipo = ? AND objeto_id = ?',
            [$usuarioId, $tipo->value, $objetoId],
        );
    }

    /**
     * Los ids que sigue, por tipo y del más nuevo al más viejo.
     *
     * @return array{sala: list<string>, organizador: list<string>}
     */
    public function deUsuario(string $usuarioId): array
    {
        $seguidos = ['sala' => [], 'organizador' => []];
        foreach ($this->base->filas(
            'SELECT tipo, objeto_id FROM seguimiento WHERE usuario_id = ? ORDER BY creado_en DESC, objeto_id',
            [$usuarioId],
        ) as $fila) {
            $seguidos[(string) $fila['tipo']][] = (string) $fila['objeto_id'];
        }

        return $seguidos;
    }
}
