<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use DateTimeImmutable;

/**
 * Con cuánto se reparte lo cobrado de una función paga entre la sala, el dueño de la obra y la
 * plataforma, desde una fecha (EF-08.1).
 *
 * Los porcentajes van en centésimas de punto y no en float: 70 % es 7000, y la suma de los tres
 * tiene que dar 10000 exacto. Con 33,33 / 33,33 / 33,34 en float la suma no da 100 y el reparto
 * dejaría plata sin dueño; con enteros, da.
 *
 * No se corrige uno: cada cambio es otro parámetro con su vigencia, y el de una función es el
 * vigente en su fecha_hora, así un cambio no mueve lo que ya pasó.
 */
final readonly class ParametroDeReparto
{
    /** 100 %, en centésimas de punto. */
    public const TOTAL = 10_000;

    /**
     * @throws ReglaIncumplida si los tres no suman 100 % o si alguno es negativo
     */
    public function __construct(
        public string $id,
        public int $sala,
        public int $dueno,
        public int $plataforma,
        public DateTimeImmutable $vigenteDesde,
        public ?string $cargadoPor,
        public DateTimeImmutable $cargadoEn,
    ) {
        if ($sala < 0 || $dueno < 0 || $plataforma < 0) {
            throw new ReglaIncumplida('Ningún porcentaje del reparto puede ser negativo.');
        }
        if ($sala + $dueno + $plataforma !== self::TOTAL) {
            throw new ReglaIncumplida('Los tres porcentajes tienen que sumar 100 %.');
        }
    }

    /**
     * Un porcentaje escrito a mano o leído de la base, en centésimas de punto; null si no es un
     * porcentaje de 0 a 100 con hasta dos decimales. Acepta la coma, que es como se escribe en
     * castellano, y el punto, que es como lo manda un campo numérico y como lo devuelve MySQL.
     */
    public static function centesimas(string $texto): ?int
    {
        $limpio = str_replace(',', '.', trim($texto));
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $limpio, $partes) !== 1) {
            return null;
        }
        $centesimas = (int) $partes[1] * 100 + (int) str_pad($partes[2] ?? '', 2, '0');

        return $centesimas > self::TOTAL ? null : $centesimas;
    }

    /** 7050 → «70.50», como lo guarda la columna DECIMAL(5,2). */
    public static function comoDecimal(int $centesimas): string
    {
        return sprintf('%d.%02d', intdiv($centesimas, 100), $centesimas % 100);
    }

    /** 7000 → «70 %»; 7050 → «70,5 %». Los decimales sólo cuando los hay, como el resto del sitio. */
    public static function comoTexto(int $centesimas): string
    {
        $entero = (string) intdiv($centesimas, 100);
        $decimales = rtrim(sprintf('%02d', $centesimas % 100), '0');

        return ($decimales === '' ? $entero : "{$entero},{$decimales}") . ' %';
    }

    /** Si rige en ese momento: empezó a valer en él o antes. */
    public function rigeEn(DateTimeImmutable $momento): bool
    {
        return $this->vigenteDesde <= $momento;
    }
}
