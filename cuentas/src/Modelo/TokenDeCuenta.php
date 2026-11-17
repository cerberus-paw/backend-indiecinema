<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

use DateTimeImmutable;

/**
 * Un token de un solo uso para verificar el correo o recuperar la contraseña (X-02).
 *
 * El valor crudo (32 bytes en hexadecimal) viaja en el enlace; la base guarda sólo su huella
 * SHA-256, igual que las sesiones.
 */
final readonly class TokenDeCuenta
{
    private const FORMATO_DEL_TOKEN = '/^[0-9a-f]{64}\z/';

    public function __construct(
        public string $huella,
        public string $usuarioId,
        public PropositoDeToken $proposito,
        public DateTimeImmutable $creadoEn,
        public DateTimeImmutable $venceEn,
        public ?DateTimeImmutable $usadoEn = null,
    ) {
    }

    /**
     * Genera 32 bytes al azar en hexadecimal (64 caracteres).
     */
    public static function nuevoValor(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * La huella SHA-256 que se guarda en la tabla, o null si no tiene el formato esperado.
     */
    public static function huellaDe(string $token): ?string
    {
        return preg_match(self::FORMATO_DEL_TOKEN, $token) === 1 ? hash('sha256', $token) : null;
    }

    public function estaUsado(): bool
    {
        return $this->usadoEn !== null;
    }

    public function estaVencido(DateTimeImmutable $ahora): bool
    {
        return $ahora >= $this->venceEn;
    }
}
