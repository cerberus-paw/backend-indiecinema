<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Funciones\Modelo\Calificacion;
use IndieCinema\Nucleo\BaseDeDatos;
use PDOException;

/**
 * Las calificaciones de las funciones (EF-04.2) y las obras de cada una.
 */
final class RepositorioDeCalificaciones
{
    /** El código de MySQL de una clave única repetida. */
    private const CLAVE_DUPLICADA = 1062;

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * La calificación con sus obras, juntas.
     *
     * @return bool false si el espectador ya había calificado la función, y entonces no guardó nada
     */
    public function agregar(Calificacion $calificacion): bool
    {
        try {
            $this->base->enTransaccion(function (BaseDeDatos $base) use ($calificacion): void {
                $base->ejecutar(
                    'INSERT INTO calificacion (id, funcion_id, usuario_id, reserva_id, puntaje, comentario, sala_id, organizador_id, creada_en)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $calificacion->id, $calificacion->funcionId, $calificacion->usuarioId, $calificacion->reservaId,
                        $calificacion->puntaje, $calificacion->comentario, $calificacion->salaId, $calificacion->organizadorId,
                        $calificacion->creadaEn->setTimezone(new DateTimeZone('UTC')),
                    ],
                );
                foreach ($calificacion->obrasIds as $obraId) {
                    $base->ejecutar('INSERT INTO calificacion_obra (calificacion_id, obra_id) VALUES (?, ?)', [$calificacion->id, $obraId]);
                }
            });

            return true;
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                return false;
            }
            throw $error;
        }
    }

    /**
     * Las que dejó el espectador en esas funciones, para «Mis reservas»: qué falta calificar y qué
     * puntaje puso en las otras.
     *
     * @param list<string> $funcionesIds
     *
     * @return array<string, Calificacion> con el id de la función como clave
     */
    public function delUsuarioEn(string $usuarioId, array $funcionesIds): array
    {
        if ($funcionesIds === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($funcionesIds), '?'));
        $calificaciones = [];
        foreach ($this->base->filas(
            "SELECT * FROM calificacion WHERE usuario_id = ? AND funcion_id IN ({$marcas})",
            [$usuarioId, ...$funcionesIds],
        ) as $fila) {
            $calificaciones[(string) $fila['funcion_id']] = self::calificacion($fila);
        }

        return $calificaciones;
    }

    /**
     * El promedio y la cantidad de calificaciones de cada sala, obra, organizador o función
     * (EF-04.3), en una consulta por cada tipo que se pida. Los que no tienen calificaciones no
     * vienen.
     *
     * @param array{salas?: list<string>, obras?: list<string>, organizadores?: list<string>, funciones?: list<string>} $ids
     *
     * @return array{salas: array<string, array{promedio: float, cantidad: int}>, obras: array<string, array{promedio: float, cantidad: int}>, organizadores: array<string, array{promedio: float, cantidad: int}>, funciones: array<string, array{promedio: float, cantidad: int}>}
     */
    public function resumen(array $ids): array
    {
        // Columnas fijas, nunca lo que viene del pedido: el tipo elige una de éstas.
        $columnas = ['salas' => 'c.sala_id', 'organizadores' => 'c.organizador_id', 'funciones' => 'c.funcion_id', 'obras' => 'co.obra_id'];
        $resumen = ['salas' => [], 'obras' => [], 'organizadores' => [], 'funciones' => []];
        foreach ($columnas as $tipo => $columna) {
            $lista = $ids[$tipo] ?? [];
            if ($lista === []) {
                continue;
            }
            $union = $tipo === 'obras' ? ' JOIN calificacion_obra co ON co.calificacion_id = c.id' : '';
            $marcas = implode(', ', array_fill(0, count($lista), '?'));
            foreach ($this->base->filas(
                "SELECT {$columna} AS id, AVG(c.puntaje) AS promedio, COUNT(*) AS cantidad
                 FROM calificacion c{$union}
                 WHERE {$columna} IN ({$marcas})
                 GROUP BY {$columna}",
                $lista,
            ) as $fila) {
                $resumen[$tipo][(string) $fila['id']] = ['promedio' => round((float) $fila['promedio'], 1), 'cantidad' => (int) $fila['cantidad']];
            }
        }

        return $resumen;
    }

    /**
     * Las últimas calificaciones de una sala o de una obra, de la más nueva a la más vieja, para
     * su ficha (EF-04.3).
     *
     * @param 'sala'|'obra' $de
     *
     * @return list<Calificacion>
     */
    public function ultimas(string $de, string $id, int $cuantas): array
    {
        $sql = $de === 'obra'
            ? 'SELECT c.* FROM calificacion c JOIN calificacion_obra co ON co.calificacion_id = c.id WHERE co.obra_id = ? ORDER BY c.creada_en DESC, c.id LIMIT ?'
            : 'SELECT c.* FROM calificacion c WHERE c.sala_id = ? ORDER BY c.creada_en DESC, c.id LIMIT ?';

        return array_map(self::calificacion(...), $this->base->filas($sql, [$id, $cuantas]));
    }

    /**
     * Sin las obras: quien las necesita las pide aparte.
     *
     * @param array<string, mixed> $fila
     */
    private static function calificacion(array $fila): Calificacion
    {
        return new Calificacion(
            (string) $fila['id'],
            (string) $fila['funcion_id'],
            (string) $fila['usuario_id'],
            (string) $fila['reserva_id'],
            (int) $fila['puntaje'],
            $fila['comentario'] === null ? null : (string) $fila['comentario'],
            (string) $fila['sala_id'],
            (string) $fila['organizador_id'],
            [],
            new DateTimeImmutable((string) $fila['creada_en'], new DateTimeZone('UTC')),
        );
    }
}
