<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use DateTimeImmutable;
use LogicException;

/**
 * Del primer día al último, los dos incluidos, como los elige el organizador en el filtro.
 *
 * Cualquiera de los dos puede quedar vacío: sin el primero entra todo lo anterior al último, y sin
 * el último, todo desde el primero hasta hoy (lo que todavía no pasó no está finalizado). Sin
 * ninguno, todo: es lo que muestra el reporte si no se elige nada. Antes eran los últimos noventa
 * días y un año como mucho, y no se podía ver la historia entera de una sala.
 */
final readonly class Periodo
{
    /** El primer día cuando no se elige: antes de que existiera el sitio, así no queda nada afuera. */
    public const PRINCIPIO = '2000-01-01';

    private function __construct(
        public DateTimeImmutable $desde,
        public DateTimeImmutable $hasta,
        /** Si no se eligió el primer día: el filtro lo muestra vacío. */
        public bool $sinDesde = false,
        /** Si no se eligió el último día: el filtro lo muestra vacío. */
        public bool $sinHasta = false,
    ) {
    }

    /** Todo lo que pasó hasta hoy. */
    public static function todo(DateTimeImmutable $hoy): self
    {
        return self::entre('', '', $hoy);
    }

    /**
     * @param string $desde AAAA-MM-DD, o vacío para no poner piso
     * @param string $hasta AAAA-MM-DD, o vacío para llegar hasta hoy
     *
     * @throws ReglaIncumplida si un día no es una fecha o si están al revés
     */
    public static function entre(string $desde, string $hasta, DateTimeImmutable $hoy = new DateTimeImmutable('today')): self
    {
        $sinDesde = trim($desde) === '';
        $sinHasta = trim($hasta) === '';
        $primero = $sinDesde
            ? (self::dia(self::PRINCIPIO) ?? throw new LogicException('PRINCIPIO no es una fecha.'))
            : (self::dia($desde) ?? throw new ReglaIncumplida('El primer día no es una fecha válida.'));
        $ultimo = $sinHasta
            // Si el primero es posterior a hoy, el período es ese día: vacío, pero no al revés.
            ? max($hoy->setTime(0, 0), $primero)
            : (self::dia($hasta) ?? throw new ReglaIncumplida('El último día no es una fecha válida.'));
        if ($ultimo < $primero) {
            throw new ReglaIncumplida('El último día no puede ser anterior al primero.');
        }

        return new self($primero, $ultimo, $sinDesde, $sinHasta);
    }

    private static function dia(string $texto): ?DateTimeImmutable
    {
        $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);

        return $dia === false || $dia->format('Y-m-d') !== $texto ? null : $dia;
    }
}
