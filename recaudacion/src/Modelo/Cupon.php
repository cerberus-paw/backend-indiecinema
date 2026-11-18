<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Un cupón de descuento canjeado con puntos (EF-17.1). Las reglas del programa son constantes:
 * 10 puntos por asistencia, y 100 puntos dan un cupón del 20 % que vence a los 60 días.
 */
final readonly class Cupon
{
    public const PUNTOS_POR_ASISTENCIA = 10;
    public const PUNTOS_POR_CUPON = 100;
    public const PORCENTAJE = 20;
    public const DIAS_DE_VIGENCIA = 60;

    /** Sin 0, O, 1, I ni L: se confunden al leerlos o al dictarlos. */
    private const LETRAS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        public string $codigo,
        public string $usuarioId,
        public int $porcentaje,
        public int $puntos,
        public DateTimeImmutable $creadoEn,
        public DateTimeImmutable $venceEn,
    ) {
    }

    public static function nuevo(string $usuarioId, DateTimeImmutable $ahora): self
    {
        $creado = $ahora->setTimezone(new DateTimeZone('UTC'));
        $codigo = '';
        for ($i = 0; $i < 8; ++$i) {
            $codigo .= self::LETRAS[random_int(0, strlen(self::LETRAS) - 1)];
        }

        return new self($codigo, $usuarioId, self::PORCENTAJE, self::PUNTOS_POR_CUPON, $creado, $creado->modify('+' . self::DIAS_DE_VIGENCIA . ' days'));
    }

    public function vencido(DateTimeImmutable $ahora): bool
    {
        return $this->venceEn <= $ahora;
    }
}
