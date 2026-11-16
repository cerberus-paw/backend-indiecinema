<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\BloqueoDeSala;

/**
 * Bloqueos de espectadores por sala (EF-12.1).
 */
final class RepositorioDeBloqueos
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * Registra o actualiza el bloqueo de un usuario en una sala: si ya estaba bloqueado, actualiza
     * motivo, autor y fecha sin duplicar filas (PK sala_id, usuario_id).
     */
    public function guardar(
        string $salaId,
        string $usuarioId,
        string $motivo,
        string $bloqueadoPor,
        DateTimeImmutable $bloqueadoEn = new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ): void {
        $utc = $bloqueadoEn->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $this->base->ejecutar(
            'INSERT INTO bloqueo (sala_id, usuario_id, motivo, bloqueado_por, bloqueado_en)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 motivo = VALUES(motivo),
                 bloqueado_por = VALUES(bloqueado_por),
                 bloqueado_en = VALUES(bloqueado_en)',
            [$salaId, $usuarioId, $motivo, $bloqueadoPor, $utc],
        );
    }

    public function quitar(string $salaId, string $usuarioId): bool
    {
        return $this->base->ejecutar(
            'DELETE FROM bloqueo WHERE sala_id = ? AND usuario_id = ?',
            [$salaId, $usuarioId],
        ) > 0;
    }

    public function estaBloqueado(string $salaId, string $usuarioId): bool
    {
        return $this->base->valor(
            'SELECT 1 FROM bloqueo WHERE sala_id = ? AND usuario_id = ?',
            [$salaId, $usuarioId],
        ) !== null;
    }

    /**
     * Todos los bloqueos activos en las salas del organizador, del más reciente al más viejo.
     *
     * @return list<BloqueoDeSala>
     */
    public function delOrganizador(string $organizadorId): array
    {
        $filas = $this->base->filas(
            'SELECT b.sala_id, s.nombre AS sala_nombre, b.usuario_id, b.motivo, b.bloqueado_por, b.bloqueado_en
             FROM bloqueo b
             INNER JOIN sala s ON s.id = b.sala_id
             WHERE s.organizador_id = ?
             ORDER BY b.bloqueado_en DESC, s.nombre ASC, b.usuario_id ASC',
            [$organizadorId],
        );

        return array_map(
            static fn (array $fila): BloqueoDeSala => new BloqueoDeSala(
                (string) $fila['sala_id'],
                (string) $fila['sala_nombre'],
                (string) $fila['usuario_id'],
                (string) $fila['motivo'],
                (string) $fila['bloqueado_por'],
                new DateTimeImmutable((string) $fila['bloqueado_en'], new DateTimeZone('UTC')),
            ),
            $filas,
        );
    }
}
