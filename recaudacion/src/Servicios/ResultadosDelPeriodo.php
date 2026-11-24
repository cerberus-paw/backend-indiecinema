<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Recaudacion\Modelo\CostosDeReferencia;
use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\Monto;
use IndieCinema\Recaudacion\Modelo\Periodo;
use IndieCinema\Recaudacion\Modelo\ReglaIncumplida;
use IndieCinema\Recaudacion\Modelo\Reparto;
use IndieCinema\Recaudacion\Modelo\ResultadoDePlataforma;
use IndieCinema\Recaudacion\Modelo\ResultadoDeSala;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeCostosDeSalas;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeRepartos;

/**
 * Si cada sala y la plataforma ganan o pierden plata en el período (EF-22.1). Lo que el profe
 * preguntó el 18/11: «¿ganó plata o perdió plata con el sistema?».
 *
 * La plata sale del reparto que ya se hizo de cada función (EF-08.2), que es lo que de verdad se le
 * liquida a cada uno, con la comisión de Mercado Pago ya descontada. Una función que terminó y
 * todavía no se repartió (la tarea corre cada hora) se calcula igual, con lo cobrado que dice
 * funciones y los porcentajes de su fecha, y se avisa cuántas son.
 */
final class ResultadosDelPeriodo
{
    /** Los costos de la plataforma vienen por mes; se cuentan por día, con meses de treinta. */
    private const DIAS_DEL_MES = 30;

    /** El costo más alto que se acepta por función, en centavos: más es un error de tipeo. */
    public const COSTO_MAXIMO = 10_000_000_00;

    private readonly CostosDeReferencia $referencia;

    public function __construct(
        private readonly RepositorioDeRepartos $repartos,
        private readonly RepositorioDeCostosDeSalas $costos,
        private readonly ServicioDeParametrosDeReparto $parametros,
        Configuracion $configuracion,
    ) {
        $this->referencia = CostosDeReferencia::desdeConfiguracion($configuracion);
    }

    public function referencia(): CostosDeReferencia
    {
        return $this->referencia;
    }

    /**
     * @return list<ResultadoDeSala> de la que más pierde a la que más gana: lo que hay que mirar
     *                               primero arriba
     */
    public function salas(DatosDelReporte $datos): array
    {
        $porSala = [];
        foreach ($datos->funciones as $funcion) {
            $porSala[$funcion->salaId][] = $funcion;
        }
        $repartidas = $this->repartos->sumasPorRol(array_map(static fn (FuncionFinalizada $funcion): string => $funcion->id, $datos->funciones));
        $cargados = $this->costos->deLasSalas(array_map('strval', array_keys($porSala)));

        $resultados = [];
        foreach ($porSala as $salaId => $funciones) {
            $sumas = ['cobrado' => 0, 'comision' => 0, 'sala' => 0, 'dueno' => 0, 'plataforma' => 0];
            $estimadas = 0;
            $sinDatos = 0;
            foreach ($funciones as $funcion) {
                $deLaFuncion = $repartidas[$funcion->id] ?? null;
                if ($deLaFuncion === null) {
                    $deLaFuncion = $this->estimar($funcion, $datos);
                    if ($deLaFuncion === null) {
                        ++$sinDatos;
                        continue;
                    }
                    ++$estimadas;
                }
                foreach ($sumas as $clave => $suma) {
                    $sumas[$clave] = $suma + $deLaFuncion[$clave];
                }
            }
            $salaId = (string) $salaId;
            $resultados[] = new ResultadoDeSala(
                $salaId,
                $funciones[0]->salaNombre,
                count($funciones),
                $sumas['cobrado'],
                $sumas['comision'],
                $sumas['sala'],
                $sumas['dueno'],
                $sumas['plataforma'],
                $cargados[$salaId] ?? $this->referencia->costoFuncionCentavos,
                isset($cargados[$salaId]),
                $estimadas,
                $sinDatos,
            );
        }
        usort($resultados, static fn (ResultadoDeSala $a, ResultadoDeSala $b): int => [$a->resultado(), $a->salaNombre] <=> [$b->resultado(), $b->salaNombre]);

        return $resultados;
    }

    /**
     * La parte de la plataforma de todas las salas contra lo que le cuestan los días del período:
     * del primer día elegido (o de la primera función, si no se eligió) al último.
     *
     * @param list<ResultadoDeSala> $salas
     */
    public function plataforma(DatosDelReporte $datos, array $salas): ?ResultadoDePlataforma
    {
        if ($datos->funciones === []) {
            return null;
        }
        $periodo = $datos->periodo;
        $desde = $periodo->sinDesde
            ? min(array_map(static fn (FuncionFinalizada $funcion): DateTimeImmutable => $funcion->fechaHora->setTime(0, 0), $datos->funciones))
            : $periodo->desde;
        $dias = (int) $desde->diff($periodo->hasta->setTime(0, 0))->days + 1;
        $ingresos = array_sum(array_map(static fn (ResultadoDeSala $sala): int => $sala->plataforma, $salas));

        return new ResultadoDePlataforma(
            $ingresos,
            $dias,
            intdiv($this->referencia->servidoresMesCentavos * $dias, self::DIAS_DEL_MES),
            intdiv($this->referencia->desarrolloMesCentavos * $dias, self::DIAS_DEL_MES),
        );
    }

    /**
     * Carga el costo por función de una sala. Que quien lo carga sea su organizador o el
     * administrador lo controla el controlador.
     *
     * @param string $pesos como lo escribió: «45000», «45.000» o «45000,50»
     *
     * @throws ReglaIncumplida si no es un monto de 0 a 10 millones
     */
    public function cargarCosto(string $salaId, string $pesos, string $autorId, DateTimeImmutable $cuando = new DateTimeImmutable()): void
    {
        $limpio = str_replace(['$', ' ', '.'], '', trim($pesos));
        if (preg_match('/^\d{1,10}(,\d{1,2})?$/D', $limpio) !== 1) {
            throw new ReglaIncumplida('Escribí el costo en pesos, sin signos: por ejemplo, 45000.');
        }
        $centavos = Monto::centavos(str_replace(',', '.', $limpio));
        if ($centavos > self::COSTO_MAXIMO) {
            throw new ReglaIncumplida('El costo por función puede ser de hasta $ 10.000.000.');
        }
        $this->costos->guardar($salaId, $centavos, $autorId, $cuando);
    }

    /**
     * El reparto que le va a tocar a una función que terminó y todavía no se repartió, con el mismo
     * cálculo que la tarea. Null si funciones no dijo cuánto cobró.
     *
     * @return ?array{cobrado: int, comision: int, sala: int, dueno: int, plataforma: int}
     */
    private function estimar(FuncionFinalizada $funcion, DatosDelReporte $datos): ?array
    {
        $vacia = ['cobrado' => 0, 'comision' => 0, 'sala' => 0, 'dueno' => 0, 'plataforma' => 0];
        if ($funcion->esALaGorra()) {
            return $vacia;
        }
        $ventas = $datos->ventasDe($funcion->id);
        if ($ventas === null) {
            return null;
        }
        $cobrado = Monto::centavos($ventas->cobrado);
        $parametro = $this->parametros->vigenteEn($funcion->fechaHora);
        if ($cobrado === 0 || $parametro === null) {
            return ['cobrado' => $cobrado] + $vacia;
        }
        try {
            $reparto = Reparto::calcular($funcion, $cobrado, $parametro, comisionCentesimos: $this->referencia->comisionCentesimos);
        } catch (ReglaIncumplida) {
            return ['cobrado' => $cobrado] + $vacia;
        }
        $sumas = ['cobrado' => $cobrado, 'comision' => $reparto->comisionCentavos] + $vacia;
        foreach ($reparto->partes as $parte) {
            $sumas[$parte->rol->value] += $parte->centavos;
        }

        return $sumas;
    }
}
