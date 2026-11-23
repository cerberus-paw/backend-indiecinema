<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\DiaFlojo;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\ObraAgotada;
use IndieCinema\Recaudacion\Modelo\PedidoDelPublico;
use IndieCinema\Recaudacion\Modelo\PrecioDeLaEntrada;
use IndieCinema\Recaudacion\Modelo\RecomendacionCombinada;
use IndieCinema\Recaudacion\Modelo\RendimientoDeSala;
use IndieCinema\Recaudacion\Modelo\ResultadoDeSala;
use IndieCinema\Recaudacion\Modelo\SalaSinPromocion;
use IndieCinema\Recaudacion\Modelo\TituloParaRepetir;
use IndieCinema\Recaudacion\Modelo\Umbrales;
use IndieCinema\Recaudacion\Modelo\VotantesSinReserva;

/**
 * Lo que el reporte sugiere hacer a partir del cruce (EF-09.1): no sólo cuánto se vendió, sino
 * qué conviene hacer con eso, como pidió el profe el 11/11 («tengo los datos y me sirven para
 * algo»). Cruza lo que sabe programación (cuándo, dónde, qué se votó) con lo que sabe funciones
 * (cuántos reservaron, cuántos fueron y qué les pareció).
 *
 * Las cuentas van en enteros: un porcentaje se compara multiplicando en cruz, así una sala que
 * vendió 36 de 40 lugares está en el 90 % justo y no en 89,999.
 *
 * Con lo que ganó cada sala (EF-22.1), a la que gana holgado no le sugiere promociones ni
 * descuentos (EF-22.14): no los necesita. Sí le sigue sugiriendo lo que le suma, como otra función
 * de lo que se agota o cambiar un horario.
 */
final class Sugerencias
{
    private const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábados', 7 => 'domingos'];

    /** @var array<string, SalaSinPromocion> por id de sala */
    private readonly array $sinPromocion;

    /**
     * @param list<ResultadoDeSala> $resultados si cada sala ganó o perdió en el período; sin ellos
     *                                          (vacía) no se sabe quién gana y no se filtra nada
     */
    public function __construct(private readonly Umbrales $umbrales, array $resultados = [])
    {
        $sinPromocion = [];
        foreach ($resultados as $sala) {
            $costo = $sala->costo();
            // Sin costo no hay contra qué comparar: una sala que cargó 0 no gana «holgado», gana todo.
            if ($costo > 0 && $sala->resultado() * 100 >= $this->umbrales->promocionGananciaMaxima * $costo) {
                $sinPromocion[$sala->salaId] = new SalaSinPromocion($sala->salaId, $sala->salaNombre, $sala->resultado(), $costo, self::porcentaje($sala->resultado(), $costo));
            }
        }
        $this->sinPromocion = $sinPromocion;
    }

    /**
     * Las salas que ganan tanto que no se les sugiere promoción, para decir por qué no aparecen.
     *
     * @return list<SalaSinPromocion> de la que más gana sobre su costo a la que menos
     */
    public function sinPromocion(): array
    {
        $salas = array_values($this->sinPromocion);
        usort($salas, static fn (SalaSinPromocion $a, SalaSinPromocion $b): int => [$b->sobreCosto, $a->salaNombre] <=> [$a->sobreCosto, $b->salaNombre]);

        return $salas;
    }

    /**
     * Los días de la semana en que cada sala vende poco, comparados con los demás: ahí conviene
     * una promoción (el ejemplo del profe: «el miércoles no va nadie»). Un día cuenta si tuvo
     * varias funciones, vendió hasta el umbral y vendió menos que el resto de la semana.
     *
     * Con cada día dice también qué dar (EF-09.5): la obra que el público de esa misma sala más
     * votó sin que ganara. Es de la sala y no de todas porque el que tiene que venir el miércoles
     * es su público, no el de otra.
     *
     * No vienen los de las salas que ganan holgado (EF-22.14): una promoción no les hace falta.
     *
     * @return list<DiaFlojo> de la ocupación más baja a la más alta
     */
    public function diasFlojos(DatosDelReporte $datos): array
    {
        return array_values(array_filter($this->todosLosDiasFlojos($datos), fn (DiaFlojo $dia): bool => !isset($this->sinPromocion[$dia->salaId])));
    }

    /**
     * Los días flojos de todas las salas, también de las que ganan: las combinadas que no son una
     * promoción (cambiar el horario, sumar lo que se agota) parten de ellos igual.
     *
     * @return list<DiaFlojo>
     */
    private function todosLosDiasFlojos(DatosDelReporte $datos): array
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
                    $this->pedidos($datos, (string) $salaId)[0] ?? null,
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
        return $this->pedidos($datos, null);
    }

    /**
     * @param ?string $salaId sólo lo que se votó y se proyectó en esa sala, o null para todas
     *
     * @return list<PedidoDelPublico>
     */
    private function pedidos(DatosDelReporte $datos, ?string $salaId): array
    {
        $proyectadas = [];
        $porObra = [];
        foreach ($datos->funciones as $funcion) {
            if ($salaId !== null && $funcion->salaId !== $salaId) {
                continue;
            }
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
     * Las salas con gente que votó y no reservó nada (EF-09.5): personas, no porcentajes, porque
     * una promoción apunta a gente. Se cuenta en todo el período y no por función: quien votó una
     * y reservó otra de la misma sala ya vino. Sin las que ganan holgado, que no necesitan
     * promoción (EF-22.14).
     *
     * @return list<VotantesSinReserva> de la que más gente tiene sin reservar a la que menos
     */
    public function votaronSinReservar(DatosDelReporte $datos): array
    {
        $porSala = [];
        foreach ($datos->funciones as $funcion) {
            $ventas = $datos->ventasDe($funcion->id);
            if ($ventas === null) {
                continue;
            }
            $sala = $porSala[$funcion->salaId] ?? ['nombre' => $funcion->salaNombre, 'votantes' => [], 'compradores' => []];
            foreach ($funcion->votantesIds as $id) {
                $sala['votantes'][$id] = true;
            }
            foreach ($ventas->compradoresIds as $id) {
                $sala['compradores'][$id] = true;
            }
            $porSala[$funcion->salaId] = $sala;
        }

        $salas = [];
        foreach ($porSala as $salaId => $sala) {
            $sinReserva = count(array_diff_key($sala['votantes'], $sala['compradores']));
            if ($sinReserva >= $this->umbrales->votaronSinReservarMinimos && !isset($this->sinPromocion[(string) $salaId])) {
                $salas[] = new VotantesSinReserva((string) $salaId, $sala['nombre'], count($sala['votantes']), $sinReserva);
            }
        }
        usort($salas, static fn (VotantesSinReserva $a, VotantesSinReserva $b): int => [$b->sinReserva, $a->salaNombre] <=> [$a->sinReserva, $b->salaNombre]);

        return $salas;
    }

    /**
     * Las salas que dieron funciones a la gorra y con precio, cuando la diferencia dice algo
     * (EF-09.5): si a la gorra llena bastante más, un descuento puede traer gente; si con precio
     * llena lo mismo o más, se puede cobrar. En el medio no se sugiere nada. A una sala que gana
     * holgado no se le sugiere el descuento (EF-22.14), y así tampoco sale su combinada a la gorra.
     *
     * @return list<PrecioDeLaEntrada> de la mayor diferencia a la menor
     */
    public function precioDeLaEntrada(DatosDelReporte $datos): array
    {
        /** @var array<string, array{nombre: string, gorra: array{int, int, int}, pagas: array{int, int, int}}> $porSala */
        $porSala = [];
        foreach ($datos->funciones as $funcion) {
            $ventas = $datos->ventasDe($funcion->id);
            if ($ventas === null) {
                continue;
            }
            $sala = $porSala[$funcion->salaId] ?? ['nombre' => $funcion->salaNombre, 'gorra' => [0, 0, 0], 'pagas' => [0, 0, 0]];
            $tipo = $funcion->esALaGorra() ? 'gorra' : 'pagas';
            [$funciones, $vendidas, $cupo] = $sala[$tipo];
            $sala[$tipo] = [$funciones + 1, $vendidas + $ventas->vendidas, $cupo + $funcion->cupo];
            $porSala[$funcion->salaId] = $sala;
        }

        $salas = [];
        foreach ($porSala as $salaId => $sala) {
            [$funcionesGorra, $vendidasGorra, $cupoGorra] = $sala['gorra'];
            [$funcionesPagas, $vendidasPagas, $cupoPagas] = $sala['pagas'];
            if ($funcionesGorra < $this->umbrales->gorraFuncionesMinimas || $funcionesPagas < $this->umbrales->gorraFuncionesMinimas) {
                continue;
            }
            $gorra = self::porcentaje($vendidasGorra, $cupoGorra);
            $pagas = self::porcentaje($vendidasPagas, $cupoPagas);
            $sensible = $gorra - $pagas >= $this->umbrales->gorraDiferenciaMinima;
            if ((!$sensible && $pagas < $gorra) || ($sensible && isset($this->sinPromocion[(string) $salaId]))) {
                continue;
            }
            $salas[] = new PrecioDeLaEntrada((string) $salaId, $sala['nombre'], $funcionesGorra, $gorra, $funcionesPagas, $pagas, $sensible);
        }
        usort($salas, static fn (PrecioDeLaEntrada $a, PrecioDeLaEntrada $b): int => [abs($b->ocupacionGorra - $b->ocupacionPagas), $a->salaNombre]
            <=> [abs($a->ocupacionGorra - $a->ocupacionPagas), $b->salaNombre]);

        return $salas;
    }

    /**
     * Las obras que agotaron funciones en una sala (EF-09.5): la demanda llegó al cupo y conviene
     * sumar otra función. Por sala, porque lo que se agota es el lugar de esa sala.
     *
     * @return list<ObraAgotada> de la que más funciones agotó a la que menos
     */
    public function obrasAgotadas(DatosDelReporte $datos): array
    {
        $porObra = [];
        foreach ($datos->funciones as $funcion) {
            $ventas = $datos->ventasDe($funcion->id);
            if ($ventas === null) {
                continue;
            }
            $agotada = $ventas->vendidas * 100 >= $this->umbrales->agotadaOcupacionMinima * $funcion->cupo;
            foreach ($funcion->elegidas() as $obra) {
                $clave = "{$funcion->salaId} {$obra->id}";
                $acumulado = $porObra[$clave] ?? ['obra' => $obra, 'funcion' => $funcion, 'funciones' => 0, 'agotadas' => 0, 'vendidas' => 0, 'cupo' => 0];
                $porObra[$clave] = [
                    ...$acumulado,
                    'funciones' => $acumulado['funciones'] + 1,
                    'agotadas' => $acumulado['agotadas'] + ($agotada ? 1 : 0),
                    'vendidas' => $acumulado['vendidas'] + $ventas->vendidas,
                    'cupo' => $acumulado['cupo'] + $funcion->cupo,
                ];
            }
        }

        $obras = [];
        foreach ($porObra as $obra) {
            if ($obra['agotadas'] > 0) {
                $obras[] = new ObraAgotada(
                    $obra['obra']->id,
                    $obra['obra']->titulo,
                    $obra['funcion']->salaId,
                    $obra['funcion']->salaNombre,
                    $obra['funciones'],
                    $obra['agotadas'],
                    self::porcentaje($obra['vendidas'], $obra['cupo']),
                );
            }
        }
        usort($obras, static fn (ObraAgotada $a, ObraAgotada $b): int => [$b->agotadas, $b->ocupacion, $a->titulo, $a->salaNombre]
            <=> [$a->agotadas, $a->ocupacion, $b->titulo, $b->salaNombre]);

        return $obras;
    }

    /**
     * Las recomendaciones que cruzan varias variables (EF-22.3): las de arriba miran una sola cosa
     * por vez, y el profe pidió el 18/11 «empezar a mezclar los ingredientes». Todas parten de un
     * día flojo de una sala y le suman otra cosa que sabe el reporte de esa misma sala:
     *
     * - lo que su público votó y no vio, y que a la gorra llena más que con precio: dar ese
     *   título a la gorra ese día, porque ya funcionó (no a la sala que gana holgado: es una
     *   promoción, EF-22.14);
     * - la franja horaria: si las de la noche llenan bastante más que las de la tarde (o al revés)
     *   y el día flojo está en la que llena menos, cambiarle el horario;
     * - lo que se agota y gusta: sumar una función de eso el día flojo.
     *
     * Cada una trae los datos que la justifican. Las reglas, con sus umbrales, están en
     * docs/recomendaciones.md.
     *
     * @return list<RecomendacionCombinada> en el orden de los días flojos, de la ocupación más baja
     */
    public function combinadas(DatosDelReporte $datos): array
    {
        $flojos = $this->todosLosDiasFlojos($datos);
        if ($flojos === []) {
            return [];
        }
        $precios = [];
        foreach ($this->precioDeLaEntrada($datos) as $precio) {
            $precios[$precio->salaId] = $precio;
        }
        $agotadas = $this->obrasAgotadas($datos);
        $promedios = self::promediosPorObra($datos);

        $recomendaciones = [];
        foreach ($flojos as $dia) {
            $nombre = self::DIAS[$dia->dia];
            $elDiaFlojo = "Los {$nombre} se vendió el {$dia->ocupacion} % de los lugares"
                . ($dia->ocupacionResto === null ? '' : ", contra el {$dia->ocupacionResto} % del resto de la semana")
                . " ({$dia->funciones} funciones).";

            $precio = $precios[$dia->salaId] ?? null;
            if ($dia->paraDar !== null && $precio !== null && $precio->sensible) {
                $pedido = $dia->paraDar;
                $recomendaciones[] = new RecomendacionCombinada(
                    RecomendacionCombinada::PEDIDO_A_LA_GORRA,
                    $dia->salaId,
                    $dia->salaNombre,
                    "Los {$nombre}, dar «{$pedido->titulo}» a la gorra.",
                    [
                        $elDiaFlojo,
                        "Su público lo votó y no lo vio: {$pedido->votos} " . self::plural($pedido->votos, 'voto', 'votos') . " en {$pedido->votaciones} " . self::plural($pedido->votaciones, 'votación', 'votaciones') . ", el {$pedido->porcentaje} %.",
                        "A la gorra ya funcionó en esta sala: llenó el {$precio->ocupacionGorra} % en {$precio->funcionesGorra} funciones, contra el {$precio->ocupacionPagas} % con precio.",
                    ],
                );
            }

            $franjas = $this->franjas($datos, $dia->salaId, $dia->dia);
            if ($franjas !== null) {
                $corte = $this->umbrales->franjaCorteHora;
                $hacia = $franjas['mejor'] === 'noche' ? "a la noche, desde las {$corte} h" : "a la tarde, antes de las {$corte} h";
                $recomendaciones[] = new RecomendacionCombinada(
                    RecomendacionCombinada::OTRO_HORARIO,
                    $dia->salaId,
                    $dia->salaNombre,
                    "Los {$nombre}, pasar las funciones {$hacia}.",
                    [
                        $elDiaFlojo,
                        "En esta sala, las de la {$franjas['mejor']} llenan el {$franjas['ocupacion_mejor']} % ({$franjas['funciones_mejor']} funciones) y las de la {$franjas['peor']}, el {$franjas['ocupacion_peor']} % ({$franjas['funciones_peor']}).",
                        "De las {$dia->funciones} funciones de los {$nombre}, {$franjas['el_dia_en_la_peor']} fueron a la {$franjas['peor']}.",
                    ],
                );
            }

            foreach ($agotadas as $obra) {
                $promedio = $promedios[$obra->obraId] ?? null;
                if ($obra->salaId !== $dia->salaId || $promedio === null || $promedio < $this->umbrales->repetirPromedioMinimo) {
                    continue;
                }
                $recomendaciones[] = new RecomendacionCombinada(
                    RecomendacionCombinada::AGOTADA_EL_DIA_FLOJO,
                    $dia->salaId,
                    $dia->salaNombre,
                    "Sumar una función de «{$obra->titulo}» los {$nombre}.",
                    [
                        "Se agotó en {$obra->agotadas} de sus {$obra->funciones} funciones en esta sala ({$obra->ocupacion} % de los lugares vendidos): hay gente que se quedó afuera.",
                        'Gustó: ' . str_replace('.', ',', sprintf('%.1f', $promedio)) . ' de promedio.',
                        $elDiaFlojo,
                    ],
                );
                // Una por día: la que más se agotó, que es la primera.
                break;
            }
        }

        return $recomendaciones;
    }

    /**
     * Cómo llenan en una sala las funciones de la tarde y las de la noche, si la diferencia dice
     * algo y el día flojo está en la que llena menos. Null si no.
     *
     * @return ?array{mejor: string, peor: string, ocupacion_mejor: int, ocupacion_peor: int, funciones_mejor: int, funciones_peor: int, el_dia_en_la_peor: int}
     */
    private function franjas(DatosDelReporte $datos, string $salaId, int $dia): ?array
    {
        $franjas = ['tarde' => [0, 0, 0], 'noche' => [0, 0, 0]];
        $elDia = ['tarde' => 0, 'noche' => 0];
        foreach ($datos->funciones as $funcion) {
            $ventas = $datos->ventasDe($funcion->id);
            if ($funcion->salaId !== $salaId || $ventas === null) {
                continue;
            }
            $franja = (int) $funcion->fechaHora->format('G') < $this->umbrales->franjaCorteHora ? 'tarde' : 'noche';
            [$funciones, $vendidas, $cupo] = $franjas[$franja];
            $franjas[$franja] = [$funciones + 1, $vendidas + $ventas->vendidas, $cupo + $funcion->cupo];
            if ((int) $funcion->fechaHora->format('N') === $dia) {
                ++$elDia[$franja];
            }
        }
        if ($franjas['tarde'][0] < $this->umbrales->franjaFuncionesMinimas || $franjas['noche'][0] < $this->umbrales->franjaFuncionesMinimas) {
            return null;
        }
        $ocupacion = [
            'tarde' => self::porcentaje($franjas['tarde'][1], $franjas['tarde'][2]),
            'noche' => self::porcentaje($franjas['noche'][1], $franjas['noche'][2]),
        ];
        [$mejor, $peor] = $ocupacion['noche'] >= $ocupacion['tarde'] ? ['noche', 'tarde'] : ['tarde', 'noche'];
        if ($ocupacion[$mejor] - $ocupacion[$peor] < $this->umbrales->franjaDiferenciaMinima || $elDia[$peor] <= $elDia[$mejor]) {
            return null;
        }

        return [
            'mejor' => $mejor,
            'peor' => $peor,
            'ocupacion_mejor' => $ocupacion[$mejor],
            'ocupacion_peor' => $ocupacion[$peor],
            'funciones_mejor' => $franjas[$mejor][0],
            'funciones_peor' => $franjas[$peor][0],
            'el_dia_en_la_peor' => $elDia[$peor],
        ];
    }

    /**
     * El promedio de las calificaciones de cada obra en todas sus funciones del período.
     *
     * @return array<string, float> por id de obra; las que nadie calificó no están
     */
    private static function promediosPorObra(DatosDelReporte $datos): array
    {
        $porObra = [];
        foreach ($datos->funciones as $funcion) {
            $calificacion = $datos->calificacionDe($funcion->id);
            if ($calificacion === null || $calificacion->cantidad === 0) {
                continue;
            }
            foreach ($funcion->elegidas() as $obra) {
                [$puntos, $cantidad] = $porObra[$obra->id] ?? [0.0, 0];
                $porObra[$obra->id] = [$puntos + $calificacion->promedio * $calificacion->cantidad, $cantidad + $calificacion->cantidad];
            }
        }

        return array_map(static fn (array $obra): float => round($obra[0] / $obra[1], 1), $porObra);
    }

    private static function plural(int $cantidad, string $uno, string $varios): string
    {
        return $cantidad === 1 ? $uno : $varios;
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
