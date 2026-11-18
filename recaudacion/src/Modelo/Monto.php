<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

/**
 * Plata, en centavos enteros. En recaudación no pasa por float en ningún lado: sumar «3500.00»
 * cien veces en float deja 349999,99999 y un reparto que no cierra. Los montos viajan como texto
 * con dos decimales, como los guarda la base y como los manda funciones (GET /interno/cobros), y
 * acá se pasan a enteros para hacer las cuentas.
 */
final class Monto
{
    /** «3500.00» → 350000. */
    public static function centavos(string $monto): int
    {
        [$enteros, $decimales] = explode('.', $monto . '.00');

        return (int) $enteros * 100 + (int) substr(str_pad($decimales, 2, '0'), 0, 2);
    }

    /** 350000 → «3500.00», con dos decimales como la base. */
    public static function comoDecimal(int $centavos): string
    {
        return sprintf('%d.%02d', intdiv($centavos, 100), $centavos % 100);
    }

    /** 15400000 → «$ 154.000», con los centavos sólo si los hay, como el resto del sitio. */
    public static function comoPesos(int $centavos): string
    {
        $texto = number_format(intdiv($centavos, 100), 0, ',', '.');

        return '$ ' . ($centavos % 100 === 0 ? $texto : $texto . ',' . sprintf('%02d', $centavos % 100));
    }
}
