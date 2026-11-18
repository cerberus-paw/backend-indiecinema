<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\Http\ClienteInterno;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Las solicitudes de sala, que son de moderación: «Mi cuenta» muestra en qué quedó la última que
 * mandó el usuario (EF-07.6), por la API interna de moderación.
 */
final class SolicitudesDeModeracion
{
    public function __construct(
        private readonly ClienteInterno $cliente,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * La última que mandó, o null si nunca mandó una. Si moderación no responde, o responde otra
     * cosa, también null: «Mi cuenta» se ve igual, sin el bloque, y el log lo dice.
     *
     * @return ?array{estado: string, sala: string, motivo: ?string, fecha: DateTimeImmutable}
     */
    public function ultimaDe(string $usuarioId): ?array
    {
        try {
            $datos = $this->cliente->get('moderacion', "/interno/solicitudes/ultima/{$usuarioId}");
            if ($datos === null) {
                return null;
            }
            if (
                !is_array($datos) || !is_string($datos['estado'] ?? null) || !is_string($datos['sala'] ?? null)
                || !(is_string($datos['motivo'] ?? null) || ($datos['motivo'] ?? null) === null) || !is_string($datos['fecha'] ?? null)
            ) {
                throw new RuntimeException('moderación no respondió una solicitud a /interno/solicitudes/ultima/{usuario}.');
            }

            return [
                'estado' => $datos['estado'],
                'sala' => $datos['sala'],
                'motivo' => $datos['motivo'],
                'fecha' => new DateTimeImmutable($datos['fecha'])->setTimezone(new DateTimeZone(date_default_timezone_get())),
            ];
        } catch (Throwable $error) {
            $this->log->warning('«Mi cuenta» se muestra sin la solicitud de sala de {usuario}: {mensaje}', [
                'usuario' => $usuarioId,
                'mensaje' => $error->getMessage(),
            ]);

            return null;
        }
    }
}
