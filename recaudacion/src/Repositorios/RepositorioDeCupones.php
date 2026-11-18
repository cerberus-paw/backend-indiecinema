<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Repositorios;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Recaudacion\Modelo\Cupon;
use PDOException;
use RuntimeException;

/**
 * Los cupones canjeados con puntos (EF-17.1).
 */
final class RepositorioDeCupones
{
    private const CLAVE_DUPLICADA = 1062;

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    /**
     * @return list<Cupon> del más nuevo al más viejo
     */
    public function deUsuario(string $usuarioId): array
    {
        return array_map(self::cupon(...), $this->base->filas(
            'SELECT * FROM cupon WHERE usuario_id = ? ORDER BY creado_en DESC, codigo',
            [$usuarioId],
        ));
    }

    public function buscar(string $codigo): ?Cupon
    {
        $fila = $this->base->fila('SELECT * FROM cupon WHERE codigo = ?', [$codigo]);

        return $fila === null ? null : self::cupon($fila);
    }

    public function puntosGastados(string $usuarioId): int
    {
        return (int) $this->base->valor('SELECT COALESCE(SUM(puntos), 0) FROM cupon WHERE usuario_id = ?', [$usuarioId]);
    }

    /**
     * @return bool false si el código ya existía (se sortea otro)
     */
    public function agregar(Cupon $cupon): bool
    {
        try {
            $this->base->ejecutar(
                'INSERT INTO cupon (codigo, usuario_id, porcentaje, puntos, creado_en, vence_en) VALUES (?, ?, ?, ?, ?, ?)',
                [$cupon->codigo, $cupon->usuarioId, $cupon->porcentaje, $cupon->puntos, $cupon->creadoEn, $cupon->venceEn],
            );

            return true;
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === self::CLAVE_DUPLICADA) {
                return false;
            }
            throw $error;
        }
    }

    /**
     * Corre el trabajo con el saldo del espectador bloqueado (un lock con nombre de MySQL): dos
     * canjes a la vez no gastan dos veces los mismos puntos.
     *
     * @template T
     *
     * @param Closure(): T $trabajo
     *
     * @return T
     */
    public function conElSaldoBloqueado(string $usuarioId, Closure $trabajo): mixed
    {
        $nombre = 'indiecinema-puntos-' . $usuarioId;
        if ((int) $this->base->valor('SELECT GET_LOCK(?, 5)', [$nombre]) !== 1) {
            throw new RuntimeException('No se pudo bloquear el saldo de puntos.');
        }
        try {
            return $trabajo();
        } finally {
            $this->base->valor('SELECT RELEASE_LOCK(?)', [$nombre]);
        }
    }

    /**
     * @param array<string, mixed> $fila
     */
    private static function cupon(array $fila): Cupon
    {
        return new Cupon(
            (string) $fila['codigo'],
            (string) $fila['usuario_id'],
            (int) $fila['porcentaje'],
            (int) $fila['puntos'],
            new DateTimeImmutable((string) $fila['creado_en'], new DateTimeZone('UTC')),
            new DateTimeImmutable((string) $fila['vence_en'], new DateTimeZone('UTC')),
        );
    }
}
