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
