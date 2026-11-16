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
            $token = $this->tokens->buscar($huella, bloquear: true);
            if ($token === null || $token->proposito !== $proposito) {
                throw TokenInvalido::inexistente();
            }
            if ($token->estaUsado()) {
                throw TokenInvalido::yaUsado();
            }
            if ($token->estaVencido($instante)) {
                throw TokenInvalido::vencido();
            }
            if (!$this->tokens->marcarUsado($huella, $instante)) {
                throw TokenInvalido::yaUsado();
            }

            return $token->usuarioId;
        });
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

    private static function instante(?DateTimeImmutable $ahora): DateTimeImmutable
    {
        return ($ahora ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }
}
