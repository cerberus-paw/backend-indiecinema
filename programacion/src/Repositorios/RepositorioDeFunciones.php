<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\FuncionDelOrganizador;
use IndieCinema\Programacion\Modelo\ResumenDeFuncion;

/**
 * Las funciones y sus obras en el esquema de programación.
 *
 * En funcion_obra quedan todas las candidatas de la votación, y al cerrarla las que se proyectan
 * pasan a elegidas: las demás siguen ahí, para el historial de la votación. La función del
 * modelo, en cambio, tiene una sola lista: las candidatas mientras está en votación y después las
 * elegidas. Al leerla, si tiene elegidas son ésas; si no, todavía está en votación (o se canceló
 * en votación) y son las candidatas.
 */
final class RepositorioDeFunciones
{
    private const COLUMNAS = 'id, sala_id, precio_base, cupo, estado, fecha_hora';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * La función como la piden los otros subsistemas, con su sala y los títulos, o null si no
     * existe o su sala se dio de baja. Una sala sin habilitar sí aparece, marcada: quien la pide
     * decide qué hacer (no se reserva en una sala que no está publicada, por ejemplo).
     */
    public function resumen(string $id): ?ResumenDeFuncion
    {
        $fila = $this->base->fila(
            'SELECT f.id, f.estado, f.fecha_hora, f.precio_base, f.cupo,
                    s.id AS sala_id, s.nombre AS sala_nombre, s.localidad AS sala_localidad,
                    s.habilitada AS sala_habilitada, s.organizador_id
             FROM funcion f
             JOIN sala s ON s.id = f.sala_id
             WHERE f.id = ? AND s.dada_de_baja_en IS NULL',
            [$id],
        );
        if ($fila === null) {
            return null;
        }
        $obras = $this->base->filas(
            'SELECT fo.estado, o.titulo FROM funcion_obra fo JOIN obra o ON o.id = fo.obra_id
             WHERE fo.funcion_id = ? ORDER BY o.titulo, o.id',
            [$id],
        );
        // Como en buscar(): si ya hay elegidas son ésas; si no, las candidatas.
        $elegidas = array_filter($obras, static fn (array $obra): bool => $obra['estado'] === 'elegida');

        return new ResumenDeFuncion(
            (string) $fila['id'],
            EstadoFuncion::from((string) $fila['estado']),
            $fila['fecha_hora'] === null ? null : new DateTimeImmutable((string) $fila['fecha_hora']),
            (float) $fila['precio_base'],
            (int) $fila['cupo'],
            array_values(array_map(static fn (array $obra): string => (string) $obra['titulo'], $elegidas ?: $obras)),
            $elegidas !== [],
            (string) $fila['sala_id'],
            (string) $fila['sala_nombre'],
            (string) $fila['sala_localidad'],
            (bool) $fila['sala_habilitada'],
            (string) $fila['organizador_id'],
        );
    }

    public function buscar(string $id): ?Funcion
    {
        $fila = $this->base->fila('SELECT ' . self::COLUMNAS . ' FROM funcion WHERE id = ?', [$id]);
        if ($fila === null) {
            return null;
        }
        $obras = $this->base->filas(
            'SELECT funcion_id, obra_id, estado FROM funcion_obra WHERE funcion_id = ? ORDER BY obra_id',
            [$id],
        );

        return self::funcion($fila, $obras);
    }

    /**
     * Las funciones de una sala: primero las que tienen fecha, de la más próxima a la más lejana, y
     * después las que todavía no la tienen, de la más nueva a la más vieja.
     *
     * @return list<Funcion>
     */
    public function deLaSala(string $salaId): array
    {
        $filas = $this->base->filas(
            'SELECT ' . self::COLUMNAS . ' FROM funcion
             WHERE sala_id = ?
             ORDER BY fecha_hora IS NULL, fecha_hora, creada_en DESC, id',
            [$salaId],
        );
        // Las obras de todas en una sola consulta, en vez de una por función.
        $obras = [];
        foreach ($this->base->filas(
            'SELECT fo.funcion_id, fo.obra_id, fo.estado FROM funcion_obra fo
             JOIN funcion f ON f.id = fo.funcion_id
             WHERE f.sala_id = ?
             ORDER BY fo.obra_id',
            [$salaId],
        ) as $obra) {
            $obras[$obra['funcion_id']][] = $obra;
        }

        return array_map(static fn (array $fila): Funcion => self::funcion($fila, $obras[$fila['id']] ?? []), $filas);
    }

    /**
     * Desde y hasta cuándo va cada función programada de la sala: lo que duran sus obras elegidas.
     * Las canceladas no ocupan la sala, y las que todavía no tienen fecha tampoco.
     *
     * @return list<array{DateTimeImmutable, DateTimeImmutable}>
     */
    public function ocupadoEnLaSala(string $salaId, ?string $exceptoFuncionId = null): array
    {
        $parametros = [$salaId];
        $filtro = '';
        if ($exceptoFuncionId !== null) {
            $filtro = ' AND f.id != ?';
            $parametros[] = $exceptoFuncionId;
        }

        $filas = $this->base->filas(
            "SELECT f.fecha_hora, COALESCE(SUM(o.duracion), 0) AS duracion
             FROM funcion f
             LEFT JOIN funcion_obra fo ON fo.funcion_id = f.id AND fo.estado = 'elegida'
             LEFT JOIN obra o ON o.id = fo.obra_id
             WHERE f.sala_id = ? AND f.estado = 'programada'{$filtro}
             GROUP BY f.id, f.fecha_hora
             ORDER BY f.fecha_hora",
            $parametros,
        );

        return array_map(static function (array $fila): array {
            $desde = new DateTimeImmutable((string) $fila['fecha_hora']);

            return [$desde, $desde->modify('+' . (int) $fila['duracion'] . ' minutes')];
        }, $filas);
    }

    /**
     * Las funciones de todas las salas de un organizador, como las muestra su panel, en cualquier
     * estado: también las canceladas y las que todavía no tienen fecha.
     *
     * Arriba, las que siguen en curso, como las de una sala: la más próxima primero y al final las
     * que todavía no tienen fecha. Abajo, las terminadas (canceladas, finalizadas o con la fecha ya
     * pasada), la más reciente primero: así las viejas no tapan a las que vienen.
     *
     * Las de una sala dada de baja no están: la sala ya no se ve en ningún lado, así que sus
     * funciones tampoco tienen desde dónde seguirse.
     *
     * @return list<FuncionDelOrganizador>
     */
    public function delOrganizador(string $organizadorId, DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        $filas = $this->base->filas(
            'SELECT * FROM (
                SELECT f.id, f.estado, f.fecha_hora, f.precio_base, f.cupo, f.creada_en,
                       s.id AS sala_id, s.nombre AS sala_nombre, s.habilitada AS sala_habilitada,
                       (f.estado IN (?, ?) OR (f.fecha_hora IS NOT NULL AND f.fecha_hora <= ?)) AS terminada
                FROM funcion f
                JOIN sala s ON s.id = f.sala_id
                WHERE s.organizador_id = ? AND s.dada_de_baja_en IS NULL
             ) AS f
             ORDER BY f.terminada, f.fecha_hora IS NULL,
                      IF(f.terminada, NULL, f.fecha_hora), IF(f.terminada, f.fecha_hora, NULL) DESC,
                      f.creada_en DESC, f.id',
            [EstadoFuncion::Cancelada, EstadoFuncion::Finalizada, $ahora, $organizadorId],
        );
        // Los títulos de todas en una sola consulta, en vez de una por función.
        $titulos = [];
        foreach ($this->base->filas(
            'SELECT fo.funcion_id, fo.estado, o.titulo FROM funcion_obra fo
             JOIN obra o ON o.id = fo.obra_id
             JOIN funcion f ON f.id = fo.funcion_id
             JOIN sala s ON s.id = f.sala_id
             WHERE s.organizador_id = ? AND s.dada_de_baja_en IS NULL
             ORDER BY o.titulo, o.id',
            [$organizadorId],
        ) as $obra) {
            $titulos[$obra['funcion_id']][] = $obra;
        }

        return array_map(static function (array $fila) use ($titulos): FuncionDelOrganizador {
            $obras = $titulos[$fila['id']] ?? [];
            // Como en buscar(): si ya hay elegidas son ésas; si no, las candidatas.
            $elegidas = array_filter($obras, static fn (array $obra): bool => $obra['estado'] === 'elegida');

            return new FuncionDelOrganizador(
                (string) $fila['id'],
                EstadoFuncion::from((string) $fila['estado']),
                $fila['fecha_hora'] === null ? null : new DateTimeImmutable((string) $fila['fecha_hora']),
                (float) $fila['precio_base'],
                (int) $fila['cupo'],
                (string) $fila['sala_id'],
                (string) $fila['sala_nombre'],
                (bool) $fila['sala_habilitada'],
                array_values(array_map(static fn (array $obra): string => (string) $obra['titulo'], $elegidas ?: $obras)),
                $elegidas !== [],
            );
        }, $filas);
    }

    public function delOrganizadorPorId(string $organizadorId, string $funcionId): ?FuncionDelOrganizador
    {
        foreach ($this->delOrganizador($organizadorId) as $funcion) {
            if ($funcion->id === $funcionId) {
                return $funcion;
            }
        }

        return null;
    }

    /**
     * La función y sus obras juntas: una función sin obras no tiene que quedar en la base.
     */
    public function agregar(Funcion $funcion): void
    {
        $estadoDeLasObras = $funcion->estado === EstadoFuncion::EnVotacionTitulos ? 'candidata' : 'elegida';
        $this->base->enTransaccion(function (BaseDeDatos $base) use ($funcion, $estadoDeLasObras): void {
            $base->ejecutar(
                'INSERT INTO funcion (id, sala_id, precio_base, cupo, estado, fecha_hora) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $funcion->id, $funcion->salaId, self::precio($funcion->precioBase), $funcion->cupo,
                    $funcion->estado, $funcion->fechaHora,
                ],
            );
            foreach ($funcion->obrasIds as $obraId) {
                $base->ejecutar(
                    'INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, ?)',
                    [$funcion->id, $obraId, $estadoDeLasObras],
                );
            }
        });
    }

    /**
     * Guarda lo que cambia con el ciclo de vida: el estado, la fecha y, al cerrar la votación,
     * cuáles de las candidatas quedaron elegidas. La sala, el precio y el cupo no cambian por acá.
     *
     * Una cancelada no toca sus obras: cancelar() no las cambia, y si se canceló en votación,
     * sus obras son las candidatas y no tienen que quedar elegidas. Por eso cada cambio de estado
     * se guarda antes del siguiente.
     */
    public function guardar(Funcion $funcion): void
    {
        $this->base->enTransaccion(function (BaseDeDatos $base) use ($funcion): void {
            $base->ejecutar(
                'UPDATE funcion SET estado = ?, fecha_hora = ? WHERE id = ?',
                [$funcion->estado, $funcion->fechaHora, $funcion->id],
            );
            if (in_array($funcion->estado, [EstadoFuncion::EnVotacionTitulos, EstadoFuncion::Cancelada], true)) {
                return;
            }
            // Las obras de la función son un subconjunto de las candidatas (lo controla
            // cerrarVotacionTitulos()), así que alcanza con marcarlas.
            $marcas = implode(', ', array_fill(0, count($funcion->obrasIds), '?'));
            $base->ejecutar(
                "UPDATE funcion_obra SET estado = 'elegida' WHERE funcion_id = ? AND obra_id IN ({$marcas})",
                [$funcion->id, ...$funcion->obrasIds],
            );
        });
    }

    /**
     * Persiste los cambios de la función editada (E3-11.3): sala, obras, fecha/hora, precio y cupo.
     */
    public function actualizar(Funcion $funcion): void
    {
        $estadoDeLasObras = $funcion->estado === EstadoFuncion::EnVotacionTitulos ? 'candidata' : 'elegida';
        $this->base->enTransaccion(function (BaseDeDatos $base) use ($funcion, $estadoDeLasObras): void {
            $base->ejecutar(
                'UPDATE funcion SET sala_id = ?, precio_base = ?, cupo = ?, estado = ?, fecha_hora = ? WHERE id = ?',
                [
                    $funcion->salaId, self::precio($funcion->precioBase), $funcion->cupo,
                    $funcion->estado, $funcion->fechaHora, $funcion->id,
                ],
            );
            $base->ejecutar('DELETE FROM funcion_obra WHERE funcion_id = ?', [$funcion->id]);
            foreach ($funcion->obrasIds as $obraId) {
                $base->ejecutar(
                    'INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, ?)',
                    [$funcion->id, $obraId, $estadoDeLasObras],
                );
            }
        });
    }

    /**
     * El precio como texto con dos decimales, para que la columna DECIMAL lo guarde exacto y no
     * pase por la conversión de un float.
     */
    private static function precio(float $precio): string
    {
        return number_format($precio, 2, '.', '');
    }

    /**
     * @param array<string, mixed>       $fila
     * @param list<array<string, mixed>> $obras las filas de funcion_obra de esta función
     */
    private static function funcion(array $fila, array $obras): Funcion
    {
        $elegidas = array_filter($obras, static fn (array $obra): bool => $obra['estado'] === 'elegida');

        return new Funcion(
            (string) $fila['id'],
            (string) $fila['sala_id'],
            array_values(array_map(static fn (array $obra): string => (string) $obra['obra_id'], $elegidas ?: $obras)),
            (float) $fila['precio_base'],
            (int) $fila['cupo'],
            EstadoFuncion::from((string) $fila['estado']),
            $fila['fecha_hora'] === null ? null : new DateTimeImmutable((string) $fila['fecha_hora']),
        );
    }
}
