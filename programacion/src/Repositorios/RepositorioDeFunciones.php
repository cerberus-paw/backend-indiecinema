<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\FuncionDelOrganizador;
use IndieCinema\Programacion\Modelo\FuncionFinalizada;
use IndieCinema\Programacion\Modelo\ObraVotada;
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
    private const COLUMNAS = 'id, sala_id, precio_base, cupo, estado, fecha_hora, votacion_cierra_en';

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
        return $this->resumenes([$id])[$id] ?? null;
    }

    /**
     * Varias de una vez, como resumen(): dos consultas para todas, en lugar de dos por función.
     * Las que no existen o cuya sala se dio de baja no vienen.
     *
     * @param list<string> $ids
     *
     * @return array<string, ResumenDeFuncion> con el id como clave
     */
    public function resumenes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($ids), '?'));
        $filas = $this->base->filas(
            "SELECT f.id, f.estado, f.fecha_hora, f.precio_base, f.cupo,
                    s.id AS sala_id, s.nombre AS sala_nombre, s.localidad AS sala_localidad,
                    s.direccion AS sala_direccion, s.habilitada AS sala_habilitada, s.organizador_id
             FROM funcion f
             JOIN sala s ON s.id = f.sala_id
             WHERE f.id IN ({$marcas}) AND s.dada_de_baja_en IS NULL",
            $ids,
        );
        if ($filas === []) {
            return [];
        }
        $encontradas = array_map(static fn (array $fila): string => (string) $fila['id'], $filas);
        $obrasPorFuncion = [];
        foreach ($this->base->filas(
            'SELECT fo.funcion_id, fo.estado, o.id, o.titulo FROM funcion_obra fo JOIN obra o ON o.id = fo.obra_id
             WHERE fo.funcion_id IN (' . implode(', ', array_fill(0, count($encontradas), '?')) . ')
             ORDER BY o.titulo, o.id',
            $encontradas,
        ) as $obra) {
            $obrasPorFuncion[(string) $obra['funcion_id']][] = $obra;
        }

        $resumenes = [];
        foreach ($filas as $fila) {
            $obras = $obrasPorFuncion[(string) $fila['id']] ?? [];
            // Como en buscar(): si ya hay elegidas son ésas; si no, las candidatas.
            $elegidas = array_filter($obras, static fn (array $obra): bool => $obra['estado'] === 'elegida');
            $proyectadas = $elegidas ?: $obras;
            $resumenes[(string) $fila['id']] = new ResumenDeFuncion(
                (string) $fila['id'],
                EstadoFuncion::from((string) $fila['estado']),
                $fila['fecha_hora'] === null ? null : new DateTimeImmutable((string) $fila['fecha_hora']),
                (float) $fila['precio_base'],
                (int) $fila['cupo'],
                array_values(array_map(static fn (array $obra): string => (string) $obra['titulo'], $proyectadas)),
                $elegidas !== [],
                (string) $fila['sala_id'],
                (string) $fila['sala_nombre'],
                (string) $fila['sala_localidad'],
                (bool) $fila['sala_habilitada'],
                (string) $fila['organizador_id'],
                array_values(array_map(static fn (array $obra): string => (string) $obra['id'], $proyectadas)),
                (string) $fila['sala_direccion'],
            );
        }

        return $resumenes;
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
     * El mayor cupo entre las funciones de la sala que siguen abiertas (en votación, en acuerdo de
     * fecha o programadas que todavía no se dieron): la capacidad de la sala no puede bajar de ahí,
     * porque el cupo de una función nunca supera la capacidad de su sala (F-01.1). 0 si no tiene.
     */
    public function mayorCupoAbierto(string $salaId, DateTimeImmutable $ahora = new DateTimeImmutable()): int
    {
        return (int) $this->base->valor(
            'SELECT COALESCE(MAX(cupo), 0) FROM funcion
             WHERE sala_id = ? AND estado IN (?, ?, ?) AND (fecha_hora IS NULL OR fecha_hora > ?)',
            [$salaId, EstadoFuncion::EnVotacionTitulos, EstadoFuncion::EnAcuerdoFecha, EstadoFuncion::Programada, $ahora],
        );
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

        return array_map(
            static fn (array $fila): FuncionDelOrganizador => self::funcionDelOrganizador($fila, $titulos[$fila['id']] ?? []),
            $filas,
        );
    }

    /**
     * Una función de las salas del organizador, como la muestra su panel, o null si no existe, es
     * de otro organizador o su sala se dio de baja.
     */
    public function delOrganizadorPorId(string $organizadorId, string $funcionId): ?FuncionDelOrganizador
    {
        $fila = $this->base->fila(
            'SELECT f.id, f.estado, f.fecha_hora, f.precio_base, f.cupo,
                    s.id AS sala_id, s.nombre AS sala_nombre, s.habilitada AS sala_habilitada
             FROM funcion f
             JOIN sala s ON s.id = f.sala_id
             WHERE f.id = ? AND s.organizador_id = ? AND s.dada_de_baja_en IS NULL',
            [$funcionId, $organizadorId],
        );
        if ($fila === null) {
            return null;
        }
        $obras = $this->base->filas(
            'SELECT fo.estado, o.titulo FROM funcion_obra fo JOIN obra o ON o.id = fo.obra_id
             WHERE fo.funcion_id = ? ORDER BY o.titulo, o.id',
            [$funcionId],
        );

        return self::funcionDelOrganizador($fila, $obras);
    }

    /**
     * @param array<string, mixed>       $fila
     * @param list<array<string, mixed>> $obras con el estado en funcion_obra y el título de la obra
     */
    private static function funcionDelOrganizador(array $fila, array $obras): FuncionDelOrganizador
    {
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
    }

    /**
     * Las funciones finalizadas de una sala, de la más reciente a la más vieja, para el historial
     * de proyecciones de su ficha (EF-04.3).
     *
     * @return list<ResumenDeFuncion>
     */
    public function finalizadasDeLaSala(string $salaId, int $cuantas): array
    {
        $ids = array_map(
            static fn (array $fila): string => (string) $fila['id'],
            $this->base->filas(
                'SELECT id FROM funcion WHERE sala_id = ? AND estado = ? ORDER BY fecha_hora DESC, id LIMIT ?',
                [$salaId, EstadoFuncion::Finalizada, $cuantas],
            ),
        );
        $resumenes = $this->resumenes($ids);

        // resumenes() no respeta el orden: se vuelve al de la consulta.
        return array_values(array_filter(array_map(static fn (string $id): ?ResumenDeFuncion => $resumenes[$id] ?? null, $ids)));
    }

    /**
     * Las programadas que ya terminaron (EF-04.1), de la que terminó primero a la última, con el
     * organizador de su sala. Una función termina a su hora más la duración de las funciones de
     * la sala, que es lo más que pueden durar sus obras (Sala::crearFuncion()): así no se da por
     * terminada una que todavía se está proyectando. También las de una sala dada de baja: no
     * vuelven a la cartelera, pero sus reservas también tienen que quedar al día.
     *
     * @return list<array{id: string, organizador_id: string}>
     */
    public function terminadas(DateTimeImmutable $ahora, int $cuantas): array
    {
        return array_map(
            static fn (array $fila): array => ['id' => (string) $fila['id'], 'organizador_id' => (string) $fila['organizador_id']],
            $this->base->filas(
                'SELECT f.id, s.organizador_id
                 FROM funcion f
                 JOIN sala s ON s.id = f.sala_id
                 WHERE f.estado = ? AND f.fecha_hora + INTERVAL s.duracion_funcion MINUTE <= ?
                 ORDER BY f.fecha_hora, f.id
                 LIMIT ?',
                [EstadoFuncion::Programada, $ahora, $cuantas],
            ),
        );
    }

    /**
     * Las funciones en votación de títulos que ya pasaron su fecha de cierre (EF-03.1), de la que
     * venció primero a la última. Las que no tienen fecha de cierre no: ésas sólo las cierra el
     * organizador. También las de una sala dada de baja o sin habilitar, para que ninguna quede
     * en votación para siempre.
     *
     * @return list<string> los ids
     */
    public function conVotacionVencida(DateTimeImmutable $ahora, int $cuantas): array
    {
        return array_map(
            static fn (array $fila): string => (string) $fila['id'],
            $this->base->filas(
                'SELECT id FROM funcion
                 WHERE estado = ? AND votacion_cierra_en <= ?
                 ORDER BY votacion_cierra_en, id
                 LIMIT ?',
                [EstadoFuncion::EnVotacionTitulos, $ahora, $cuantas],
            ),
        );
    }

    /**
     * Las programadas que empiezan después de $desde y hasta $hasta, como las piden los otros
     * subsistemas (EF-03.3: funciones, para el recordatorio del día antes), de la más próxima a
     * la última. Las de una sala dada de baja no, como en resumenes().
     *
     * @return list<ResumenDeFuncion>
     */
    public function programadasEntre(DateTimeImmutable $desde, DateTimeImmutable $hasta, int $cuantas): array
    {
        $ids = array_map(
            static fn (array $fila): string => (string) $fila['id'],
            $this->base->filas(
                'SELECT f.id FROM funcion f JOIN sala s ON s.id = f.sala_id
                 WHERE f.estado = ? AND f.fecha_hora > ? AND f.fecha_hora <= ? AND s.dada_de_baja_en IS NULL
                 ORDER BY f.fecha_hora, f.id
                 LIMIT ?',
                [EstadoFuncion::Programada, $desde, $hasta, $cuantas],
            ),
        );
        $resumenes = $this->resumenes($ids);

        // resumenes() no respeta el orden: se vuelve al de la consulta.
        return array_values(array_filter(array_map(static fn (string $id): ?ResumenDeFuncion => $resumenes[$id] ?? null, $ids)));
    }

    /**
     * Las finalizadas que empezaron entre $desde (incluido) y $hasta (excluido), de la primera a la
     * última, para la reportería de recaudación (EF-09): las de las salas de un organizador, las
     * que proyectaron alguna obra de un dueño, o todas. También las de una sala dada de baja: lo
     * que pasó en ella sigue contando para el período.
     *
     * Trae las obras de cada una con sus votos, y quiénes votaron, en dos consultas más para todas
     * juntas: una por función serían cientos de consultas para un período largo.
     *
     * @return list<FuncionFinalizada>
     */
    public function finalizadasEntre(
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        ?string $organizadorId,
        ?string $duenoId,
        int $cuantas,
    ): array {
        $condiciones = ['f.estado = ?', 'f.fecha_hora >= ?', 'f.fecha_hora < ?'];
        $valores = [EstadoFuncion::Finalizada, $desde, $hasta];
        if ($organizadorId !== null) {
            $condiciones[] = 's.organizador_id = ?';
            $valores[] = $organizadorId;
        }
        if ($duenoId !== null) {
            $condiciones[] = 'EXISTS (SELECT 1 FROM funcion_obra fo JOIN obra o ON o.id = fo.obra_id WHERE fo.funcion_id = f.id AND o.dueno_id = ?)';
            $valores[] = $duenoId;
        }
        $filas = $this->base->filas(
            'SELECT f.id, f.fecha_hora, f.cupo, f.precio_base, s.id AS sala_id, s.nombre AS sala_nombre, s.organizador_id
             FROM funcion f
             JOIN sala s ON s.id = f.sala_id
             WHERE ' . implode(' AND ', $condiciones) . '
             ORDER BY f.fecha_hora, f.id
             LIMIT ?',
            [...$valores, $cuantas],
        );
        if ($filas === []) {
            return [];
        }
        $ids = array_map(static fn (array $fila): string => (string) $fila['id'], $filas);
        $marcas = implode(', ', array_fill(0, count($ids), '?'));

        $obras = [];
        foreach ($this->base->filas(
            "SELECT fo.funcion_id, fo.estado, o.id, o.titulo, o.dueno_id,
                    (SELECT COUNT(*) FROM voto v WHERE v.funcion_id = fo.funcion_id AND v.obra_id = o.id) AS votos
             FROM funcion_obra fo
             JOIN obra o ON o.id = fo.obra_id
             WHERE fo.funcion_id IN ({$marcas})
             ORDER BY o.titulo, o.id",
            $ids,
        ) as $obra) {
            $obras[(string) $obra['funcion_id']][] = new ObraVotada(
                (string) $obra['id'],
                (string) $obra['titulo'],
                (string) $obra['dueno_id'],
                $obra['estado'] === 'elegida',
                (int) $obra['votos'],
            );
        }
        $votantes = [];
        foreach ($this->base->filas("SELECT funcion_id, usuario_id FROM voto WHERE funcion_id IN ({$marcas}) ORDER BY usuario_id", $ids) as $voto) {
            $votantes[(string) $voto['funcion_id']][] = (string) $voto['usuario_id'];
        }

        return array_map(static fn (array $fila): FuncionFinalizada => new FuncionFinalizada(
            (string) $fila['id'],
            new DateTimeImmutable((string) $fila['fecha_hora']),
            (int) $fila['cupo'],
            (string) $fila['precio_base'],
            (string) $fila['sala_id'],
            (string) $fila['sala_nombre'],
            (string) $fila['organizador_id'],
            $obras[(string) $fila['id']] ?? [],
            $votantes[(string) $fila['id']] ?? [],
        ), $filas);
    }

    /**
     * Las funciones en acuerdo de fecha, por id, de a $cuantas, después de $desdeId (EF-03.2). Su
     * fecha límite la sabe funciones, que es donde está el acuerdo: la tarea le pregunta por cada
     * una. Se recorren por id, y no se repite la consulta desde el principio, porque las que
     * todavía no vencieron siguen en acuerdo y volverían en cada tanda.
     *
     * @return list<string> los ids
     */
    public function enAcuerdoDeFecha(int $cuantas, string $desdeId = ''): array
    {
        return array_map(
            static fn (array $fila): string => (string) $fila['id'],
            $this->base->filas(
                'SELECT id FROM funcion WHERE estado = ? AND id > ? ORDER BY id LIMIT ?',
                [EstadoFuncion::EnAcuerdoFecha, $desdeId, $cuantas],
            ),
        );
    }

    /**
     * La función y sus obras juntas: una función sin obras no tiene que quedar en la base.
     */
    public function agregar(Funcion $funcion): void
    {
        $estadoDeLasObras = $funcion->estado === EstadoFuncion::EnVotacionTitulos ? 'candidata' : 'elegida';
        $this->base->enTransaccion(function (BaseDeDatos $base) use ($funcion, $estadoDeLasObras): void {
            $base->ejecutar(
                'INSERT INTO funcion (id, sala_id, precio_base, cupo, estado, fecha_hora, votacion_cierra_en) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $funcion->id, $funcion->salaId, self::precio($funcion->precioBase), $funcion->cupo,
                    $funcion->estado, $funcion->fechaHora, $funcion->votacionCierraEn,
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
     * Sólo se edita una programada, así que sus obras son las elegidas.
     *
     * Las candidatas que no salieron elegidas se quedan como están, para el historial de la
     * votación: se borran sólo las elegidas que ya no van, y una candidata que ahora se proyecta
     * pasa a elegida en vez de repetirse.
     */
    public function actualizar(Funcion $funcion): void
    {
        $this->base->enTransaccion(function (BaseDeDatos $base) use ($funcion): void {
            $base->ejecutar(
                'UPDATE funcion SET sala_id = ?, precio_base = ?, cupo = ?, fecha_hora = ? WHERE id = ?',
                [$funcion->salaId, self::precio($funcion->precioBase), $funcion->cupo, $funcion->fechaHora, $funcion->id],
            );
            $marcas = implode(', ', array_fill(0, count($funcion->obrasIds), '?'));
            $base->ejecutar(
                "DELETE FROM funcion_obra WHERE funcion_id = ? AND estado = 'elegida' AND obra_id NOT IN ({$marcas})",
                [$funcion->id, ...$funcion->obrasIds],
            );
            foreach ($funcion->obrasIds as $obraId) {
                $base->ejecutar(
                    "INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, 'elegida')
                     ON DUPLICATE KEY UPDATE estado = 'elegida'",
                    [$funcion->id, $obraId],
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
            $fila['votacion_cierra_en'] === null ? null : new DateTimeImmutable((string) $fila['votacion_cierra_en']),
        );
    }
}
