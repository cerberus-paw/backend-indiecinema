<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use IndieCinema\Nucleo\Configuracion;
use RuntimeException;

/**
 * Con qué se compara lo cobrado para saber si alguien gana o pierde plata (EF-22.1), de config.ini:
 *
 * - [reparto] comision_mercadopago: el porcentaje que se queda Mercado Pago de cada cobro. Se
 *   descuenta antes de repartir. Por omisión, el 7,61 % (6,29 % más IVA, con la plata disponible
 *   en el momento, en Checkout Pro de Argentina a noviembre de 2026).
 * - [reportes] costo_funcion: lo que le cuesta a una sala abrir para una función (alquiler, luz,
 *   la persona en la puerta), en pesos, cuando el organizador no cargó el suyo.
 * - [reportes] plataforma_servidores_mes y plataforma_desarrollo_mes: lo que paga la plataforma
 *   por mes: los dos servidores de AWS con el dominio, y el desarrollo y el mantenimiento.
 *
 * Son referencias, no la contabilidad de nadie: alcanzan para que el reporte diga si con estos
 * números la sala y la plataforma ganan o pierden, y qué pasa si cambian (el profe, 18/11). La
 * plata va en centavos enteros, como en el resto de recaudación.
 */
final readonly class CostosDeReferencia
{
    /** Hasta 99,99 %: con el 100 % no queda nada para repartir. */
    private const COMISION_MAXIMA = 9_999;

    public function __construct(
        /** En centésimos de punto: 761 es el 7,61 %. */
        public int $comisionCentesimos = 761,
        public int $costoFuncionCentavos = 5_000_000,
        public int $servidoresMesCentavos = 3_000_000,
        public int $desarrolloMesCentavos = 12_000_000,
    ) {
        if ($comisionCentesimos < 0 || $comisionCentesimos > self::COMISION_MAXIMA) {
            throw new RuntimeException('comision_mercadopago de [reparto] va de 0 a 99,99.');
        }
        foreach ([$costoFuncionCentavos, $servidoresMesCentavos, $desarrolloMesCentavos] as $costo) {
            if ($costo < 0) {
                throw new RuntimeException('Los costos de [reportes] no pueden ser negativos.');
            }
        }
    }

    /**
     * @throws RuntimeException si un valor no es un número o está fuera de rango
     */
    public static function desdeConfiguracion(Configuracion $configuracion): self
    {
        $porDefecto = new self();
        $numero = static function (string $clave, int $centesimos) use ($configuracion): int {
            $leido = $configuracion->obtener($clave, $centesimos / 100);
            if (!is_int($leido) && !is_float($leido) && !(is_string($leido) && is_numeric($leido))) {
                throw new RuntimeException("«{$clave}» de config.ini tiene que ser un número.");
            }

            return (int) round((float) $leido * 100);
        };

        return new self(
            $numero('reparto.comision_mercadopago', $porDefecto->comisionCentesimos),
            $numero('reportes.costo_funcion', $porDefecto->costoFuncionCentavos),
            $numero('reportes.plataforma_servidores_mes', $porDefecto->servidoresMesCentavos),
            $numero('reportes.plataforma_desarrollo_mes', $porDefecto->desarrolloMesCentavos),
        );
    }

    /** Lo que paga la plataforma por mes. */
    public function plataformaMesCentavos(): int
    {
        return $this->servidoresMesCentavos + $this->desarrolloMesCentavos;
    }
}
