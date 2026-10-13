<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\DatosDeSolicitud;
use IndieCinema\Cuentas\Modelo\EstadoSolicitud;
use IndieCinema\Cuentas\Modelo\Solicitante;
use IndieCinema\Cuentas\Modelo\Solicitud;
use IndieCinema\Cuentas\Servicios\SolicitudPendiente;
use IndieCinema\Nucleo\BaseDeDatos;
use PDOException;

/**
 * Las solicitudes para ser organizador en el esquema de cuentas, siempre con la cuenta de quien
 * la mandó. Las fechas van y vienen en UTC, como las escribe la aplicación.
 */
final class RepositorioDeSolicitudes
{
    private const CLAVE_DUPLICADA = 1062;

    private const SELECT = 'SELECT s.id, s.sala, s.localidad, s.correo, s.telefono, s.presentacion, s.estado,
                                   s.enviada_en, s.motivo, s.resuelta_en,
                                   u.id AS usuario_id, u.nombre AS usuario_nombre, u.correo AS usuario_correo,
                                   u.creado_en AS usuario_creado_en
                            FROM solicitud s
                            JOIN usuario u ON u.id = s.usuario_id';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * @throws SolicitudPendiente si el usuario ya tiene una sin resolver
     */
    public function agregar(string $id, string $usuarioId, DatosDeSolicitud $datos, DateTimeImmutable $enviadaEn): void
    {
        try {
            $this->base->ejecutar(
                'INSERT INTO solicitud (id, usuario_id, sala, localidad, correo, telefono, presentacion, enviada_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $usuarioId, $datos->sala, $datos->localidad, $datos->correo, $datos->telefono, $datos->presentacion, $enviadaEn],
            );
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                throw new SolicitudPendiente('El usuario ya tiene una solicitud pendiente.', previous: $error);
            }
            throw $error;
        }
    }

    public function buscar(string $id): ?Solicitud
    {
        $fila = $this->base->fila(self::SELECT . ' WHERE s.id = ?', [$id]);

        return $fila === null ? null : self::solicitud($fila);
    }

    /**
     * La última que mandó el usuario, resuelta o no, o null si nunca mandó una.
     */
    public function ultimaDe(string $usuarioId): ?Solicitud
    {
        $fila = $this->base->fila(self::SELECT . ' WHERE s.usuario_id = ? ORDER BY s.enviada_en DESC, s.id LIMIT 1', [$usuarioId]);

        return $fila === null ? null : self::solicitud($fila);
    }

    /**
     * Las que esperan una resolución, de la más vieja a la más nueva: primero la que lleva más
     * tiempo esperando.
     *
     * @param positive-int $limite
     *
     * @return list<Solicitud>
     */
    public function pendientes(int $limite): array
    {
        $filas = $this->base->filas(
            self::SELECT . ' WHERE s.estado = ? ORDER BY s.enviada_en, s.id LIMIT ?',
            [EstadoSolicitud::Pendiente->value, $limite],
        );

        return array_map(self::solicitud(...), $filas);
    }

    public function cuantasPendientes(): int
    {
        return (int) $this->base->valor('SELECT COUNT(*) FROM solicitud WHERE estado = ?', [EstadoSolicitud::Pendiente->value]);
    }

    /**
     * La aprueba o la rechaza, sólo si sigue pendiente.
     *
     * @return bool false si ya estaba resuelta (otro administrador, o el mismo POST dos veces)
     */
    public function resolver(string $id, EstadoSolicitud $estado, string $administradorId, string $motivo, DateTimeImmutable $resueltaEn): bool
    {
        return $this->base->ejecutar(
            'UPDATE solicitud SET estado = ?, resuelta_por = ?, motivo = ?, resuelta_en = ? WHERE id = ? AND estado = ?',
            [$estado->value, $administradorId, $motivo, $resueltaEn, $id, EstadoSolicitud::Pendiente->value],
        ) === 1;
    }

    /**
     * @param array<string, mixed> $fila
     */
    private static function solicitud(array $fila): Solicitud
    {
        return new Solicitud(
            (string) $fila['id'],
            new Solicitante(
                (string) $fila['usuario_id'],
                (string) $fila['usuario_nombre'],
                (string) $fila['usuario_correo'],
                self::fecha($fila['usuario_creado_en']),
            ),
            new DatosDeSolicitud(
                (string) $fila['sala'],
                (string) $fila['localidad'],
                (string) $fila['correo'],
                $fila['telefono'] === null ? null : (string) $fila['telefono'],
                (string) $fila['presentacion'],
            ),
            EstadoSolicitud::from((string) $fila['estado']),
            self::fecha($fila['enviada_en']),
            $fila['motivo'] === null ? null : (string) $fila['motivo'],
            $fila['resuelta_en'] === null ? null : self::fecha($fila['resuelta_en']),
        );
    }

    private static function fecha(mixed $valor): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $valor, new DateTimeZone('UTC'));
    }
}
