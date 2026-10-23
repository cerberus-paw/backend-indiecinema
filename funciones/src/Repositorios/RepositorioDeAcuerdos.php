<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Repositorios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\AcuerdoFecha;
use IndieCinema\Funciones\Modelo\EstadoAcuerdo;
use IndieCinema\Funciones\Modelo\FranjaHoraria;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Uuid;

/**
 * Persistencia de los acuerdos de fecha, sus franjas y votos en MySQL.
 */
class RepositorioDeAcuerdos
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * Guarda un acuerdo de fecha con sus franjas en una sola transacción.
     */
    public function guardar(AcuerdoFecha $acuerdo): void
    {
        $this->base->enTransaccion(function () use ($acuerdo): void {
            $creadoEn = ($acuerdo->creadoEn ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
            $cerradoEn = $acuerdo->cerradoEn?->format('Y-m-d H:i:s');

            $this->base->ejecutar(
                'INSERT INTO acuerdo_fecha (id, funcion_id, minimo_asistentes, estado, franja_ganadora_id, creado_en, cerrado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $acuerdo->id,
                    $acuerdo->funcionId,
                    $acuerdo->minimoAsistentes,
                    $acuerdo->estado->value,
                    $acuerdo->franjaGanadoraId,
                    $creadoEn,
                    $cerradoEn,
                ],
            );

            foreach ($acuerdo->franjas as $franja) {
                $franjaCreadaEn = ($franja->creadaEn ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
                $this->base->ejecutar(
                    'INSERT INTO acuerdo_franja (id, acuerdo_id, fecha_hora_inicio, fecha_hora_fin, creada_en)
                     VALUES (?, ?, ?, ?, ?)',
                    [
                        $franja->id,
                        $acuerdo->id,
                        $franja->inicio->format('Y-m-d H:i:s'),
                        $franja->fin?->format('Y-m-d H:i:s'),
                        $franjaCreadaEn,
                    ],
                );
            }
        });
    }

    /**
     * Busca el acuerdo de fecha más reciente de una función, con sus franjas y conteo de votos.
     */
    public function buscarPorFuncion(string $funcionId): ?AcuerdoFecha
    {
        $fila = $this->base->fila(
            'SELECT * FROM acuerdo_fecha WHERE funcion_id = ? ORDER BY creado_en DESC LIMIT 1',
            [$funcionId],
        );

        if ($fila === null) {
            return null;
        }

        return $this->hidratar($fila);
    }

    /**
     * Busca un acuerdo por su ID con sus franjas y conteo de votos.
     */
    public function buscarPorId(string $id): ?AcuerdoFecha
    {
        $fila = $this->base->fila(
            'SELECT * FROM acuerdo_fecha WHERE id = ?',
            [$id],
        );

        if ($fila === null) {
            return null;
        }

        return $this->hidratar($fila);
    }

    /**
     * Actualiza el estado de un acuerdo de fecha.
     */
    public function actualizarEstado(string $id, EstadoAcuerdo $estado, ?string $franjaGanadoraId = null): void
    {
        $cerradoEn = $estado->estaAbierto() ? null : (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->base->ejecutar(
            'UPDATE acuerdo_fecha SET estado = ?, franja_ganadora_id = ?, cerrado_en = ? WHERE id = ?',
            [$estado->value, $franjaGanadoraId, $cerradoEn, $id],
        );
    }

    /**
     * Emite o cambia el voto de un usuario por una franja horaria.
     * Si ya votó en este acuerdo o función, se actualiza la franja elegida.
     */
    public function votarOActualizar(string $acuerdoId, string $funcionId, string $franjaId, string $usuarioId): void
    {
        $ahora = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->base->ejecutar(
            'INSERT INTO voto_acuerdo (id, acuerdo_id, funcion_id, franja_id, usuario_id, creado_en)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE franja_id = VALUES(franja_id), creado_en = VALUES(creado_en)',
            [Uuid::nuevo(), $acuerdoId, $funcionId, $franjaId, $usuarioId, $ahora],
        );
    }

    /**
     * Devuelve el id de la franja votada por el usuario en este acuerdo, o null si no votó.
     */
    public function votoDeUsuario(string $acuerdoId, string $usuarioId): ?string
    {
        $franjaId = $this->base->valor(
            'SELECT franja_id FROM voto_acuerdo WHERE acuerdo_id = ? AND usuario_id = ?',
            [$acuerdoId, $usuarioId],
        );

        return is_string($franjaId) ? $franjaId : null;
    }

    /**
     * @param array<string, mixed> $fila
     */
    private function hidratar(array $fila): AcuerdoFecha
    {
        $acuerdoId = (string) $fila['id'];
        $filasFranjas = $this->base->filas(
            'SELECT f.*, COUNT(v.id) AS votos
             FROM acuerdo_franja f
             LEFT JOIN voto_acuerdo v ON v.franja_id = f.id
             WHERE f.acuerdo_id = ?
             GROUP BY f.id
             ORDER BY f.fecha_hora_inicio ASC',
            [$acuerdoId],
        );

        $franjas = [];
        foreach ($filasFranjas as $ff) {
            $franjas[] = new FranjaHoraria(
                (string) $ff['id'],
                $acuerdoId,
                new DateTimeImmutable((string) $ff['fecha_hora_inicio']),
                $ff['fecha_hora_fin'] !== null ? new DateTimeImmutable((string) $ff['fecha_hora_fin']) : null,
                $ff['creada_en'] !== null ? new DateTimeImmutable((string) $ff['creada_en']) : null,
                (int) ($ff['votos'] ?? 0),
            );
        }

        return new AcuerdoFecha(
            $acuerdoId,
            (string) $fila['funcion_id'],
            (int) $fila['minimo_asistentes'],
            EstadoAcuerdo::from((string) $fila['estado']),
            $fila['franja_ganadora_id'] !== null ? (string) $fila['franja_ganadora_id'] : null,
            new DateTimeImmutable((string) $fila['creado_en']),
            $fila['cerrado_en'] !== null ? new DateTimeImmutable((string) $fila['cerrado_en']) : null,
            $franjas,
        );
    }
}
