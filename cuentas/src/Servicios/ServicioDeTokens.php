<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\PropositoDeToken;
use IndieCinema\Cuentas\Modelo\TokenDeCuenta;
use IndieCinema\Cuentas\Repositorios\RepositorioDeTokens;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;

/**
 * Emisión y consumo de tokens de un solo uso para verificación de correo y recuperación de
 * contraseña (X-02).
 */
final class ServicioDeTokens
{
    public function __construct(
        private readonly RepositorioDeTokens $tokens,
        private readonly RepositorioDeUsuarios $usuarios,
    ) {
    }

    /**
     * Emite un token al azar de 32 bytes en hexadecimal; en la base queda sólo su SHA-256.
     */
    public function emitir(
        string $usuarioId,
        PropositoDeToken $proposito,
        DateInterval|string $duracion,
        ?DateTimeImmutable $ahora = null,
    ): string {
        $instante = self::instante($ahora);
        $intervalo = $duracion instanceof DateInterval ? $duracion : new DateInterval($duracion);
        $valor = TokenDeCuenta::nuevoValor();
        $huella = (string) TokenDeCuenta::huellaDe($valor);
        $this->tokens->agregar(new TokenDeCuenta(
            $huella,
            $usuarioId,
            $proposito,
            $instante,
            $instante->add($intervalo),
            null,
        ));

        return $valor;
    }

    /**
     * Valida que el token exista, corresponda a $proposito y no esté usado ni vencido, sin consumirlo.
     *
     * @throws TokenInvalido
     */
    public function verificarDisponible(
        string $valor,
        PropositoDeToken $proposito,
        ?DateTimeImmutable $ahora = null,
    ): TokenDeCuenta {
        $huella = TokenDeCuenta::huellaDe($valor);
        if ($huella === null) {
            throw TokenInvalido::inexistente();
        }
        $token = $this->tokens->buscar($huella);

        return self::asegurarValido($token, $proposito, self::instante($ahora));
    }

    /**
     * Valida y marca como usado un token del propósito pedido; devuelve el id de la cuenta.
     *
     * @throws TokenInvalido
     */
    public function consumir(
        string $valor,
        PropositoDeToken $proposito,
        ?DateTimeImmutable $ahora = null,
    ): string {
        $huella = TokenDeCuenta::huellaDe($valor);
        if ($huella === null) {
            throw TokenInvalido::inexistente();
        }
        $instante = self::instante($ahora);

        return $this->tokens->enTransaccion(function () use ($huella, $proposito, $instante): string {
            $token = self::asegurarValido($this->tokens->buscar($huella, bloquear: true), $proposito, $instante);
            if (!$this->tokens->marcarUsado($huella, $instante)) {
                throw TokenInvalido::yaUsado();
            }

            return $token->usuarioId;
        });
    }

    private static function asegurarValido(
        ?TokenDeCuenta $token,
        PropositoDeToken $proposito,
        DateTimeImmutable $ahora,
    ): TokenDeCuenta {
        if ($token === null || $token->proposito !== $proposito) {
            throw TokenInvalido::inexistente();
        }
        if ($token->estaUsado()) {
            throw TokenInvalido::yaUsado();
        }
        if ($token->estaVencido($ahora)) {
            throw TokenInvalido::vencido();
        }

        return $token;
    }

    /**
     * Consume el token de verificación y marca el correo de la cuenta como verificado en la misma
     * transacción.
     *
     * @throws TokenInvalido
     */
    public function verificarCorreo(string $valor, ?DateTimeImmutable $ahora = null): string
    {
        $instante = self::instante($ahora);

        return $this->tokens->enTransaccion(function () use ($valor, $instante): string {
            $usuarioId = $this->consumir($valor, PropositoDeToken::Verificar, $instante);
            $this->usuarios->marcarCorreoVerificado($usuarioId, $instante);

            return $usuarioId;
        });
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
        return $this->tokens->enTransaccion($trabajo);
    }

    public function invalidarPendientes(
        string $usuarioId,
        PropositoDeToken $proposito,
        ?DateTimeImmutable $ahora = null,
    ): int {
        return $this->tokens->invalidarPendientes($usuarioId, $proposito, self::instante($ahora));
    }

    private static function instante(?DateTimeImmutable $ahora): DateTimeImmutable
    {
        return ($ahora ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }
}
