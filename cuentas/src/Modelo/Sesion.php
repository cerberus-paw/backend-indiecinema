<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

use DateInterval;
use DateTimeImmutable;

/**
 * Una sesión abierta. Es nuestra y no la de PHP (session_start): los subsistemas no guardan
 * estado, y la sesión la valida cuentas para nginx en cada pedido.
 *
 * Del identificador sólo se conoce la huella: el identificador en sí lo tiene la cookie del
 * navegador y nunca se guarda.
 */
final readonly class Sesion
{
    /** Cuánto dura desde que se abre, use o no use el sitio. */
    public const DURACION = 'P7D';

    /** 32 bytes de random_bytes, en hexadecimal: lo que viaja en la cookie. */
    private const FORMATO_DEL_IDENTIFICADOR = '/^[0-9a-f]{64}\z/';

    public function __construct(
        public string $huella,
        public string $usuarioId,
        public DateTimeImmutable $creadaEn,
        public DateTimeImmutable $usadaEn,
        public DateTimeImmutable $venceEn,
    ) {
    }

    public static function nueva(string $huella, string $usuarioId, DateTimeImmutable $ahora): self
    {
        return new self($huella, $usuarioId, $ahora, $ahora, $ahora->add(new DateInterval(self::DURACION)));
    }

    /**
     * Un identificador nuevo para la cookie, imposible de adivinar.
     */
    public static function nuevoIdentificador(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Lo que se guarda en lugar del identificador, o null si no tiene la forma de uno: así un
     * valor cualquiera en la cookie ni llega a la base.
     */
    public static function huellaDe(string $identificador): ?string
    {
        return preg_match(self::FORMATO_DEL_IDENTIFICADOR, $identificador) === 1 ? hash('sha256', $identificador) : null;
    }

    /**
     * Si la sesión sigue viva: hasta el instante del vencimiento, sin incluirlo.
     */
    public function sigueViva(DateTimeImmutable $ahora): bool
    {
        return $ahora < $this->venceEn;
    }
}
