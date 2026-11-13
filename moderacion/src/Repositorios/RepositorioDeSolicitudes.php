<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\DatosDeSala;
use IndieCinema\Moderacion\Modelo\EstadoSolicitud;
use IndieCinema\Moderacion\Modelo\SolicitudDeSala;
use IndieCinema\Moderacion\Servicios\SolicitudPendiente;
use IndieCinema\Nucleo\BaseDeDatos;
use PDOException;

/**
 * Las solicitudes de sala, con la sala que proponen (EF-07.2): la bandeja y el solicitante ven lo
 * que se pidió sin preguntarle a programación.
 */
final class RepositorioDeSolicitudes
{
    private const CLAVE_DUPLICADA = 1062;

    private const SELECT = 'SELECT id, sala_id, usuario_id, solicitante_nombre, nombre, descripcion, direccion, localidad,
                                   capacidad, peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, imagen,
                                   estado, enviada_en, resuelta_en, motivo
                            FROM solicitud_sala';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * @throws SolicitudPendiente si la sala ya tiene una sin resolver
     */
    public function agregar(SolicitudDeSala $solicitud): void
    {
        $datos = $solicitud->datos;
        try {
            $this->base->ejecutar(
                'INSERT INTO solicitud_sala (id, sala_id, usuario_id, solicitante_nombre, nombre, descripcion, direccion,
                    localidad, capacidad, peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, imagen, estado, enviada_en)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $solicitud->id, $solicitud->salaId, $solicitud->usuarioId, $solicitud->solicitanteNombre,
                    $datos->nombre, $datos->descripcion, $datos->direccion, $datos->localidad, $datos->capacidad,
                    $datos->peliculasPorFuncion, $datos->duracionFuncion, $datos->tiempoEntreFunciones,
                    $solicitud->imagen, $solicitud->estado->value, $solicitud->enviadaEn,
                ],
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
        $fila = $this->base->fila(self::SELECT . ' WHERE id = ?', [$id]);

        return $fila === null ? null : self::solicitud($fila);
    }

    /**
     * La última que mandó el usuario, resuelta o no, o null si nunca mandó una.
     */
    public function ultimaDe(string $usuarioId): ?SolicitudDeSala
    {
        $fila = $this->base->fila(self::SELECT . ' WHERE usuario_id = ? ORDER BY enviada_en DESC, id LIMIT 1', [$usuarioId]);

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
        $orden = $pendientes ? 'enviada_en, id' : 'resuelta_en DESC, id';
        $parametros[] = $limite;

        return array_map(self::solicitud(...), $this->base->filas(self::SELECT . " WHERE {$filtro} ORDER BY {$orden} LIMIT ?", $parametros));
    }

    /**
     * Cuántas hay en la lista, aunque muestre menos.
     */
    public function cuantas(bool $pendientes, ?string $texto = null): int
    {
        [$filtro, $parametros] = self::filtro($pendientes, $texto);

        return (int) $this->base->valor('SELECT COUNT(*) FROM solicitud_sala WHERE ' . $filtro, $parametros);
    }

    /**
     * La aprueba o la rechaza, sólo si sigue pendiente.
     *
     * @return bool false si ya estaba resuelta (otro administrador, o el mismo POST dos veces)
     */
    public function resolver(string $id, EstadoSolicitud $estado, string $resueltaPor, string $motivo, DateTimeImmutable $resueltaEn): bool
    {
        return $this->base->ejecutar(
            'UPDATE solicitud_sala SET estado = ?, resuelta_por = ?, motivo = ?, resuelta_en = ? WHERE id = ? AND estado = ?',
            [$estado->value, $resueltaPor, $motivo, $resueltaEn, $id, EstadoSolicitud::Pendiente->value],
        ) === 1;
    }

    /**
     * @return array{string, list<string>}
     */
    private static function filtro(bool $pendientes, ?string $texto): array
    {
        $filtro = $pendientes ? 'estado = ?' : 'estado <> ?';
        $parametros = [EstadoSolicitud::Pendiente->value];
        $texto = trim((string) $texto);
        if ($texto !== '') {
            $filtro .= " AND (nombre LIKE ? ESCAPE '!' OR localidad LIKE ? ESCAPE '!' OR solicitante_nombre LIKE ? ESCAPE '!')";
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
            (string) $fila['id'],
            (string) $fila['sala_id'],
            (string) $fila['usuario_id'],
            $fila['solicitante_nombre'] === null ? null : (string) $fila['solicitante_nombre'],
            new DatosDeSala(
                (string) $fila['nombre'],
                (string) $fila['descripcion'],
                (string) $fila['direccion'],
                (string) $fila['localidad'],
                (int) $fila['capacidad'],
                (int) $fila['peliculas_por_funcion'],
                (int) $fila['duracion_funcion'],
                (int) $fila['tiempo_entre_funciones'],
            ),
            $fila['imagen'] === null ? null : (string) $fila['imagen'],
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
