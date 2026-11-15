<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Uuid;

/**
 * El historial de las resoluciones de obras (EF-07.7): quién aprobó o rechazó qué obra, cuándo y
 * por qué. El estado de la obra es de programación.
 */
final class RepositorioDeRevisionesDeObras
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function agregar(string $obraId, string $duenoId, string $titulo, string $resolucion, string $motivo, string $moderadorId, DateTimeImmutable $resueltaEn): void
    {
        $this->base->ejecutar(
            'INSERT INTO revision_obra (id, obra_id, dueno_id, titulo, resolucion, motivo, moderador_id, resuelta_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [Uuid::nuevo(), $obraId, $duenoId, $titulo, $resolucion, $motivo, $moderadorId, $resueltaEn],
        );
    }

    /**
     * Las últimas resoluciones, primero la más nueva.
     *
     * @param positive-int $limite
     *
     * @return list<array{obra_id: string, dueno_id: string, titulo: string, resolucion: string, motivo: string, moderador_id: string, resuelta_en: DateTimeImmutable}>
     */
    public function historial(int $limite): array
    {
        return array_map(static fn (array $fila): array => [
            'obra_id' => (string) $fila['obra_id'],
            'dueno_id' => (string) $fila['dueno_id'],
            'titulo' => (string) $fila['titulo'],
            'resolucion' => (string) $fila['resolucion'],
            'motivo' => (string) $fila['motivo'],
            'moderador_id' => (string) $fila['moderador_id'],
            'resuelta_en' => new DateTimeImmutable((string) $fila['resuelta_en'], new DateTimeZone('UTC')),
        ], $this->base->filas(
            'SELECT obra_id, dueno_id, titulo, resolucion, motivo, moderador_id, resuelta_en FROM revision_obra
             ORDER BY resuelta_en DESC, id LIMIT ?',
            [$limite],
        ));
    }
}
