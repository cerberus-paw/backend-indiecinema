<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\PropositoDeToken;
use IndieCinema\Cuentas\Modelo\TokenDeCuenta;
use IndieCinema\Nucleo\BaseDeDatos;

/**
 * Los tokens de un solo uso en el esquema de cuentas. Las fechas van y vienen en UTC, como en
 * sesion.
 */
final class RepositorioDeTokens
{
    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function agregar(TokenDeCuenta $token): void
    {
        $this->base->ejecutar(
            'INSERT INTO token_de_cuenta (huella, usuario_id, proposito, creado_en, vence_en, usado_en) VALUES (?, ?, ?, ?, ?, ?)',
            [$token->huella, $token->usuarioId, $token->proposito, $token->creadoEn, $token->venceEn, $token->usadoEn],
        );
    }

    public function buscar(string $huella, bool $bloquear = false): ?TokenDeCuenta
    {
        $bloqueo = $bloquear ? ' FOR UPDATE' : '';
        $fila = $this->base->fila(
            "SELECT huella, usuario_id, proposito, creado_en, vence_en, usado_en
             FROM token_de_cuenta
             WHERE huella = ?{$bloqueo}",
            [$huella],
        );
        if ($fila === null) {
            return null;
        }

        return new TokenDeCuenta(
            (string) $fila['huella'],
            (string) $fila['usuario_id'],
            PropositoDeToken::from((string) $fila['proposito']),
            self::fecha($fila['creado_en']),
            self::fecha($fila['vence_en']),
            $fila['usado_en'] === null ? null : self::fecha($fila['usado_en']),
        );
    }

    public function marcarUsado(string $huella, DateTimeImmutable $cuando): bool
    {
        return $this->base->ejecutar(
            'UPDATE token_de_cuenta SET usado_en = ? WHERE huella = ? AND usado_en IS NULL',
            [$cuando, $huella],
        ) === 1;
    }

    public function invalidarPendientes(string $usuarioId, PropositoDeToken $proposito, DateTimeImmutable $cuando): int
    {
        return $this->base->ejecutar(
            'UPDATE token_de_cuenta SET usado_en = ? WHERE usuario_id = ? AND proposito = ? AND usado_en IS NULL',
            [$cuando, $usuarioId, $proposito->value],
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $trabajo
     *
     * @return T
     */
    public function enTransaccion(callable $trabajo): mixed
    {
        return $this->base->enTransaccion(static fn (): mixed => $trabajo());
    }

    private static function fecha(mixed $valor): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $valor, new DateTimeZone('UTC'));
    }
}
