<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\CausaSuspension;
use IndieCinema\Moderacion\Modelo\Suspension;
use IndieCinema\Moderacion\Modelo\TipoDocumento;
use IndieCinema\Nucleo\BaseDeDatos;
use PDOException;

/**
 * Las suspensiones de las salas (EF-07.5).
 */
final class RepositorioDeSuspensiones
{
    /** El error de MySQL de una clave única repetida: ya hay una activa por esa causa. */
    private const CLAVE_DUPLICADA = 1062;

    private const SELECT = 'SELECT id, sala_id, causa, tipo_documento, motivo, abierta_por, desde FROM suspension';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * @return bool false si ya había una activa de esa sala por la misma causa y tipo
     */
    public function agregar(Suspension $suspension): bool
    {
        try {
            $this->base->ejecutar(
                'INSERT INTO suspension (id, sala_id, causa, tipo_documento, motivo, abierta_por, desde) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $suspension->id, $suspension->salaId, $suspension->causa->value, $suspension->tipoDocumento?->value,
                    $suspension->motivo, $suspension->abiertaPor, $suspension->desde,
                ],
            );
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                return false;
            }
            throw $error;
        }

        return true;
    }

    /**
     * Las que siguen activas, de la más vieja a la más nueva.
     *
     * @return list<Suspension>
     */
    public function activasDe(string $salaId): array
    {
        return array_map(self::suspension(...), $this->base->filas(self::SELECT . ' WHERE sala_id = ? AND levantada_en IS NULL ORDER BY desde, id', [$salaId]));
    }

    /**
     * Las activas que abrió la tarea (por un documento vencido o que falta): las que llevan su
     * aviso al organizador.
     *
     * @return list<Suspension>
     */
    public function activasDeLaTarea(): array
    {
        return array_map(self::suspension(...), $this->base->filas(
            self::SELECT . " WHERE causa = 'vencimiento' AND abierta_por IS NULL AND levantada_en IS NULL ORDER BY desde, id",
        ));
    }

    /**
     * @return bool false si ya estaba levantada
     */
    public function levantar(string $id, ?string $quien, DateTimeImmutable $cuando): bool
    {
        return $this->base->ejecutar(
            'UPDATE suspension SET levantada_en = ?, levantada_por = ? WHERE id = ? AND levantada_en IS NULL',
            [$cuando, $quien, $id],
        ) === 1;
    }

    /**
     * @param array<string, mixed> $fila
     */
    private static function suspension(array $fila): Suspension
    {
        return new Suspension(
            (string) $fila['id'],
            (string) $fila['sala_id'],
            CausaSuspension::from((string) $fila['causa']),
            $fila['tipo_documento'] === null ? null : TipoDocumento::from((string) $fila['tipo_documento']),
            (string) $fila['motivo'],
            $fila['abierta_por'] === null ? null : (string) $fila['abierta_por'],
            new DateTimeImmutable((string) $fila['desde'], new DateTimeZone('UTC')),
        );
    }
}
