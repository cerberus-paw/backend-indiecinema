<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use DateTimeImmutable;

/**
 * Del primer día al último, los dos incluidos, como los elige el organizador en el filtro.
 */
final readonly class Periodo
{
    /** Lo que muestra el reporte si no se elige nada: los últimos tres meses. */
    public const DIAS_POR_DEFECTO = 90;

    /** Un año y un día, como acepta programación. */
    public const DIAS_MAXIMOS = 366;

    private function __construct(
        public DateTimeImmutable $desde,
        public DateTimeImmutable $hasta,
    ) {
    }

    public static function porDefecto(DateTimeImmutable $hoy): self
    {
        $hasta = $hoy->setTime(0, 0);

        return new self($hasta->modify('-' . (self::DIAS_POR_DEFECTO - 1) . ' days'), $hasta);
    }

    /**
     * @throws ReglaIncumplida si un día no es una fecha, si están al revés o si son más de un año
     */
    public static function entre(string $desde, string $hasta): self
    {
        $primero = self::dia($desde) ?? throw new ReglaIncumplida('El primer día no es una fecha válida.');
        $ultimo = self::dia($hasta) ?? throw new ReglaIncumplida('El último día no es una fecha válida.');
        if ($ultimo < $primero) {
            throw new ReglaIncumplida('El último día no puede ser anterior al primero.');
        }
        if ($primero->diff($ultimo)->days > self::DIAS_MAXIMOS) {
            throw new ReglaIncumplida('El período puede ser de un año como mucho.');
        }

        return new self($primero, $ultimo);
    }

    private static function dia(string $texto): ?DateTimeImmutable
    {
        $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);

        return $dia === false || $dia->format('Y-m-d') !== $texto ? null : $dia;
    }
}
