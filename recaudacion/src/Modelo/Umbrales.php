<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Modelo;

use IndieCinema\Nucleo\Configuracion;
use RuntimeException;

/**
 * Desde dónde el reporte sugiere algo (EF-09.1), en la sección [reportes] de config.ini. Los
 * porcentajes son enteros, de 0 a 100: las cuentas se hacen con enteros y un borde como «90 %»
 * no depende de un redondeo. Sin la sección, valen los del ejemplo.
 */
final readonly class Umbrales
{
    public function __construct(
        /** Un día de la semana con esta ocupación o menos sugiere una promoción… */
        public int $diaFlojoOcupacionMaxima = 50,
        /** …si ese día tuvo por lo menos estas funciones: con una sola, puede ser casualidad. */
        public int $diaFlojoFuncionesMinimas = 2,
        /** Un título para repetir llenó por lo menos este porcentaje de los lugares… */
        public int $repetirOcupacionMinima = 90,
        /** …fue por lo menos este porcentaje de los que reservaron… */
        public int $repetirAsistenciaMinima = 80,
        /** …y lo calificaron con este promedio o más, de 1 a 5. */
        public float $repetirPromedioMinimo = 4.0,
        /** Una obra que perdió con este porcentaje de los votos o más es algo que el público pidió. */
        public int $pedidoVotosMinimos = 30,
    ) {
        foreach ([$diaFlojoOcupacionMaxima, $repetirOcupacionMinima, $repetirAsistenciaMinima, $pedidoVotosMinimos] as $porcentaje) {
            if ($porcentaje < 0 || $porcentaje > 100) {
                throw new RuntimeException('Los porcentajes de [reportes] van de 0 a 100.');
            }
        }
        if ($diaFlojoFuncionesMinimas < 1) {
            throw new RuntimeException('dia_flojo_funciones_minimas de [reportes] tiene que ser 1 o más.');
        }
        if ($repetirPromedioMinimo < 1 || $repetirPromedioMinimo > 5) {
            throw new RuntimeException('repetir_promedio_minimo de [reportes] va de 1 a 5.');
        }
    }

    /**
     * @throws RuntimeException si un valor no es un número o está fuera de rango: el subsistema no
     *                          arranca con un umbral que no se entiende
     */
    public static function desdeConfiguracion(Configuracion $configuracion): self
    {
        $porDefecto = new self();
        $entero = static function (string $clave, int $valor) use ($configuracion): int {
            $leido = $configuracion->obtener("reportes.{$clave}", $valor);
            if (is_int($leido) || (is_string($leido) && preg_match('/^\d+$/', $leido) === 1)) {
                return (int) $leido;
            }
            throw new RuntimeException("«reportes.{$clave}» de config.ini tiene que ser un número entero.");
        };
        $promedio = $configuracion->obtener('reportes.repetir_promedio_minimo', $porDefecto->repetirPromedioMinimo);
        if (!is_numeric($promedio)) {
            throw new RuntimeException('«reportes.repetir_promedio_minimo» de config.ini tiene que ser un número.');
        }

        return new self(
            $entero('dia_flojo_ocupacion_maxima', $porDefecto->diaFlojoOcupacionMaxima),
            $entero('dia_flojo_funciones_minimas', $porDefecto->diaFlojoFuncionesMinimas),
            $entero('repetir_ocupacion_minima', $porDefecto->repetirOcupacionMinima),
            $entero('repetir_asistencia_minima', $porDefecto->repetirAsistenciaMinima),
            (float) $promedio,
            $entero('pedido_votos_minimos', $porDefecto->pedidoVotosMinimos),
        );
    }
}
