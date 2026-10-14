<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\EstadoSolicitud;
use IndieCinema\Programacion\Modelo\SolicitudDeSala;
use IndieCinema\Programacion\Servicios\SolicitudPendiente;
use PDOException;

/**
 * Las solicitudes de sala, cada una con su sala en la misma consulta: la bandeja muestra las dos
 * cosas juntas. Las de salas dadas de baja no se ven.
 */
final class RepositorioDeSolicitudes
{
    private const CLAVE_DUPLICADA = 1062;

    private const SELECT = 'SELECT so.id AS solicitud_id, so.usuario_id, so.solicitante_nombre, so.estado,
                                   so.enviada_en, so.resuelta_en, so.motivo,
                                   sa.id, sa.organizador_id, sa.nombre, sa.descripcion, sa.direccion, sa.localidad,
                                   sa.capacidad, sa.peliculas_por_funcion, sa.duracion_funcion,
                                   sa.tiempo_entre_funciones, sa.imagen, sa.habilitada
                            FROM solicitud_sala so
                            JOIN sala sa ON sa.id = so.sala_id AND sa.dada_de_baja_en IS NULL';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * @throws SolicitudPendiente si la sala ya tiene una sin resolver
     */
    public function agregar(string $id, string $salaId, string $usuarioId, ?string $solicitanteNombre, DateTimeImmutable $enviadaEn): void
    {
        try {
            $this->base->ejecutar(
                'INSERT INTO solicitud_sala (id, sala_id, usuario_id, solicitante_nombre, enviada_en) VALUES (?, ?, ?, ?, ?)',
                [$id, $salaId, $usuarioId, $solicitanteNombre, $enviadaEn],
            );
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                throw new SolicitudPendiente('La sala ya tiene una solicitud pendiente.', previous: $error);
            }
            throw $error;
        }
    }

    public function buscar(string $id): ?SolicitudDeSala
    {
        $fila = $this->base->fila(self::SELECT . ' WHERE so.id = ?', [$id]);

        return $fila === null ? null : self::solicitud($fila);
    }

    /**
     * La última que mandó el usuario, resuelta o no, o null si nunca mandó una.
     */
    public function ultimaDe(string $usuarioId): ?SolicitudDeSala
    {
        $fila = $this->base->fila(self::SELECT . ' WHERE so.usuario_id = ? ORDER BY so.enviada_en DESC, so.id LIMIT 1', [$usuarioId]);

        return $fila === null ? null : self::solicitud($fila);
    }

    /**
     * Cuántas mandó el usuario en total, para el historial del solicitante.
     */
    public function cuantasDe(string $usuarioId): int
    {
        return (int) $this->base->valor('SELECT COUNT(*) FROM solicitud_sala WHERE usuario_id = ?', [$usuarioId]);
    }

    /**
     * Las que esperan una resolución, primero la que lleva más tiempo esperando; o las resueltas
     * (el historial de decisiones), primero la última que se resolvió.
     *
     * @param ?string      $texto  si viene, sólo las que lo tienen en el nombre o la localidad de
     *                             la sala, o en el nombre de quien la mandó. Como en la cartelera,
     *                             sin distinguir mayúsculas ni tildes, y va como parámetro, con los
     *                             comodines de LIKE escapados.
     * @param positive-int $limite
     *
     * @return list<SolicitudDeSala>
     */
    public function lista(bool $pendientes, ?string $texto, int $limite): array
    {
        [$filtro, $parametros] = self::filtro($pendientes, $texto);
        $orden = $pendientes ? 'so.enviada_en, so.id' : 'so.resuelta_en DESC, so.id';
        $parametros[] = $limite;

        return array_map(self::solicitud(...), $this->base->filas(self::SELECT . " WHERE {$filtro} ORDER BY {$orden} LIMIT ?", $parametros));
    }

    /**
     * Cuántas hay en la lista, aunque muestre menos.
     */
    public function cuantas(bool $pendientes, ?string $texto = null): int
    {
        [$filtro, $parametros] = self::filtro($pendientes, $texto);

        return (int) $this->base->valor(
            'SELECT COUNT(*) FROM solicitud_sala so JOIN sala sa ON sa.id = so.sala_id AND sa.dada_de_baja_en IS NULL WHERE ' . $filtro,
            $parametros,
        );
    }

    /**
     * La aprueba o la rechaza, sólo si sigue pendiente.
     *
     * @return bool false si ya estaba resuelta (otro administrador, o el mismo POST dos veces)
     */
    public function resolver(string $id, EstadoSolicitud $estado, string $administradorId, string $motivo, DateTimeImmutable $resueltaEn): bool
    {
        return $this->base->ejecutar(
            'UPDATE solicitud_sala SET estado = ?, resuelta_por = ?, motivo = ?, resuelta_en = ? WHERE id = ? AND estado = ?',
            [$estado->value, $administradorId, $motivo, $resueltaEn, $id, EstadoSolicitud::Pendiente->value],
        ) === 1;
    }

    /**
     * @return array{string, list<string>}
     */
    private static function filtro(bool $pendientes, ?string $texto): array
    {
        $filtro = $pendientes ? 'so.estado = ?' : 'so.estado <> ?';
        $parametros = [EstadoSolicitud::Pendiente->value];
        $texto = trim((string) $texto);
        if ($texto !== '') {
            $filtro .= " AND (sa.nombre LIKE ? ESCAPE '!' OR sa.localidad LIKE ? ESCAPE '!' OR so.solicitante_nombre LIKE ? ESCAPE '!')";
            array_push($parametros, Like::contiene($texto), Like::contiene($texto), Like::contiene($texto));
        }

        return [$filtro, $parametros];
    }

    /**
     * @param array<string, mixed> $fila
     */
    private static function solicitud(array $fila): SolicitudDeSala
    {
        return new SolicitudDeSala(
            (string) $fila['solicitud_id'],
            RepositorioDeSalas::sala($fila),
            (string) $fila['usuario_id'],
            $fila['solicitante_nombre'] === null ? null : (string) $fila['solicitante_nombre'],
            EstadoSolicitud::from((string) $fila['estado']),
            self::fecha($fila['enviada_en']),
            $fila['resuelta_en'] === null ? null : self::fecha($fila['resuelta_en']),
            $fila['motivo'] === null ? null : (string) $fila['motivo'],
        );
    }

    private static function fecha(mixed $valor): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $valor, new DateTimeZone('UTC'));
    }
}
