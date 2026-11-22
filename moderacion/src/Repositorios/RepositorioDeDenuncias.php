<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Modelo\CategoriaDenuncia;
use IndieCinema\Moderacion\Modelo\Denuncia;
use IndieCinema\Moderacion\Modelo\GravedadDenuncia;
use IndieCinema\Moderacion\Modelo\TipoDenuncia;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Uuid;
use PDOException;

/**
 * Las denuncias (EF-07.8): las abiertas, agrupadas por lo denunciado, y las cerradas, para el
 * historial.
 */
final class RepositorioDeDenuncias
{
    /** El error de MySQL de una clave única repetida: ya tenía una abierta sobre eso. */
    private const CLAVE_DUPLICADA = 1062;

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * Con la gravedad de su motivo (EF-22.11).
     *
     * @param ?string $salaId la sala denunciada o la de la función; null en obras y comentarios
     * @param ?string $foto   el nombre de la foto guardada, en salas y funciones (EF-22.11)
     *
     * @return bool false si ese usuario ya tenía una abierta sobre eso
     */
    public function agregar(TipoDenuncia $tipo, string $objetoId, ?string $salaId, string $titulo, string $usuarioId, CategoriaDenuncia $categoria, ?string $detalle, DateTimeImmutable $creadaEn, ?string $foto = null): bool
    {
        try {
            $this->base->ejecutar(
                'INSERT INTO denuncia (id, tipo, objeto_id, sala_id, titulo, usuario_id, categoria, gravedad, detalle, foto, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [Uuid::nuevo(), $tipo->value, $objetoId, $salaId, $titulo, $usuarioId, $categoria->value, $categoria->gravedad()->value, $detalle, $foto, $creadaEn],
            );
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                return false;
            }
            throw $error;
        }

        return true;
    }

    public function tieneAbierta(TipoDenuncia $tipo, string $objetoId, string $usuarioId): bool
    {
        return $this->base->valor(
            "SELECT 1 FROM denuncia WHERE estado = 'abierta' AND tipo = ? AND objeto_id = ? AND usuario_id = ?",
            [$tipo->value, $objetoId, $usuarioId],
        ) !== null;
    }

    /**
     * Lo denunciado con denuncias abiertas, con el título de la última denuncia, cuántas tiene y
     * la más grave (EF-22.11). Primero las salas y las funciones con una alta abierta, que quedan
     * para suspender; después lo más grave, lo que tiene más y lo que lleva más tiempo esperando.
     *
     * @return list<array{tipo: TipoDenuncia, objeto_id: string, titulo: string, cantidad: int, gravedad: GravedadDenuncia, para_suspender: bool, sala_id: ?string, primera: DateTimeImmutable}>
     */
    public function abiertas(): array
    {
        return array_map(static fn (array $fila): array => [
            'tipo' => TipoDenuncia::from((string) $fila['tipo']),
            'objeto_id' => (string) $fila['objeto_id'],
            'titulo' => (string) $fila['titulo'],
            'cantidad' => (int) $fila['cantidad'],
            'gravedad' => GravedadDenuncia::deOrden((int) $fila['gravedad']),
            'para_suspender' => (bool) $fila['para_suspender'],
            'sala_id' => $fila['sala_id'] === null ? null : (string) $fila['sala_id'],
            'primera' => new DateTimeImmutable((string) $fila['primera'], new DateTimeZone('UTC')),
        ], $this->base->filas(
            // gravedad + 0: la posición en el ENUM, de menos a más grave.
            "SELECT tipo, objeto_id, MAX(titulo) AS titulo, COUNT(*) AS cantidad, MAX(gravedad + 0) AS gravedad,
                    tipo IN ('sala', 'funcion') AND MAX(gravedad = 'alta') AS para_suspender, MAX(sala_id) AS sala_id, MIN(creada_en) AS primera
             FROM denuncia WHERE estado = 'abierta'
             GROUP BY tipo, objeto_id
             ORDER BY para_suspender DESC, gravedad DESC, cantidad DESC, primera, tipo, objeto_id",
        ));
    }

    /**
     * Las abiertas de lo denunciado, primero la más grave y después la más vieja.
     *
     * @return list<Denuncia>
     */
    public function abiertasDe(TipoDenuncia $tipo, string $objetoId): array
    {
        return array_map(static fn (array $fila): Denuncia => new Denuncia(
            (string) $fila['id'],
            TipoDenuncia::from((string) $fila['tipo']),
            (string) $fila['objeto_id'],
            $fila['sala_id'] === null ? null : (string) $fila['sala_id'],
            (string) $fila['titulo'],
            (string) $fila['usuario_id'],
            CategoriaDenuncia::from((string) $fila['categoria']),
            GravedadDenuncia::from((string) $fila['gravedad']),
            $fila['detalle'] === null ? null : (string) $fila['detalle'],
            new DateTimeImmutable((string) $fila['creada_en'], new DateTimeZone('UTC')),
            $fila['foto'] === null ? null : (string) $fila['foto'],
        ), $this->base->filas(
            "SELECT id, tipo, objeto_id, sala_id, titulo, usuario_id, categoria, gravedad, detalle, foto, creada_en FROM denuncia
             WHERE estado = 'abierta' AND tipo = ? AND objeto_id = ?
             ORDER BY gravedad + 0 DESC, creada_en, id",
            [$tipo->value, $objetoId],
        ));
    }

    /**
     * El nombre de la foto de una denuncia (EF-22.11), o null si no tiene o no existe.
     */
    public function fotoDe(string $id): ?string
    {
        $foto = $this->base->valor('SELECT foto FROM denuncia WHERE id = ?', [$id]);

        return $foto === null ? null : (string) $foto;
    }

    /**
     * Cierra las abiertas de lo denunciado: desestimadas (sin acción) o resueltas con la acción.
     *
     * @return int cuántas cerró: 0 si ya no había abiertas
     */
    public function cerrar(TipoDenuncia $tipo, string $objetoId, ?string $accion, ?string $obraRechazadaId, string $motivo, string $moderadorId, DateTimeImmutable $cuando): int
    {
        return $this->base->ejecutar(
            "UPDATE denuncia SET estado = ?, accion = ?, obra_rechazada_id = ?, motivo = ?, resuelta_por = ?, resuelta_en = ?
             WHERE estado = 'abierta' AND tipo = ? AND objeto_id = ?",
            [$accion === null ? 'desestimada' : 'resuelta', $accion, $obraRechazadaId, $motivo, $moderadorId, $cuando, $tipo->value, $objetoId],
        );
    }

    /**
     * Las últimas cerradas, primero la más nueva.
     *
     * @param positive-int $limite
     *
     * @return list<array{tipo: TipoDenuncia, objeto_id: string, titulo: string, categoria: CategoriaDenuncia, gravedad: GravedadDenuncia, estado: string, accion: ?string, motivo: string, resuelta_por: string, resuelta_en: DateTimeImmutable}>
     */
    public function historial(int $limite): array
    {
        return array_map(static fn (array $fila): array => [
            'tipo' => TipoDenuncia::from((string) $fila['tipo']),
            'objeto_id' => (string) $fila['objeto_id'],
            'titulo' => (string) $fila['titulo'],
            'categoria' => CategoriaDenuncia::from((string) $fila['categoria']),
            'gravedad' => GravedadDenuncia::from((string) $fila['gravedad']),
            'estado' => (string) $fila['estado'],
            'accion' => $fila['accion'] === null ? null : (string) $fila['accion'],
            'motivo' => (string) $fila['motivo'],
            'resuelta_por' => (string) $fila['resuelta_por'],
            'resuelta_en' => new DateTimeImmutable((string) $fila['resuelta_en'], new DateTimeZone('UTC')),
        ], $this->base->filas(
            "SELECT tipo, objeto_id, titulo, categoria, gravedad, estado, accion, motivo, resuelta_por, resuelta_en FROM denuncia
             WHERE estado <> 'abierta'
             ORDER BY resuelta_en DESC, creada_en DESC, id LIMIT ?",
            [$limite],
        ));
    }

    /**
     * Las reglas (EF-22.11): por sala, cuántos denunciantes distintos tienen una denuncia media
     * resuelta, creada desde ese momento.
     *
     * @param list<string> $salaIds
     *
     * @return array<string, int> sala => conteo, sólo las que tienen alguna
     */
    public function mediasResueltasPorSala(array $salaIds, DateTimeImmutable $desde): array
    {
        if ($salaIds === []) {
            return [];
        }
        $marcas = implode(', ', array_fill(0, count($salaIds), '?'));
        $conteos = [];
        foreach ($this->base->filas(
            "SELECT sala_id, COUNT(DISTINCT usuario_id) AS conteo FROM denuncia
             WHERE sala_id IN ({$marcas}) AND estado = 'resuelta' AND gravedad = 'media' AND creada_en >= ?
             GROUP BY sala_id",
            [...$salaIds, $desde],
        ) as $fila) {
            $conteos[(string) $fila['sala_id']] = (int) $fila['conteo'];
        }

        return $conteos;
    }

    /**
     * Las salas con al menos esa cantidad en el conteo de las reglas, de más a menos.
     *
     * @return array<string, int> sala => conteo
     */
    public function salasConMediasResueltas(int $cantidad, DateTimeImmutable $desde): array
    {
        $salas = [];
        foreach ($this->base->filas(
            "SELECT sala_id, COUNT(DISTINCT usuario_id) AS conteo FROM denuncia
             WHERE sala_id IS NOT NULL AND estado = 'resuelta' AND gravedad = 'media' AND creada_en >= ?
             GROUP BY sala_id HAVING conteo >= ?
             ORDER BY conteo DESC, sala_id",
            [$desde, $cantidad],
        ) as $fila) {
            $salas[(string) $fila['sala_id']] = (int) $fila['conteo'];
        }

        return $salas;
    }
}
