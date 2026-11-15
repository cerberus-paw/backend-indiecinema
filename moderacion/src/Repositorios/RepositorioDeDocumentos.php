<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\Documento;
use IndieCinema\Moderacion\Modelo\EstadoDocumento;
use IndieCinema\Moderacion\Modelo\TipoDocumento;
use IndieCinema\Nucleo\BaseDeDatos;

/**
 * Los documentos de las salas (EF-07.4). Se agregan con su solicitud, en RepositorioDeSolicitudes,
 * para que queden en la misma transacción.
 */
final class RepositorioDeDocumentos
{
    private const SELECT = 'SELECT id, sala_id, solicitud_id, tipo, archivo, vence_en, subido_en, estado FROM documento';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function buscar(string $id): ?Documento
    {
        $fila = $this->base->fila(self::SELECT . ' WHERE id = ?', [$id]);

        return $fila === null ? null : self::documento($fila);
    }

    /**
     * Los de una solicitud, primero el seguro.
     *
     * @return list<Documento>
     */
    public function deSolicitud(string $solicitudId): array
    {
        return array_map(self::documento(...), $this->base->filas(self::SELECT . ' WHERE solicitud_id = ? ORDER BY tipo', [$solicitudId]));
    }

    /**
     * @param array<string, mixed> $fila
     */
    private static function documento(array $fila): Documento
    {
        return new Documento(
            (string) $fila['id'],
            (string) $fila['sala_id'],
            $fila['solicitud_id'] === null ? null : (string) $fila['solicitud_id'],
            TipoDocumento::from((string) $fila['tipo']),
            (string) $fila['archivo'],
            new DateTimeImmutable((string) $fila['vence_en']),
            // Se guarda en UTC, como las fechas de la solicitud.
            new DateTimeImmutable((string) $fila['subido_en'], new DateTimeZone('UTC')),
            EstadoDocumento::from((string) $fila['estado']),
        );
    }
}
