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
     * Los de una sala, de la solicitud o renovaciones, primero el seguro y el que vence más lejos.
     *
     * @return list<Documento>
     */
    public function deLaSala(string $salaId): array
    {
        return array_map(self::documento(...), $this->base->filas(self::SELECT . ' WHERE sala_id = ? ORDER BY tipo, vence_en DESC, subido_en DESC', [$salaId]));
    }

    /**
     * Una renovación que sube el organizador (EF-07.5), pendiente y sin solicitud. Si había otra
     * pendiente del mismo tipo, la reemplaza: queda rechazada, en la misma transacción.
     */
    public function agregarRenovacion(Documento $documento): void
    {
        $this->base->enTransaccion(function (BaseDeDatos $base) use ($documento): void {
            $base->ejecutar(
                "UPDATE documento SET estado = 'rechazado', motivo = 'Lo reemplazó otro.', resuelto_en = ?
                 WHERE sala_id = ? AND tipo = ? AND solicitud_id IS NULL AND estado = 'pendiente'",
                [$documento->subidoEn, $documento->salaId, $documento->tipo->value],
            );
            $base->ejecutar(
                'INSERT INTO documento (id, sala_id, solicitud_id, tipo, archivo, vence_en, subido_en, estado) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)',
                [
                    $documento->id, $documento->salaId, $documento->tipo->value, $documento->archivo,
                    $documento->venceEn->format('Y-m-d'), $documento->subidoEn, $documento->estado->value,
                ],
            );
        });
    }

    /**
     * Las renovaciones que esperan a la moderación, de la más vieja a la más nueva.
     *
     * @return list<Documento>
     */
    public function renovacionesPendientes(): array
    {
        return array_map(self::documento(...), $this->base->filas(
            self::SELECT . " WHERE solicitud_id IS NULL AND estado = 'pendiente' ORDER BY subido_en, id",
        ));
    }

    /**
     * Acepta o rechaza una renovación pendiente, con el motivo y quién.
     *
     * @return bool false si ya no estaba pendiente
     */
    public function resolverRenovacion(string $id, EstadoDocumento $estado, string $motivo, string $quien, DateTimeImmutable $cuando): bool
    {
        return $this->base->ejecutar(
            "UPDATE documento SET estado = ?, motivo = ?, resuelto_por = ?, resuelto_en = ?
             WHERE id = ? AND solicitud_id IS NULL AND estado = 'pendiente'",
            [$estado->value, $motivo, $quien, $cuando, $id],
        ) === 1;
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
     * Las salas y tipos cuyo documento vigente (el aceptado con el vencimiento más lejano) venció:
     * vence hoy o antes.
     *
     * @return list<array{sala_id: string, tipo: TipoDocumento}>
     */
    public function vencidosAl(DateTimeImmutable $hoy): array
    {
        $filas = $this->base->filas(
            "SELECT sala_id, tipo FROM documento WHERE estado = 'aceptado'
             GROUP BY sala_id, tipo HAVING MAX(vence_en) <= ? ORDER BY sala_id, tipo",
            [$hoy->format('Y-m-d')],
        );

        return array_map(static fn (array $fila): array => [
            'sala_id' => (string) $fila['sala_id'],
            'tipo' => TipoDocumento::from((string) $fila['tipo']),
        ], $filas);
    }

    /**
     * Los tipos de los que la sala tiene algún documento aceptado, aunque esté vencido (de ése se
     * encarga el vencimiento).
     *
     * @return list<TipoDocumento>
     */
    public function tiposAceptadosDe(string $salaId): array
    {
        return array_map(
            static fn (array $fila): TipoDocumento => TipoDocumento::from((string) $fila['tipo']),
            $this->base->filas("SELECT DISTINCT tipo FROM documento WHERE sala_id = ? AND estado = 'aceptado' ORDER BY tipo", [$salaId]),
        );
    }

    /**
     * Los documentos vigentes (el aceptado con el vencimiento más lejano de cada sala y tipo) que
     * vencen entre las dos fechas, incluidas.
     *
     * @return list<Documento>
     */
    public function vigentesQueVencenEntre(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $filas = $this->base->filas(
            "SELECT id, sala_id, solicitud_id, tipo, archivo, vence_en, subido_en, estado FROM documento d
             WHERE estado = 'aceptado' AND vence_en BETWEEN ? AND ?
               AND vence_en = (SELECT MAX(o.vence_en) FROM documento o WHERE o.sala_id = d.sala_id AND o.tipo = d.tipo AND o.estado = 'aceptado')
             ORDER BY sala_id, tipo",
            [$desde->format('Y-m-d'), $hasta->format('Y-m-d')],
        );

        return array_map(self::documento(...), $filas);
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
