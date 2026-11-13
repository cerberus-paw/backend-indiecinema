<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\DiaFlojo;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\PedidoDelPublico;
use IndieCinema\Recaudacion\Modelo\RendimientoDeSala;
use IndieCinema\Recaudacion\Modelo\TituloParaRepetir;
use IndieCinema\Recaudacion\Modelo\Umbrales;

/**
 * Lo que el reporte sugiere hacer a partir del cruce (EF-09.1): no sólo cuánto se vendió, sino
 * qué conviene hacer con eso, como pidió el profe el 11/11 («tengo los datos y me sirven para
 * algo»). Cruza lo que sabe programación (cuándo, dónde, qué se votó) con lo que sabe funciones
 * (cuántos reservaron, cuántos fueron y qué les pareció).
 *
 * Las cuentas van en enteros: un porcentaje se compara multiplicando en cruz, así una sala que
 * vendió 36 de 40 lugares está en el 90 % justo y no en 89,999.
 */
final class Sugerencias
{
    public function __construct(private readonly Umbrales $umbrales)
    {
    }

    /**
     * Los días de la semana en que cada sala vende poco, comparados con los demás: ahí conviene
     * una promoción (el ejemplo del profe: «el miércoles no va nadie»). Un día cuenta si tuvo
     * varias funciones, vendió hasta el umbral y vendió menos que el resto de la semana.
     *
     * @return list<DiaFlojo> de la ocupación más baja a la más alta
     */
    public function diasFlojos(DatosDelReporte $datos): array
    {
        /** @var array<string, array<int, array{funciones: int, vendidas: int, cupo: int}>> $porSala */
        $porSala = [];
        $nombres = [];
        foreach ($datos->funciones as $funcion) {
            $ventas = $datos->ventasDe($funcion->id);
            if ($ventas === null) {
                continue;
            }
            $dia = (int) $funcion->fechaHora->format('N');
            $acumulado = $porSala[$funcion->salaId][$dia] ?? ['funciones' => 0, 'vendidas' => 0, 'cupo' => 0];
            $porSala[$funcion->salaId][$dia] = [
                'funciones' => $acumulado['funciones'] + 1,
                'vendidas' => $acumulado['vendidas'] + $ventas->vendidas,
                'cupo' => $acumulado['cupo'] + $funcion->cupo,
            ];
            $nombres[$funcion->salaId] = $funcion->salaNombre;
        }

        $flojos = [];
        foreach ($porSala as $salaId => $dias) {
            foreach ($dias as $dia => $esteDia) {
                if ($esteDia['funciones'] < $this->umbrales->diaFlojoFuncionesMinimas
                    || $esteDia['vendidas'] * 100 > $this->umbrales->diaFlojoOcupacionMaxima * $esteDia['cupo']) {
                    continue;
                }
                $restoVendidas = 0;
                $restoCupo = 0;
                foreach ($dias as $otro => $otroDia) {
                    if ($otro !== $dia) {
                        $restoVendidas += $otroDia['vendidas'];
                        $restoCupo += $otroDia['cupo'];
                    }
                }
                // Si los otros días tampoco venden más, una promoción ese día no cambia nada.
                if ($restoCupo > 0 && $esteDia['vendidas'] * $restoCupo >= $restoVendidas * $esteDia['cupo']) {
                    continue;
                }
                $flojos[] = new DiaFlojo(
                    (string) $salaId,
                    $nombres[$salaId],
                    $dia,
                    $esteDia['funciones'],
                    self::porcentaje($esteDia['vendidas'], $esteDia['cupo']),
                    $restoCupo > 0 ? self::porcentaje($restoVendidas, $restoCupo) : null,
                );
            }
        }
        usort($flojos, static fn (DiaFlojo $a, DiaFlojo $b): int => [$a->ocupacion, $a->salaNombre, $a->dia] <=> [$b->ocupacion, $b->salaNombre, $b->dia]);

        return $flojos;
    }

    /**
     * Las obras que se llenaron, a las que fue la gente que reservó y que gustaron: las tres
     * cosas, sumadas en todas sus funciones del período. Sin calificaciones no se sugiere: no se
     * sabe si gustó.
     *
     * @return list<TituloParaRepetir> de la que más se llenó a la que menos
     */
    public function titulosParaRepetir(DatosDelReporte $datos): array
    {
        $porObra = [];
        foreach ($datos->funciones as $funcion) {
            $ventas = $datos->ventasDe($funcion->id);
            if ($ventas === null) {
                continue;
            }
            $calificacion = $datos->calificacionDe($funcion->id);
            foreach ($funcion->elegidas() as $obra) {
                $acumulado = $porObra[$obra->id] ?? ['titulo' => $obra->titulo, 'funciones' => 0, 'vendidas' => 0, 'cupo' => 0, 'asistentes' => 0, 'puntos' => 0.0, 'calificaciones' => 0];
                $porObra[$obra->id] = [
                    'titulo' => $obra->titulo,
                    'funciones' => $acumulado['funciones'] + 1,
                    'vendidas' => $acumulado['vendidas'] + $ventas->vendidas,
                    'cupo' => $acumulado['cupo'] + $funcion->cupo,
                    'asistentes' => $acumulado['asistentes'] + $ventas->asistentes,
                    'puntos' => $acumulado['puntos'] + ($calificacion === null ? 0.0 : $calificacion->promedio * $calificacion->cantidad),
                    'calificaciones' => $acumulado['calificaciones'] + ($calificacion === null ? 0 : $calificacion->cantidad),
                ];
            }
        }

        $titulos = [];
        foreach ($porObra as $obraId => $obra) {
            if ($obra['vendidas'] === 0 || $obra['calificaciones'] === 0
                || $obra['vendidas'] * 100 < $this->umbrales->repetirOcupacionMinima * $obra['cupo']
                || $obra['asistentes'] * 100 < $this->umbrales->repetirAsistenciaMinima * $obra['vendidas']) {
                continue;
            }
            $promedio = round($obra['puntos'] / $obra['calificaciones'], 1);
            if ($promedio < $this->umbrales->repetirPromedioMinimo) {
                continue;
            }
            $titulos[] = new TituloParaRepetir(
                (string) $obraId,
                $obra['titulo'],
                $obra['funciones'],
                self::porcentaje($obra['vendidas'], $obra['cupo']),
                self::porcentaje($obra['asistentes'], $obra['vendidas']),
                $promedio,
            );
        }
        usort($titulos, static fn (TituloParaRepetir $a, TituloParaRepetir $b): int => [$b->ocupacion, $b->promedio, $a->titulo] <=> [$a->ocupacion, $a->promedio, $b->titulo]);

        return $titulos;
    }

    /**
     * Lo que el público votó y no vio: las obras que perdieron votaciones con buena parte de los
     * votos y no se proyectaron en ninguna función del período. Sólo necesita a programación: sale
     * aunque funciones no responda.
     *
     * @return list<PedidoDelPublico> de la más votada a la menos
     */
    public function pedidosDelPublico(DatosDelReporte $datos): array
    {
        $proyectadas = [];
        $porObra = [];
        foreach ($datos->funciones as $funcion) {
            foreach ($funcion->elegidas() as $obra) {
                $proyectadas[$obra->id] = true;
            }
            $total = $funcion->votos();
            if ($total === 0) {
                continue;
            }
            foreach ($funcion->obras as $obra) {
                if ($obra->elegida || $obra->votos === 0 || $obra->votos * 100 < $this->umbrales->pedidoVotosMinimos * $total) {
                    continue;
                }
                $acumulado = $porObra[$obra->id] ?? ['titulo' => $obra->titulo, 'votaciones' => 0, 'votos' => 0, 'total' => 0];
                $porObra[$obra->id] = [
                    'titulo' => $obra->titulo,
                    'votaciones' => $acumulado['votaciones'] + 1,
                    'votos' => $acumulado['votos'] + $obra->votos,
                    'total' => $acumulado['total'] + $total,
                ];
            }
        }

        $pedidos = [];
        foreach ($porObra as $obraId => $obra) {
            if (!isset($proyectadas[$obraId])) {
                $pedidos[] = new PedidoDelPublico((string) $obraId, $obra['titulo'], $obra['votaciones'], $obra['votos'], self::porcentaje($obra['votos'], $obra['total']));
            }
        }
        usort($pedidos, static fn (PedidoDelPublico $a, PedidoDelPublico $b): int => [$b->votos, $b->votaciones, $a->titulo] <=> [$a->votos, $a->votaciones, $b->titulo]);

        return $pedidos;
    }

    /**
     * Las salas ordenadas por lo que rinden: primero la ocupación, después la asistencia y el
     * promedio. Suma además cuántos de los que votaron títulos terminaron reservando, que es lo
     * que mide si la votación trae gente.
     *
     * @return list<RendimientoDeSala>
     */
    public function salasQueRinden(DatosDelReporte $datos): array
    {
        $porSala = [];
        foreach ($datos->funciones as $funcion) {
            $porSala[$funcion->salaId][] = $funcion;
        }
        $salas = [];
        foreach ($porSala as $funciones) {
            $rendimiento = $this->rendimiento($datos, $funciones);
            if ($rendimiento !== null) {
                $salas[] = $rendimiento;
            }
        }
        // Lo que falta (null) va al final: -1 queda debajo de cualquier porcentaje o promedio.
        usort($salas, static fn (RendimientoDeSala $a, RendimientoDeSala $b): int => [$b->ocupacion, $b->asistencia ?? -1, $b->promedio ?? -1, $a->salaNombre]
            <=> [$a->ocupacion, $a->asistencia ?? -1, $a->promedio ?? -1, $b->salaNombre]);

        return $salas;
    }

    /**
     * Lo mismo que una sala, para todas las funciones del período juntas: las tarjetas de arriba
     * del reporte. Null si funciones no respondió o no hay funciones.
     */
    public function total(DatosDelReporte $datos): ?RendimientoDeSala
    {
        return $this->rendimiento($datos, $datos->funciones);
    }

    /**
     * @param list<FuncionFinalizada> $funciones
     */
    private function rendimiento(DatosDelReporte $datos, array $funciones): ?RendimientoDeSala
    {
        $vendidas = 0;
        $cupo = 0;
        $asistentes = 0;
        $puntos = 0.0;
        $calificaciones = 0;
        $votantes = 0;
        $votantesQueReservaron = 0;
        $contadas = 0;
        foreach ($funciones as $funcion) {
            $ventas = $datos->ventasDe($funcion->id);
            if ($ventas === null) {
                continue;
            }
            ++$contadas;
            $vendidas += $ventas->vendidas;
            $cupo += $funcion->cupo;
            $asistentes += $ventas->asistentes;
            $calificacion = $datos->calificacionDe($funcion->id);
            if ($calificacion !== null) {
                $puntos += $calificacion->promedio * $calificacion->cantidad;
                $calificaciones += $calificacion->cantidad;
            }
            $votantes += count($funcion->votantesIds);
            $votantesQueReservaron += count(array_intersect($funcion->votantesIds, $ventas->compradoresIds));
        }
        if ($contadas === 0) {
            return null;
        }
        $primera = $funciones[0];

        return new RendimientoDeSala(
            $primera->salaId,
            $primera->salaNombre,
            $contadas,
            self::porcentaje($vendidas, $cupo),
            $vendidas === 0 ? null : self::porcentaje($asistentes, $vendidas),
            $calificaciones === 0 ? null : round($puntos / $calificaciones, 1),
            $votantes === 0 ? null : self::porcentaje($votantesQueReservaron, $votantes),
        );
    }

    /** Redondeado al entero más cercano, sin pasar por float. */
    private static function porcentaje(int $parte, int $total): int
    {
        return $total === 0 ? 0 : intdiv(200 * $parte + $total, 2 * $total);
    }
}
