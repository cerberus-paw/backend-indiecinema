<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;

/**
 * Lo que pasó en moderación en un período, contado, para los reportes del administrador en
 * recaudación (EF-22.4): las denuncias por lo denunciado y su estado, las salas suspendidas ahora
 * y las solicitudes de sala por estado. Las fechas van en UTC, como las guarda moderación.
 */
final class RepositorioDelResumen
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * Las denuncias que se hicieron en el período, contadas por lo denunciado, por estado y por
     * gravedad.
     *
     * @return list<array{tipo: string, objeto_id: string, estado: string, gravedad: string, cantidad: int}>
     */
    public function denuncias(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        return array_map(static fn (array $fila): array => [
            'tipo' => (string) $fila['tipo'],
            'objeto_id' => (string) $fila['objeto_id'],
            'estado' => (string) $fila['estado'],
            'gravedad' => (string) $fila['gravedad'],
            'cantidad' => (int) $fila['cantidad'],
        ], $this->base->filas(
            // Con la gravedad (EF-22.11): agrupa más fino, y quien sólo suma las cantidades sigue
            // igual. De más a menos grave.
            'SELECT tipo, objeto_id, estado, gravedad, COUNT(*) AS cantidad
             FROM denuncia
             WHERE creada_en >= ? AND creada_en < ?
             GROUP BY tipo, objeto_id, estado, gravedad
             ORDER BY tipo, objeto_id, estado, gravedad DESC',
            [$desde, $hasta],
        ));
    }

    /**
     * Las suspensiones que siguen activas: es el estado de hoy, no el del período.
     *
     * @return list<array{sala_id: string, causa: string, desde: string}>
     */
    public function suspendidas(): array
    {
        return array_map(static fn (array $fila): array => [
            'sala_id' => (string) $fila['sala_id'],
            'causa' => (string) $fila['causa'],
            'desde' => (string) $fila['desde'],
        ], $this->base->filas(
            'SELECT sala_id, causa, desde FROM suspension WHERE levantada_en IS NULL ORDER BY desde',
        ));
    }

    /**
     * Las solicitudes de sala que se mandaron en el período, por estado.
     *
     * @return array<string, int>
     */
    public function solicitudes(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $porEstado = [];
        foreach ($this->base->filas(
            'SELECT estado, COUNT(*) AS cantidad FROM solicitud_sala WHERE enviada_en >= ? AND enviada_en < ? GROUP BY estado',
            [$desde, $hasta],
        ) as $fila) {
            $porEstado[(string) $fila['estado']] = (int) $fila['cantidad'];
        }

        return $porEstado;
    }
}
