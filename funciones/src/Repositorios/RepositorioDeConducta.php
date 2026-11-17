<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Funciones\Modelo\Conducta;
use IndieCinema\Nucleo\BaseDeDatos;
use PDOException;

/**
 * Calificaciones de conducta de los espectadores sobre reservas con asistencia (EF-13.1).
 */
final class RepositorioDeConducta
{
    private const CLAVE_DUPLICADA = 1062;

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * @return bool false si esa reserva ya tenía su conducta calificada
     */
    public function agregar(Conducta $conducta): bool
    {
        try {
            $this->base->ejecutar(
                'INSERT INTO conducta (reserva_id, usuario_id, organizador_id, positiva, motivo, creada_en)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $conducta->reservaId,
                    $conducta->usuarioId,
                    $conducta->organizadorId,
                    $conducta->positiva ? 1 : 0,
                    $conducta->motivo,
                    $conducta->creadaEn->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                ],
            );

            return true;
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                return false;
            }

            throw $error;
        }
    }

    /**
     * Las calificaciones de conducta ya cargadas en la función, indexadas por reserva_id.
     *
     * @return array<string, Conducta>
     */
    public function deLaFuncion(string $funcionId): array
    {
        $filas = $this->base->filas(
            'SELECT c.reserva_id, c.usuario_id, c.organizador_id, c.positiva, c.motivo, c.creada_en
             FROM conducta c
             INNER JOIN reserva r ON r.id = c.reserva_id
             WHERE r.funcion_id = ?',
            [$funcionId],
        );

        $porReserva = [];
        foreach ($filas as $fila) {
            $conducta = new Conducta(
                (string) $fila['reserva_id'],
                (string) $fila['usuario_id'],
                (string) $fila['organizador_id'],
                (bool) $fila['positiva'],
                $fila['motivo'] === null ? null : (string) $fila['motivo'],
                new DateTimeImmutable((string) $fila['creada_en'], new DateTimeZone('UTC')),
            );
            $porReserva[$conducta->reservaId] = $conducta;
        }

        return $porReserva;
    }

    public function negativasVigentesDe(string $usuarioId, DateTimeImmutable $desde): int
    {
        return (int) $this->base->valor(
            'SELECT COUNT(*) FROM conducta WHERE usuario_id = ? AND positiva = 0 AND creada_en >= ?',
            [$usuarioId, $desde->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
    }

    public function ausenciasDe(string $usuarioId): int
    {
        return (int) $this->base->valor(
            "SELECT COUNT(*) FROM reserva WHERE usuario_id = ? AND estado = 'ausente'",
            [$usuarioId],
        );
    }

    /**
     * Resumen de actividad y calificaciones de conducta del espectador (EF-13.3).
     *
     * @return array{asistencias: int, ausencias: int, conducta_positivas: int, conducta_negativas: int, negativas_vigentes: int, calificaciones_funciones: int}
     */
    public function resumenDeActividad(string $usuarioId, DateTimeImmutable $desde): array
    {
        $reservas = $this->base->fila(
            "SELECT
                COALESCE(SUM(estado = 'asistio'), 0) AS asistencias,
                COALESCE(SUM(estado = 'ausente'), 0) AS ausencias
             FROM reserva
             WHERE usuario_id = ?",
            [$usuarioId],
        );
        $desdeUtc = $desde->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $conductas = $this->base->fila(
            'SELECT
                COALESCE(SUM(positiva = 1), 0) AS positivas,
                COALESCE(SUM(positiva = 0), 0) AS negativas,
                COALESCE(SUM(positiva = 0 AND creada_en >= ?), 0) AS negativas_vigentes
             FROM conducta
             WHERE usuario_id = ?',
            [$desdeUtc, $usuarioId],
        );
        $calificaciones = (int) $this->base->valor(
            'SELECT COUNT(*) FROM calificacion WHERE usuario_id = ?',
            [$usuarioId],
        );

        return [
            'asistencias' => (int) ($reservas['asistencias'] ?? 0),
            'ausencias' => (int) ($reservas['ausencias'] ?? 0),
            'conducta_positivas' => (int) ($conductas['positivas'] ?? 0),
            'conducta_negativas' => (int) ($conductas['negativas'] ?? 0),
            'negativas_vigentes' => (int) ($conductas['negativas_vigentes'] ?? 0),
            'calificaciones_funciones' => $calificaciones,
        ];
    }

    /**
     * Calificaciones negativas vigentes por usuario en lote (EF-13.3).
     *
     * @param list<string> $usuariosIds
     *
     * @return array<string, int>
     */
    public function negativasVigentesDeVarios(array $usuariosIds, DateTimeImmutable $desde): array
    {
        if ($usuariosIds === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($usuariosIds), '?'));
        $desdeUtc = $desde->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $porUsuario = [];
        foreach ($this->base->filas(
            "SELECT usuario_id, COUNT(*) AS cantidad
             FROM conducta
             WHERE positiva = 0 AND creada_en >= ? AND usuario_id IN ({$marcas})
             GROUP BY usuario_id",
            [$desdeUtc, ...$usuariosIds],
        ) as $fila) {
            $porUsuario[(string) $fila['usuario_id']] = (int) $fila['cantidad'];
        }

        return $porUsuario;
    }

    /**
     * Ausencias totales por usuario en lote (EF-13.3).
     *
     * @param list<string> $usuariosIds
     *
     * @return array<string, int>
     */
    public function ausenciasDeVarios(array $usuariosIds): array
    {
        if ($usuariosIds === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($usuariosIds), '?'));
        $porUsuario = [];
        foreach ($this->base->filas(
            "SELECT usuario_id, COUNT(*) AS cantidad
             FROM reserva
             WHERE estado = 'ausente' AND usuario_id IN ({$marcas})
             GROUP BY usuario_id",
            $usuariosIds,
        ) as $fila) {
            $porUsuario[(string) $fila['usuario_id']] = (int) $fila['cantidad'];
        }

        return $porUsuario;
    }
}
