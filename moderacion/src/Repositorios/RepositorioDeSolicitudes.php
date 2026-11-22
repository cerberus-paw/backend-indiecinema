<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\DatosDeSala;
use IndieCinema\Moderacion\Modelo\Devolucion;
use IndieCinema\Moderacion\Modelo\Documento;
use IndieCinema\Moderacion\Modelo\EstadoDocumento;
use IndieCinema\Moderacion\Modelo\EstadoSolicitud;
use IndieCinema\Moderacion\Modelo\RequisitoDeSala;
use IndieCinema\Moderacion\Modelo\SolicitudDeSala;
use IndieCinema\Moderacion\Modelo\TipoDocumento;
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
                                   estado, enviada_en, resuelta_en, motivo, revision_motivo, revision_en, cambio,
                                   devolucion_motivo, devuelta_por, devuelta_en, devolucion_vence, devolucion_faltantes
                            FROM solicitud_sala';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * La solicitud y sus documentos (EF-07.4), juntos o ninguno.
     *
     * @param list<Documento> $documentos
     *
     * @throws SolicitudPendiente si la sala ya tiene una abierta
     */
    public function agregar(SolicitudDeSala $solicitud, array $documentos = []): void
    {
        $datos = $solicitud->datos;
        try {
            $this->base->enTransaccion(function (BaseDeDatos $base) use ($solicitud, $datos, $documentos): void {
                $base->ejecutar(
                    'INSERT INTO solicitud_sala (id, sala_id, usuario_id, solicitante_nombre, nombre, descripcion, direccion,
                        localidad, capacidad, peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, imagen, estado, enviada_en, cambio)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $solicitud->id, $solicitud->salaId, $solicitud->usuarioId, $solicitud->solicitanteNombre,
                        $datos->nombre, $datos->descripcion, $datos->direccion, $datos->localidad, $datos->capacidad,
                        $datos->peliculasPorFuncion, $datos->duracionFuncion, $datos->tiempoEntreFunciones,
                        $solicitud->imagen, $solicitud->estado->value, $solicitud->enviadaEn, (int) $solicitud->cambio,
                    ],
                );
                foreach ($documentos as $documento) {
                    $base->ejecutar(
                        'INSERT INTO documento (id, sala_id, solicitud_id, tipo, archivo, vence_en, subido_en, estado)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                        [
                            $documento->id, $documento->salaId, $documento->solicitudId, $documento->tipo->value,
                            $documento->archivo, $documento->venceEn->format('Y-m-d'), $documento->subidoEn, $documento->estado->value,
                        ],
                    );
                }
            });
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
     * Si la sala tiene una abierta (pendiente, en revisión manual o devuelta).
     */
    public function hayAbiertaDe(string $salaId): bool
    {
        $estados = self::valores(EstadoSolicitud::abiertas());

        return (int) $this->base->valor('SELECT COUNT(*) FROM solicitud_sala WHERE sala_id = ? AND estado IN (' . self::marcas($estados) . ')', [$salaId, ...$estados]) > 0;
    }

    /**
     * Cuántas mandó el usuario en total, para el historial del solicitante.
     */
    public function cuantasDe(string $usuarioId): int
    {
        return (int) $this->base->valor('SELECT COUNT(*) FROM solicitud_sala WHERE usuario_id = ?', [$usuarioId]);
    }

    /**
     * Las que esperan una resolución (las pendientes y las que están en revisión manual), primero
     * la que lleva más tiempo esperando; o las resueltas (el historial de decisiones), primero la
     * última que se resolvió. Las devueltas no están en ninguna de las dos: esperan al solicitante
     * (devueltas()).
     *
     * @param ?string      $texto  si viene, sólo las que lo tienen en el nombre o la localidad de
     *                             la sala, o en el nombre de quien la mandó. Como en la cartelera,
     *                             sin distinguir mayúsculas ni tildes, y va como parámetro, con los
     *                             comodines de LIKE escapados.
     * @param positive-int $limite
     *
     * @return list<SolicitudDeSala>
     */
    public function lista(bool $abiertas, ?string $texto, int $limite): array
    {
        [$filtro, $parametros] = self::filtro($abiertas, $texto);
        $orden = $abiertas ? 'enviada_en, id' : 'resuelta_en DESC, id';
        $parametros[] = $limite;

        return array_map(self::solicitud(...), $this->base->filas(self::SELECT . " WHERE {$filtro} ORDER BY {$orden} LIMIT ?", $parametros));
    }

    /**
     * Las que esperan al solicitante (EF-22.10), primero la que vence antes.
     *
     * @return list<SolicitudDeSala>
     */
    public function devueltas(): array
    {
        return array_map(self::solicitud(...), $this->base->filas(
            self::SELECT . ' WHERE estado = ? ORDER BY devolucion_vence, devuelta_en, id',
            [EstadoSolicitud::Devuelta->value],
        ));
    }

    /**
     * Las que vencieron desde esa fecha (EF-22.10), para avisarles.
     *
     * @return list<SolicitudDeSala>
     */
    public function vencidasDesde(DateTimeImmutable $desde): array
    {
        return array_map(self::solicitud(...), $this->base->filas(
            self::SELECT . ' WHERE estado = ? AND resuelta_en >= ? ORDER BY resuelta_en, id',
            [EstadoSolicitud::Vencida->value, $desde],
        ));
    }

    /**
     * La cierra como vencida si seguía devuelta (EF-22.10): terminal, con el motivo y sin quién.
     * Lo que quedaba pendiente de sus documentos se rechaza con ella, como al rechazarla. Todo
     * junto o nada.
     *
     * @return bool false si ya no estaba devuelta: no cambia nada
     */
    public function vencer(string $id, string $motivo, DateTimeImmutable $cuando): bool
    {
        return (bool) $this->base->enTransaccion(function (BaseDeDatos $base) use ($id, $motivo, $cuando): bool {
            $vencida = $base->ejecutar(
                'UPDATE solicitud_sala SET estado = ?, motivo = ?, resuelta_en = ? WHERE id = ? AND estado = ?',
                [EstadoSolicitud::Vencida->value, $motivo, $cuando, $id, EstadoSolicitud::Devuelta->value],
            ) === 1;
            if ($vencida) {
                $base->ejecutar(
                    "UPDATE documento SET estado = 'rechazado', motivo = ?, resuelto_en = ? WHERE solicitud_id = ? AND estado = 'pendiente'",
                    [$motivo, $cuando, $id],
                );
            }

            return $vencida;
        });
    }

    /**
     * Cuántas hay en la lista, aunque muestre menos.
     */
    public function cuantas(bool $abiertas, ?string $texto = null): int
    {
        [$filtro, $parametros] = self::filtro($abiertas, $texto);

        return (int) $this->base->valor('SELECT COUNT(*) FROM solicitud_sala WHERE ' . $filtro, $parametros);
    }

    /**
     * La deja resuelta si estaba abierta, y con ella sus documentos (EF-07.4): aprobarla los
     * acepta y rechazarla los rechaza (grilling de EF-07, Q6), y con el checklist que tildó quien la
     * resolvió (EF-07.9). Todo junto o nada.
     *
     * @param list<RequisitoDeSala> $tildados
     *
     * @return bool false si no estaba por resolver: no cambia nada
     */
    public function resolver(string $id, EstadoSolicitud $estado, string $resueltaPor, string $motivo, DateTimeImmutable $resueltaEn, array $tildados): bool
    {
        return (bool) $this->base->enTransaccion(function (BaseDeDatos $base) use ($id, $estado, $resueltaPor, $motivo, $resueltaEn, $tildados): bool {
            $porResolver = self::valores(EstadoSolicitud::porResolver());
            $resuelta = $base->ejecutar(
                'UPDATE solicitud_sala SET estado = ?, resuelta_por = ?, motivo = ?, resuelta_en = ?
                 WHERE id = ? AND estado IN (' . self::marcas($porResolver) . ')',
                [$estado->value, $resueltaPor, $motivo, $resueltaEn, $id, ...$porResolver],
            ) === 1;
            if (!$resuelta) {
                return false;
            }
            $documentos = match ($estado) {
                EstadoSolicitud::Aprobada => EstadoDocumento::Aceptado,
                EstadoSolicitud::Rechazada => EstadoDocumento::Rechazado,
                default => null,
            };
            if ($documentos !== null) {
                // Los que se rechazaron al devolverla (EF-22.10) quedan como historial.
                $base->ejecutar("UPDATE documento SET estado = ? WHERE solicitud_id = ? AND estado <> 'rechazado'", [$documentos->value, $id]);
            }
            self::registrarChecklist($base, $id, $estado, $resueltaPor, $resueltaEn, $tildados);

            return true;
        });
    }

    /**
     * La pasa a revisión manual (EF-07.6) si estaba pendiente: sigue abierta, con el motivo de la
     * revisión aparte del de la resolución, y con el checklist que tildó quien la pasó (EF-07.9).
     *
     * @param list<RequisitoDeSala> $tildados
     *
     * @return bool false si no estaba pendiente: no cambia nada
     */
    public function pasarARevision(string $id, string $revisadaPor, string $motivo, DateTimeImmutable $revisadaEn, array $tildados): bool
    {
        return (bool) $this->base->enTransaccion(function (BaseDeDatos $base) use ($id, $revisadaPor, $motivo, $revisadaEn, $tildados): bool {
            $pasada = $base->ejecutar(
                'UPDATE solicitud_sala SET estado = ?, revision_por = ?, revision_motivo = ?, revision_en = ? WHERE id = ? AND estado = ?',
                [EstadoSolicitud::EnRevisionManual->value, $revisadaPor, $motivo, $revisadaEn, $id, EstadoSolicitud::Pendiente->value],
            ) === 1;
            if ($pasada) {
                self::registrarChecklist($base, $id, EstadoSolicitud::EnRevisionManual, $revisadaPor, $revisadaEn, $tildados);
            }

            return $pasada;
        });
    }

    /**
     * Se la devuelve al solicitante (EF-22.10) si estaba pendiente o en revisión manual: queda
     * devuelta con la devolución, los documentos de los tipos que faltan quedan rechazados con el
     * motivo, quién y cuándo, y el checklist que tildó, en una fila «devuelta». Todo junto o nada.
     *
     * @param list<RequisitoDeSala> $tildados
     *
     * @return bool false si no estaba por resolver: no cambia nada
     */
    public function devolver(string $id, Devolucion $devolucion, array $tildados): bool
    {
        return (bool) $this->base->enTransaccion(function (BaseDeDatos $base) use ($id, $devolucion, $tildados): bool {
            $porResolver = self::valores(EstadoSolicitud::porResolver());
            $faltantes = self::valores($devolucion->faltantes);
            $devuelta = $base->ejecutar(
                'UPDATE solicitud_sala SET estado = ?, devolucion_motivo = ?, devuelta_por = ?, devuelta_en = ?, devolucion_vence = ?,
                    devolucion_faltantes = ? WHERE id = ? AND estado IN (' . self::marcas($porResolver) . ')',
                [
                    EstadoSolicitud::Devuelta->value, $devolucion->motivo, $devolucion->devueltaPor, $devolucion->devueltaEn,
                    $devolucion->vence->format('Y-m-d'), implode(',', $faltantes), $id, ...$porResolver,
                ],
            ) === 1;
            if (!$devuelta) {
                return false;
            }
            $base->ejecutar(
                "UPDATE documento SET estado = 'rechazado', motivo = ?, resuelto_por = ?, resuelto_en = ?
                 WHERE solicitud_id = ? AND estado <> 'rechazado' AND tipo IN (" . self::marcas($faltantes) . ')',
                [$devolucion->motivo, $devolucion->devueltaPor, $devolucion->devueltaEn, $id, ...$faltantes],
            );
            self::registrarChecklist($base, $id, EstadoSolicitud::Devuelta, $devolucion->devueltaPor, $devolucion->devueltaEn, $tildados);

            return true;
        });
    }

    /**
     * El solicitante completa la devuelta (EF-22.10): vuelve a pendiente, con los documentos nuevos,
     * pendientes. Todo junto o nada.
     *
     * @param list<Documento> $documentos
     *
     * @return bool false si ya no estaba devuelta: no cambia nada
     */
    public function completar(string $id, array $documentos): bool
    {
        return (bool) $this->base->enTransaccion(function (BaseDeDatos $base) use ($id, $documentos): bool {
            $completada = $base->ejecutar(
                'UPDATE solicitud_sala SET estado = ? WHERE id = ? AND estado = ?',
                [EstadoSolicitud::Pendiente->value, $id, EstadoSolicitud::Devuelta->value],
            ) === 1;
            if (!$completada) {
                return false;
            }
            foreach ($documentos as $documento) {
                $base->ejecutar(
                    'INSERT INTO documento (id, sala_id, solicitud_id, tipo, archivo, vence_en, subido_en, estado)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $documento->id, $documento->salaId, $documento->solicitudId, $documento->tipo->value,
                        $documento->archivo, $documento->venceEn->format('Y-m-d'), $documento->subidoEn, $documento->estado->value,
                    ],
                );
            }

            return true;
        });
    }

    /**
     * Lo que tildó cada moderador al resolverla (EF-07.9), del registro más viejo al más nuevo.
     *
     * @return list<array{resolucion: EstadoSolicitud, moderador_id: string, revisado_en: DateTimeImmutable, tildados: list<RequisitoDeSala>}>
     */
    public function revisionesDe(string $solicitudId): array
    {
        $columnas = array_map(static fn (RequisitoDeSala $requisito): string => $requisito->value, RequisitoDeSala::cases());

        return array_map(static fn (array $fila): array => [
            'resolucion' => EstadoSolicitud::from((string) $fila['resolucion']),
            'moderador_id' => (string) $fila['moderador_id'],
            'revisado_en' => new DateTimeImmutable((string) $fila['revisado_en'], new DateTimeZone('UTC')),
            'tildados' => array_values(array_filter(RequisitoDeSala::cases(), static fn (RequisitoDeSala $requisito): bool => (bool) $fila[$requisito->value])),
        ], $this->base->filas(
            'SELECT resolucion, moderador_id, revisado_en, ' . implode(', ', $columnas) . ' FROM revision_requisitos
             WHERE solicitud_id = ? ORDER BY id',
            [$solicitudId],
        ));
    }

    /**
     * Qué tildó el moderador al resolverla: una fila por resolución, con una columna por punto.
     *
     * @param list<RequisitoDeSala> $tildados
     */
    private static function registrarChecklist(BaseDeDatos $base, string $solicitudId, EstadoSolicitud $resolucion, string $moderadorId, DateTimeImmutable $revisadoEn, array $tildados): void
    {
        $columnas = array_map(static fn (RequisitoDeSala $requisito): string => $requisito->value, RequisitoDeSala::cases());
        $base->ejecutar(
            'INSERT INTO revision_requisitos (solicitud_id, moderador_id, resolucion, revisado_en, ' . implode(', ', $columnas) . ')
             VALUES (?, ?, ?, ?' . str_repeat(', ?', count($columnas)) . ')',
            [
                $solicitudId, $moderadorId, $resolucion->value, $revisadoEn,
                ...array_map(static fn (RequisitoDeSala $requisito): int => (int) in_array($requisito, $tildados, true), RequisitoDeSala::cases()),
            ],
        );
    }

    /**
     * @param list<EstadoSolicitud|TipoDocumento> $casos
     *
     * @return list<string>
     */
    private static function valores(array $casos): array
    {
        return array_map(static fn (EstadoSolicitud|TipoDocumento $caso): string => $caso->value, $casos);
    }

    /**
     * «?, ?, ?», una marca por valor.
     *
     * @param list<string> $valores
     */
    private static function marcas(array $valores): string
    {
        return implode(', ', array_fill(0, count($valores), '?'));
    }

    /**
     * Las por resolver o las terminadas (las devueltas, en ninguna de las dos).
     *
     * @return array{string, list<string>}
     */
    private static function filtro(bool $abiertas, ?string $texto): array
    {
        $parametros = self::valores($abiertas ? EstadoSolicitud::porResolver() : EstadoSolicitud::abiertas());
        $filtro = ($abiertas ? 'estado IN (' : 'estado NOT IN (') . self::marcas($parametros) . ')';
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
            $fila['revision_motivo'] === null ? null : (string) $fila['revision_motivo'],
            $fila['revision_en'] === null ? null : self::fecha($fila['revision_en']),
            (bool) $fila['cambio'],
            $fila['devuelta_en'] === null ? null : new Devolucion(
                array_values(array_filter(TipoDocumento::cases(), static fn (TipoDocumento $tipo): bool => in_array($tipo->value, explode(',', (string) $fila['devolucion_faltantes']), true))),
                (string) $fila['devolucion_motivo'],
                (string) $fila['devuelta_por'],
                self::fecha($fila['devuelta_en']),
                new DateTimeImmutable((string) $fila['devolucion_vence'], new DateTimeZone(Devolucion::ZONA)),
            ),
        );
    }

    private static function fecha(mixed $valor): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $valor, new DateTimeZone('UTC'));
    }
}
