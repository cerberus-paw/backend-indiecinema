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
     * Marca «Me sirve» en una franja. Marcarla dos veces no suma: la clave única es por franja y
     * usuario, y el segundo pedido (un doble clic) deja el voto como estaba (E3-13.F7).
     */
    public function marcar(string $acuerdoId, string $funcionId, string $franjaId, string $usuarioId): void
    {
        $this->base->ejecutar(
            'INSERT INTO voto_acuerdo (id, acuerdo_id, funcion_id, franja_id, usuario_id, creado_en)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE creado_en = creado_en',
            [Uuid::nuevo(), $acuerdoId, $funcionId, $franjaId, $usuarioId, (new DateTimeImmutable())->format('Y-m-d H:i:s')],
        );
    }

    /**
     * Quita el «Me sirve» de una franja; si no estaba marcada, no hace nada.
     */
    public function desmarcar(string $franjaId, string $usuarioId): void
    {
        $this->base->ejecutar('DELETE FROM voto_acuerdo WHERE franja_id = ? AND usuario_id = ?', [$franjaId, $usuarioId]);
    }

    /**
     * Las franjas del acuerdo que marcó el usuario, en el orden en que empiezan.
     *
     * @return list<string>
     */
    public function franjasDeUsuario(string $acuerdoId, string $usuarioId): array
    {
        $filas = $this->base->filas(
            'SELECT v.franja_id
             FROM voto_acuerdo v
             JOIN acuerdo_franja f ON f.id = v.franja_id
             WHERE v.acuerdo_id = ? AND v.usuario_id = ?
             ORDER BY f.fecha_hora_inicio',
            [$acuerdoId, $usuarioId],
        );

        return array_map(static fn (array $fila): string => (string) $fila['franja_id'], $filas);
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
