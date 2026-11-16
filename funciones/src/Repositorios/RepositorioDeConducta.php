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
}
