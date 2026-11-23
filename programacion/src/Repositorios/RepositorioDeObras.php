<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\DatosDePedido;
use IndieCinema\Programacion\Modelo\EstadoObra;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\TipoObra;

/**
 * Las obras y sus géneros en el esquema de programación. Las dadas de baja no se ven desde acá:
 * para la aplicación ya no existen, aunque la fila siga en la tabla. El catálogo (el público y el
 * de las funciones) tiene sólo las aprobadas (EF-07.7); buscar() y delDueno() traen cualquiera.
 */
final class RepositorioDeObras
{
    private const COLUMNAS = 'id, dueno_id, titulo, sinopsis, anio, director, duracion, tipo, origen_copia, afiche, estado, motivo';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function buscar(string $id): ?Obra
    {
        $fila = $this->base->fila(
            'SELECT ' . self::COLUMNAS . ' FROM obra WHERE id = ? AND dada_de_baja_en IS NULL',
            [$id],
        );
        if ($fila === null) {
            return null;
        }
        $generos = $this->base->filas('SELECT genero_id FROM obra_genero WHERE obra_id = ? ORDER BY genero_id', [$id]);

        return self::obra($fila, array_column($generos, 'genero_id'));
    }

    /**
     * Cuánto duran juntas esas obras, en minutos: lo que ocupa la sala una función con esas
     * elegidas (EF-05.5), como la cuenta RepositorioDeFunciones::ocupadoEnLaSala(). Cuenta también
     * las dadas de baja, que siguen en las funciones que ya las tenían.
     *
     * @param list<string> $ids
     */
    public function duracionDe(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->base->valor(
            'SELECT COALESCE(SUM(duracion), 0) FROM obra WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids,
        );
    }

    /**
     * Todas las obras activas y aprobadas del catálogo, ordenadas por título.
     *
     * @return list<Obra>
     */
    public function todas(): array
    {
        $filas = $this->base->filas(
            "SELECT " . self::COLUMNAS . " FROM obra
             WHERE dada_de_baja_en IS NULL AND estado = 'aprobada'
             ORDER BY titulo, anio, id",
        );
        $generos = [];
        foreach ($this->base->filas(
            "SELECT og.obra_id, og.genero_id FROM obra_genero og
             JOIN obra o ON o.id = og.obra_id
             WHERE o.dada_de_baja_en IS NULL AND o.estado = 'aprobada'
             ORDER BY og.genero_id",
        ) as $genero) {
            $generos[$genero['obra_id']][] = $genero['genero_id'];
        }

        return array_map(static fn (array $fila): Obra => self::obra($fila, $generos[$fila['id']] ?? []), $filas);
    }

    /**
     * Las obras de un dueño, por título.
     *
     * @return list<Obra>
     */
    public function delDueno(string $duenoId): array
    {
        $filas = $this->base->filas(
            'SELECT ' . self::COLUMNAS . ' FROM obra
             WHERE dueno_id = ? AND dada_de_baja_en IS NULL
             ORDER BY titulo, id',
            [$duenoId],
        );
        // Los géneros de todas en una sola consulta, en vez de una por obra.
        $generos = [];
        foreach ($this->base->filas(
            'SELECT og.obra_id, og.genero_id FROM obra_genero og
             JOIN obra o ON o.id = og.obra_id
             WHERE o.dueno_id = ? AND o.dada_de_baja_en IS NULL
             ORDER BY og.genero_id',
            [$duenoId],
        ) as $genero) {
            $generos[$genero['obra_id']][] = $genero['genero_id'];
        }

        return array_map(static fn (array $fila): Obra => self::obra($fila, $generos[$fila['id']] ?? []), $filas);
    }

    /**
     * Obras del catálogo público que coinciden con el texto (en título o director) y/o el género.
     *
     * @return list<Obra>
     */
    public function buscarEnCatalogo(?string $texto = null, ?string $generoId = null): array
    {
        $condiciones = ['dada_de_baja_en IS NULL', "estado = 'aprobada'"];
        $parametros = [];

        if ($texto !== null && trim($texto) !== '') {
            $patron = Like::contiene(trim($texto));
            $condiciones[] = "(titulo LIKE ? ESCAPE '!' OR director LIKE ? ESCAPE '!')";
            $parametros[] = $patron;
            $parametros[] = $patron;
        }

        if ($generoId !== null && trim($generoId) !== '') {
            $condiciones[] = 'EXISTS (SELECT 1 FROM obra_genero og WHERE og.obra_id = obra.id AND og.genero_id = ?)';
            $parametros[] = trim($generoId);
        }

        $where = implode(' AND ', $condiciones);
        $filas = $this->base->filas(
            'SELECT ' . self::COLUMNAS . " FROM obra WHERE {$where} ORDER BY titulo, anio, id",
            $parametros,
        );

        if ($filas === []) {
            return [];
        }

        $ids = array_column($filas, 'id');
        $marcas = implode(', ', array_fill(0, count($ids), '?'));
        $generos = [];
        foreach ($this->base->filas(
            "SELECT og.obra_id, og.genero_id FROM obra_genero og
             WHERE og.obra_id IN ({$marcas})
             ORDER BY og.genero_id",
            $ids,
        ) as $genero) {
            $generos[$genero['obra_id']][] = $genero['genero_id'];
        }

        return array_map(static fn (array $fila): Obra => self::obra($fila, $generos[$fila['id']] ?? []), $filas);
    }

    /**
     * Obras activas y aprobadas del catálogo cuyo título coincide exactamente al normalizarlo
     * (sin mayúsculas, tildes ni signos o espacios de más), para evitar pedidos duplicados de
     * películas que ya están en IndieCinema (EF-22.6).
     *
     * @return list<Obra>
     */
    public function buscarAprobadasPorTituloNormalizado(string $titulo): array
    {
        $normalizado = DatosDePedido::normalizarTitulo($titulo);
        if ($normalizado === '') {
            return [];
        }

        return array_values(array_filter(
            $this->todas(),
            static fn (Obra $obra): bool => DatosDePedido::normalizarTitulo($obra->titulo) === $normalizado,
        ));
    }

    /**
     * Títulos aprobados del catálogo que coinciden con lo escrito, para autocompletar pedidos
     * existentes o ya cargados en IndieCinema (EF-22.6).
     *
     * @return list<array{id: string, titulo: string, titulo_normalizado: string, anio: int, director: string}>
     */
    public function sugerenciasPorTitulo(string $consulta = '', int $limite = 10): array
    {
        $normalizado = DatosDePedido::normalizarTitulo($consulta);
        $filas = $this->base->filas(
            "SELECT id, titulo, anio, director FROM obra
             WHERE dada_de_baja_en IS NULL AND estado = 'aprobada'
             ORDER BY titulo, anio, id",
        );

        $coincidencias = [];
        foreach ($filas as $fila) {
            $tituloNormalizado = DatosDePedido::normalizarTitulo((string) $fila['titulo']);
            if ($normalizado !== '' && !str_contains($tituloNormalizado, $normalizado)) {
                continue;
            }
            $coincidencias[] = [
                'id' => (string) $fila['id'],
                'titulo' => (string) $fila['titulo'],
                'titulo_normalizado' => $tituloNormalizado,
                'anio' => (int) $fila['anio'],
                'director' => (string) $fila['director'],
            ];
        }

        if ($normalizado !== '') {
            usort($coincidencias, static fn (array $a, array $b): int => ($b['titulo_normalizado'] === $normalizado) <=> ($a['titulo_normalizado'] === $normalizado));
        }

        return array_slice($coincidencias, 0, max(1, $limite));
    }

    /**
     * Las pendientes de revisión (EF-07.7), primero la que lleva más tiempo, con cuándo se cargó
     * (en la hora del sitio, como la guarda la base).
     *
     * @return list<array{Obra, string}>
     */
    public function pendientes(): array
    {
        $filas = $this->base->filas(
            'SELECT ' . self::COLUMNAS . ", creada_en FROM obra
             WHERE dada_de_baja_en IS NULL AND estado = 'pendiente'
             ORDER BY creada_en, id",
        );
        $generos = [];
        foreach ($this->base->filas(
            "SELECT og.obra_id, og.genero_id FROM obra_genero og
             JOIN obra o ON o.id = og.obra_id
             WHERE o.dada_de_baja_en IS NULL AND o.estado = 'pendiente'
             ORDER BY og.genero_id",
        ) as $genero) {
            $generos[$genero['obra_id']][] = $genero['genero_id'];
        }

        return array_map(static fn (array $fila): array => [self::obra($fila, $generos[$fila['id']] ?? []), (string) $fila['creada_en']], $filas);
    }

    /**
     * Cuándo se cargó (en la hora del sitio, como la guarda la base), para mostrarla en la bandeja
     * de moderación (EF-07.7).
     */
    public function creadaEn(string $id): ?string
    {
        $creada = $this->base->valor('SELECT creada_en FROM obra WHERE id = ? AND dada_de_baja_en IS NULL', [$id]);

        return $creada === null ? null : (string) $creada;
    }

    /**
     * La deja en ese estado con el motivo, si no lo estaba.
     *
     * @return bool false si ya estaba en ese estado (o no existe): no cambia nada
     */
    public function revisar(string $id, EstadoObra $estado, string $motivo): bool
    {
        return $this->base->enTransaccion(function (BaseDeDatos $base) use ($id, $estado, $motivo): bool {
            $cambio = $base->ejecutar(
                'UPDATE obra SET estado = ?, motivo = ? WHERE id = ? AND dada_de_baja_en IS NULL AND estado <> ?',
                [$estado->value, $motivo, $id, $estado->value],
            ) === 1;
            if ($cambio && $estado === EstadoObra::Rechazada) {
                self::sacarDeLasVotaciones($base, $id);
            }

            return $cambio;
        });
    }

    /**
     * Q16: la rechazada sale de las candidatas de las funciones en votación de títulos, con sus
     * votos, para que quien la votó elija otra. Si era la única, la votación se cancela y la obra
     * queda en ella, porque una función siempre tiene alguna; en votación no hay reservas que
     * cancelar. Los acuerdos y lo programado no cambian (Q3).
     */
    private static function sacarDeLasVotaciones(BaseDeDatos $base, string $obraId): void
    {
        $base->ejecutar(
            "DELETE v FROM voto v JOIN funcion f ON f.id = v.funcion_id
             WHERE v.obra_id = ? AND f.estado = 'en_votacion_titulos'",
            [$obraId],
        );
        $base->ejecutar(
            "UPDATE funcion f JOIN funcion_obra fo ON fo.funcion_id = f.id AND fo.obra_id = ?
             SET f.estado = 'cancelada'
             WHERE f.estado = 'en_votacion_titulos'
               AND NOT EXISTS (SELECT 1 FROM funcion_obra otra WHERE otra.funcion_id = f.id AND otra.obra_id <> fo.obra_id)",
            [$obraId],
        );
        $base->ejecutar(
            "DELETE fo FROM funcion_obra fo JOIN funcion f ON f.id = fo.funcion_id
             WHERE fo.obra_id = ? AND f.estado = 'en_votacion_titulos'",
            [$obraId],
        );
    }

    /**
     * Si la obra participa en funciones futuras (en votación de títulos, en acuerdo de fecha o
     * programada para hoy o más adelante en salas habilitadas).
     */
    public function tieneFuncionesFuturas(string $obraId): bool
    {
        $total = (int) $this->base->valor(
            "SELECT COUNT(*) FROM funcion_obra fo
             JOIN funcion f ON f.id = fo.funcion_id
             JOIN sala s ON s.id = f.sala_id
             WHERE fo.obra_id = ?
               AND s.habilitada = TRUE
               AND s.dada_de_baja_en IS NULL
               AND (
                   f.estado IN ('en_votacion_titulos', 'en_acuerdo_fecha')
                   OR (f.estado = 'programada' AND (f.fecha_hora IS NULL OR f.fecha_hora >= CURRENT_TIMESTAMP))
               )",
            [$obraId],
        );

        return $total > 0;
    }

    /**
     * La obra y sus géneros juntos: una obra sin géneros no tiene que quedar en la base.
     */
    public function agregar(Obra $obra): void
    {
        $this->base->enTransaccion(function (BaseDeDatos $base) use ($obra): void {
            $base->ejecutar(
                'INSERT INTO obra (id, dueno_id, titulo, sinopsis, anio, director, duracion, tipo, origen_copia, afiche, estado, motivo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $obra->id, $obra->duenoId, $obra->titulo, $obra->sinopsis, $obra->anio, $obra->director,
                    $obra->duracionMinutos, $obra->tipo->value, $obra->origenCopia->value, $obra->afiche,
                    $obra->estado->value, $obra->motivo,
                ],
            );
            foreach ($obra->generosIds as $generoId) {
                $base->ejecutar('INSERT INTO obra_genero (obra_id, genero_id) VALUES (?, ?)', [$obra->id, $generoId]);
            }
        });
    }

    /**
     * Actualiza los datos de la obra, su estado (EF-07.7: la rechazada que se corrige vuelve a
     * pendiente) y sus géneros en una transacción.
     */
    public function actualizar(Obra $obra): void
    {
        $this->base->enTransaccion(function (BaseDeDatos $base) use ($obra): void {
            $base->ejecutar(
                'UPDATE obra SET titulo = ?, sinopsis = ?, anio = ?, director = ?, duracion = ?, tipo = ?, origen_copia = ?, afiche = ?, estado = ?
                 WHERE id = ? AND dada_de_baja_en IS NULL',
                [
                    $obra->titulo, $obra->sinopsis, $obra->anio, $obra->director,
                    $obra->duracionMinutos, $obra->tipo->value, $obra->origenCopia->value, $obra->afiche,
                    $obra->estado->value, $obra->id,
                ],
            );
            $base->ejecutar('DELETE FROM obra_genero WHERE obra_id = ?', [$obra->id]);
            foreach ($obra->generosIds as $generoId) {
                $base->ejecutar('INSERT INTO obra_genero (obra_id, genero_id) VALUES (?, ?)', [$obra->id, $generoId]);
            }
        });
    }

    /**
     * Baja lógica: la obra no se borra porque las funciones pasadas siguen apuntando a ella.
     */
    public function darDeBaja(string $id): void
    {
        $this->base->ejecutar(
            'UPDATE obra SET dada_de_baja_en = CURRENT_TIMESTAMP WHERE id = ? AND dada_de_baja_en IS NULL',
            [$id],
        );
    }

    /**
     * @param array<string, mixed> $fila
     * @param list<string>         $generosIds
     */
    private static function obra(array $fila, array $generosIds): Obra
    {
        return new Obra(
            (string) $fila['id'],
            (string) $fila['dueno_id'],
            (string) $fila['titulo'],
            (string) $fila['sinopsis'],
            (int) $fila['anio'],
            (string) $fila['director'],
            (int) $fila['duracion'],
            TipoObra::from((string) $fila['tipo']),
            OrigenCopia::from((string) $fila['origen_copia']),
            $generosIds,
            $fila['afiche'] === null ? null : (string) $fila['afiche'],
            EstadoObra::from((string) $fila['estado']),
            $fila['motivo'] === null ? null : (string) $fila['motivo'],
        );
    }
}
