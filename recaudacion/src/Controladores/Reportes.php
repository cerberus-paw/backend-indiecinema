<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Recaudacion\Modelo\CostosDeReferencia;
use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\DiaFlojo;
use IndieCinema\Recaudacion\Modelo\Embudo;
use IndieCinema\Recaudacion\Modelo\EspectadorDeSala;
use IndieCinema\Recaudacion\Modelo\EmbudoDelPeriodo;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\FuncionParaProgramar;
use IndieCinema\Recaudacion\Modelo\GeneroDeSala;
use IndieCinema\Recaudacion\Modelo\ModeracionDeSala;
use IndieCinema\Recaudacion\Modelo\ModeracionDelPeriodo;
use IndieCinema\Recaudacion\Modelo\Monto;
use IndieCinema\Recaudacion\Modelo\ObraAgotada;
use IndieCinema\Recaudacion\Modelo\ObraVotada;
use IndieCinema\Recaudacion\Modelo\PedidoDelPublico;
use IndieCinema\Recaudacion\Modelo\Periodo;
use IndieCinema\Recaudacion\Modelo\PrecioDeLaEntrada;
use IndieCinema\Recaudacion\Modelo\RecomendacionCombinada;
use IndieCinema\Recaudacion\Modelo\ReglaIncumplida;
use IndieCinema\Recaudacion\Modelo\ReporteDeObra;
use IndieCinema\Recaudacion\Modelo\RankingDeSala;
use IndieCinema\Recaudacion\Modelo\RendimientoDeSala;
use IndieCinema\Recaudacion\Modelo\ResultadoDePlataforma;
use IndieCinema\Recaudacion\Modelo\ResultadoDeSala;
use IndieCinema\Recaudacion\Modelo\SalaSinPromocion;
use IndieCinema\Recaudacion\Modelo\TituloParaRepetir;
use IndieCinema\Recaudacion\Modelo\TotalDeFunciones;
use IndieCinema\Recaudacion\Modelo\Umbrales;
use IndieCinema\Recaudacion\Modelo\VotantesSinReserva;
use IndieCinema\Recaudacion\Servicios\EmbudoDeConversion;
use IndieCinema\Recaudacion\Servicios\ModeracionYConducta;
use IndieCinema\Recaudacion\Servicios\ObrasDelDueno;
use IndieCinema\Recaudacion\Servicios\Precargas;
use IndieCinema\Recaudacion\Servicios\RankingDeEspectadores;
use IndieCinema\Recaudacion\Servicios\ResultadosDelPeriodo;
use IndieCinema\Recaudacion\Servicios\ServicioDeReportes;
use IndieCinema\Recaudacion\Servicios\Sugerencias;
use IndieCinema\Recaudacion\Servicios\TotalesDelPeriodo;
use LogicException;

/**
 * Los reportes de recaudación: lo que pasó en las salas en un período. El organizador ve las suyas
 * y el administrador, todas.
 *
 * - GET /recaudacion/reportes: si cada sala (y, para el administrador, la plataforma) gana o pierde
 *   plata (EF-22.1), la conversión de los que votaron (EF-22.13 y EF-22.16), qué conviene hacer
 *   (EF-09.1, EF-09.5 y EF-22.3) y, para el administrador, la moderación y la conducta del período
 *   (EF-22.4). Va primero porque es lo que pidió el profe el 11/11: un reporte que sólo muestra números es una
 *   máquina de escribir; uno que dice qué día conviene una promoción sirve para decidir.
 * - GET /recaudacion/reportes/funciones: los totales de cada mes y las funciones de uno (EF-09.2).
 * - GET /recaudacion/reportes/funciones.csv: todas las funciones del período, para una planilla.
 * - GET /recaudacion/reportes/obras: para el dueño, cómo le fue a cada obra suya, y con ?obra= el
 *   detalle de una (EF-09.3).
 */
final class Reportes extends Controlador
{
    private const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    /**
     * Los temas de la página de recomendaciones (EF-22.2), como pestañas que son enlaces: cada uno
     * va a lo que le interesa en vez de leer todo una cosa abajo de la otra.
     */
    public const TEMAS = ['resumen' => 'Resumen', 'plata' => 'Plata', 'conversion' => 'Conversión', 'promociones' => 'Promociones', 'programacion' => 'Programación', 'publico' => 'Público', 'salas' => 'Salas'];

    /** Cómo se nombra cada paso del embudo (EF-22.13). */
    private const PASOS_DEL_EMBUDO = ['votaron' => 'Votaron', 'reservaron' => 'Reservaron', 'pagaron' => 'Pagaron', 'fueron' => 'Fueron', 'calificaron' => 'Calificaron'];

    /**
     * Qué hacer donde más se pierde, por paso (EF-22.13): el embudo termina en una decisión, como
     * pidió el profe el 11/11 de todos los reportes.
     */
    private const QUE_HACER_EN_EL_EMBUDO = [
        'reservaron' => 'Avisarles a los que votaron cuando se elige el título, con un enlace directo para reservar.',
        'pagaron' => 'Las reservas se vencen sin pagar: recordarles el pago antes de que venza o darles más tiempo.',
        'fueron' => 'Pagaron y no fueron: un recordatorio el día de la función y, si no pueden ir, que cancelen y liberen el lugar.',
        'calificaron' => 'Pedir la calificación al salir: un correo después de la función, con el enlace para calificar.',
    ];

    private const MESES_CORTOS = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private readonly Umbrales $umbrales;

    public function __construct(
        private readonly ServicioDeReportes $reportes,
        Configuracion $configuracion,
        private readonly ?ResultadosDelPeriodo $resultados = null,
        private readonly ?ModeracionYConducta $moderacion = null,
        private readonly ?EmbudoDeConversion $embudo = null,
        private readonly ?RankingDeEspectadores $ranking = null,
    ) {
        $this->umbrales = Umbrales::desdeConfiguracion($configuracion);
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        [$periodo, $periodoElegido, $error] = self::periodoPedido($peticion);
        $usuario = self::usuario($peticion);
        $administrador = $usuario->alcanza(Rol::Administrador);
        $tema = $peticion->consulta('tema');
        $tema = is_string($tema) && isset(self::TEMAS[$tema]) ? $tema : 'resumen';
        if ($error !== null) {
            return $this->vista('reportes.html.twig', ['periodo' => $periodoElegido, 'error' => $error, 'administrador' => $administrador, 'tema' => $tema, 'temas' => self::TEMAS], 422);
        }

        $datos = $this->reportes->delOrganizador($periodo, $administrador ? null : $usuario->id);
        $resultados = $this->resultados?->salas($datos) ?? [];
        // Con lo que ganó cada sala, para no sugerirle promociones a la que gana holgado (EF-22.14).
        $sugerencias = new Sugerencias($this->umbrales, $resultados);
        $total = $sugerencias->total($datos);
        $plataforma = $administrador ? $this->resultados?->plataforma($datos, $resultados) : null;
        $meses = TotalesDelPeriodo::porMes($datos, $datos->funciones);
        $moderacion = $administrador && $datos->funciones !== [] ? $this->moderacion?->delPeriodo($datos) : null;
        // La conversión le pregunta a funciones por cada función: sólo cuando se mira su pestaña.
        $embudo = $tema === 'conversion' && $datos->funciones !== [] ? $this->embudo?->delPeriodo($datos) : null;
        // El ranking le pregunta a funciones y a cuentas: también, sólo en su pestaña (EF-22.19).
        $ranking = $tema === 'publico' && $datos->funciones !== [] ? $this->ranking?->delPeriodo($datos) : null;
        // El botón «Programarla» de cada recomendación (EF-22.17), sólo en las salas de quien mira.
        $precargas = new Precargas($datos, $usuario->id, $this->umbrales->franjaCorteHora);
        $descuento = $this->umbrales->promocionDescuento;

        return $this->vista('reportes.html.twig', [
            'periodo' => $periodoElegido,
            'administrador' => $administrador,
            'tema' => $tema,
            'temas' => self::TEMAS,
            'funciones_por_mes' => array_map(
                static fn (string $mes, TotalDeFunciones $total): array => ['mes' => self::MESES_CORTOS[(int) substr($mes, 5, 2)], 'funciones' => $total->funciones],
                array_keys($meses),
                $meses,
            ),
            'ocupacion_por_dia' => self::ocupacionPorDia($datos),
            'avisos' => $datos->avisos,
            'ganancias' => $this->resultados === null ? null : self::ganancias($resultados, $plataforma, $this->resultados->referencia()),
            'costo_cargado' => self::avisoDelCosto($peticion),
            'moderacion' => $moderacion === null ? null : self::moderacion($moderacion),
            'conversion' => $embudo === null ? null : self::embudo($embudo),
            'ranking' => $ranking === null ? null : self::ranking($ranking),
            'funciones' => count($datos->funciones),
            'sin_ventas' => $datos->ventas === null,
            'resumen' => $total === null ? null : self::rendimiento($total),
            'combinadas' => array_map(static fn (RecomendacionCombinada $recomendacion): array => [
                'regla' => $recomendacion->regla,
                'sala' => $recomendacion->salaNombre,
                'accion' => $recomendacion->accion,
                'porque' => $recomendacion->porque,
                'programar' => $precargas->url($recomendacion->programar),
            ], $sugerencias->combinadas($datos)),
            'dias_flojos' => array_map(static fn (DiaFlojo $dia): array => [
                // La promoción: ese día, con el descuento y lo que el público pidió, si hay.
                'programar' => $precargas->url(new FuncionParaProgramar($dia->salaId, $dia->paraDar === null ? [] : [$dia->paraDar->obraId], $dia->dia, descuento: $descuento)),
                'sala' => $dia->salaNombre,
                'dia' => self::DIAS[$dia->dia],
                'funciones' => $dia->funciones,
                'ocupacion' => "{$dia->ocupacion} %",
                'resto' => $dia->ocupacionResto === null ? null : "{$dia->ocupacionResto} %",
                'para_dar' => $dia->paraDar === null ? null : self::pedido($dia->paraDar),
            ], $sugerencias->diasFlojos($datos)),
            'precios' => array_map(static fn (PrecioDeLaEntrada $precio): array => [
                // Sensible al precio: una a la gorra; si no, una con el precio de siempre.
                'programar' => $precargas->url(new FuncionParaProgramar($precio->salaId, precio: $precio->sensible ? 0 : null)),
                'sala' => $precio->salaNombre,
                'funciones_gorra' => $precio->funcionesGorra,
                'gorra' => "{$precio->ocupacionGorra} %",
                'gorra_valor' => $precio->ocupacionGorra,
                'pagas_valor' => $precio->ocupacionPagas,
                'funciones_pagas' => $precio->funcionesPagas,
                'pagas' => "{$precio->ocupacionPagas} %",
                'sensible' => $precio->sensible,
            ], $sugerencias->precioDeLaEntrada($datos)),
            'sin_promocion' => array_map(static fn (SalaSinPromocion $sala): array => [
                'sala' => $sala->salaNombre,
                'resultado' => self::conSigno($sala->resultado),
                'costo' => self::pesos($sala->costo),
                'sobre_costo' => "{$sala->sobreCosto} %",
            ], $sugerencias->sinPromocion()),
            'sin_reserva' => array_map(static fn (VotantesSinReserva $sala): array => [
                'sala' => $sala->salaNombre,
                'votantes' => $sala->votantes,
                'sin_reserva' => $sala->sinReserva,
            ], $sugerencias->votaronSinReservar($datos)),
            'agotadas' => array_map(static fn (ObraAgotada $obra): array => [
                'programar' => $precargas->url(new FuncionParaProgramar($obra->salaId, [$obra->obraId])),
                'titulo' => $obra->titulo,
                'sala' => $obra->salaNombre,
                'funciones' => $obra->funciones,
                'agotadas' => $obra->agotadas,
                'ocupacion' => "{$obra->ocupacion} %",
            ], $sugerencias->obrasAgotadas($datos)),
            'titulos' => array_map(static fn (TituloParaRepetir $titulo): array => [
                'programar' => $titulo->salaId === null ? null : $precargas->url(new FuncionParaProgramar($titulo->salaId, [$titulo->obraId])),
                'titulo' => $titulo->titulo,
                'funciones' => $titulo->funciones,
                'ocupacion' => "{$titulo->ocupacion} %",
                'asistencia' => "{$titulo->asistencia} %",
                'promedio' => self::promedio($titulo->promedio),
            ], $sugerencias->titulosParaRepetir($datos)),
            'generos' => self::generos($sugerencias->generosPorSala($datos)),
            'pedidos' => array_map(static fn (PedidoDelPublico $pedido): array => [
                ...self::pedido($pedido),
                'programar' => $pedido->salaId === null ? null : $precargas->url(new FuncionParaProgramar($pedido->salaId, [$pedido->obraId])),
            ], $sugerencias->pedidosDelPublico($datos)),
            'salas' => array_map(self::rendimiento(...), $sugerencias->salasQueRinden($datos)),
            'umbrales' => [
                'dia_flojo' => "{$this->umbrales->diaFlojoOcupacionMaxima} %",
                'dia_flojo_funciones' => $this->umbrales->diaFlojoFuncionesMinimas,
                'repetir_ocupacion' => "{$this->umbrales->repetirOcupacionMinima} %",
                'repetir_asistencia' => "{$this->umbrales->repetirAsistenciaMinima} %",
                'repetir_promedio' => self::promedio($this->umbrales->repetirPromedioMinimo),
                'pedido' => "{$this->umbrales->pedidoVotosMinimos} %",
                'gorra_diferencia' => $this->umbrales->gorraDiferenciaMinima,
                'agotada' => "{$this->umbrales->agotadaOcupacionMinima} %",
                'promocion_ganancia' => "{$this->umbrales->promocionGananciaMaxima} %",
                'descuento' => "{$this->umbrales->promocionDescuento} %",
                'genero_funciones' => $this->umbrales->generoFuncionesMinimas,
            ],
        ]);
    }

    /**
     * «Guardar» el costo por función de una sala (EF-22.1). El organizador sólo carga el de sus
     * salas: las que tuvieron alguna función suya, que son las únicas que ve en el reporte. Vuelve
     * al reporte con el mismo período.
     *
     * @throws ExcepcionHttp 403 si la sala no es suya; 422 si el costo no es un monto
     */
    public function cargarCosto(Peticion $peticion): Respuesta
    {
        $usuario = self::usuario($peticion);
        $sala = $peticion->campo('sala');
        $costo = $peticion->campo('costo');
        if (!is_string($sala) || !is_string($costo) || $this->resultados === null) {
            throw ExcepcionHttp::solicitudInvalida('Elegí la sala y escribí el costo.');
        }
        if (!$usuario->alcanza(Rol::Administrador)) {
            $suyas = TotalesDelPeriodo::salas($this->reportes->delOrganizador(Periodo::todo(new DateTimeImmutable('today')), $usuario->id));
            if (!isset($suyas[$sala])) {
                throw ExcepcionHttp::prohibida();
            }
        }
        $filtros = array_filter(['desde' => $peticion->campo('desde'), 'hasta' => $peticion->campo('hasta')], static fn (mixed $valor): bool => is_string($valor) && $valor !== '');
        try {
            $this->resultados->cargarCosto($sala, $costo, $usuario->id);
        } catch (ReglaIncumplida $regla) {
            return $this->redirigir('/reportes?' . http_build_query([...$filtros, 'tema' => 'plata', 'costo_error' => $regla->getMessage()]) . '#ganancias');
        }

        return $this->redirigir('/reportes?' . http_build_query([...$filtros, 'tema' => 'plata', 'costo_cargado' => '1']) . '#ganancias');
    }

    /**
     * @return ?array{tipo: string, texto: string}
     */
    private static function avisoDelCosto(Peticion $peticion): ?array
    {
        $error = $peticion->consulta('costo_error');
        if (is_string($error) && $error !== '') {
            // Sólo los textos que arma el servicio: el parámetro viaja en la URL.
            $conocidos = ['Escribí el costo en pesos, sin signos: por ejemplo, 45000.', 'El costo por función puede ser de hasta $ 10.000.000.'];

            return in_array($error, $conocidos, true) ? ['tipo' => 'error', 'texto' => $error] : null;
        }

        return $peticion->consulta('costo_cargado') === '1' ? ['tipo' => 'exito', 'texto' => 'Guardaste el costo por función: el resultado de la sala ya lo usa.'] : null;
    }

    /**
     * Si cada sala y la plataforma ganan o pierden, ya escrito (EF-22.1).
     *
     * @param list<ResultadoDeSala> $salas
     *
     * @return array<string, mixed>
     */
    private static function ganancias(array $salas, ?ResultadoDePlataforma $plataforma, CostosDeReferencia $referencia): array
    {
        return [
            'salas' => array_map(static fn (ResultadoDeSala $sala): array => [
                'id' => $sala->salaId,
                'nombre' => $sala->salaNombre,
                'funciones' => $sala->funciones,
                'cobrado' => self::pesos($sala->cobrado),
                'comision' => self::pesos($sala->comision),
                'duenos' => self::pesos($sala->duenos),
                'plataforma' => self::pesos($sala->plataforma),
                'sala' => self::pesos($sala->sala),
                'costo' => self::pesos($sala->costo()),
                'costo_funcion' => self::pesos($sala->costoFuncion),
                'costo_funcion_valor' => Monto::comoDecimal($sala->costoFuncion),
                'costo_propio' => $sala->costoPropio,
                'resultado' => self::conSigno($sala->resultado()),
                'gana' => $sala->resultado() >= 0,
                'estimadas' => $sala->estimadas,
                'sin_datos' => $sala->sinDatos,
            ], $salas),
            'plataforma' => $plataforma === null ? null : [
                'ingresos' => self::pesos($plataforma->ingresos),
                'servidores' => self::pesos($plataforma->servidores),
                'desarrollo' => self::pesos($plataforma->desarrollo),
                'costo' => self::pesos($plataforma->costo()),
                'dias' => $plataforma->dias,
                'resultado' => self::conSigno($plataforma->resultado()),
                'gana' => $plataforma->resultado() >= 0,
            ],
            'reparto' => self::reparto($salas),
            'comision' => str_replace('.', ',', rtrim(rtrim(sprintf('%.2f', $referencia->comisionCentesimos / 100), '0'), '.')) . ' %',
            'costo_funcion' => self::pesos($referencia->costoFuncionCentavos),
            'servidores_mes' => self::pesos($referencia->servidoresMesCentavos),
            'desarrollo_mes' => self::pesos($referencia->desarrolloMesCentavos),
        ];
    }

    /**
     * Cómo se repartió lo cobrado en todas las salas juntas, para la torta de la pestaña Plata
     * (EF-22.15): el profe pidió el 18/11 gráficos «por ejemplo, de torta». Cada porción trae dónde
     * empieza y cuánto ocupa, en porcentaje de la vuelta, porque la torta es un SVG con atributos
     * (la CSP no deja style=""). Vacía si no se cobró nada: una torta de cero no dice nada.
     *
     * @param list<ResultadoDeSala> $salas
     *
     * @return list<array{clave: string, nombre: string, monto: string, porcentaje: string, largo: float, inicio: float}>
     */
    private static function reparto(array $salas): array
    {
        $partes = [
            'sala' => ['Las salas', 0],
            'duenos' => ['Los dueños de las obras', 0],
            'plataforma' => ['La plataforma', 0],
            'comision' => ['Mercado Pago', 0],
        ];
        foreach ($salas as $sala) {
            $partes['sala'][1] += $sala->sala;
            $partes['duenos'][1] += $sala->duenos;
            $partes['plataforma'][1] += $sala->plataforma;
            $partes['comision'][1] += $sala->comision;
        }
        $total = array_sum(array_column($partes, 1));
        if ($total <= 0) {
            return [];
        }

        $porciones = [];
        $inicio = 0.0;
        foreach ($partes as $clave => [$nombre, $centavos]) {
            $largo = round($centavos * 100 / $total, 2);
            $porciones[] = [
                'clave' => $clave,
                'nombre' => $nombre,
                'monto' => self::pesos($centavos),
                'porcentaje' => str_replace('.', ',', sprintf('%.1f', $centavos * 100 / $total)) . ' %',
                'largo' => $largo,
                'inicio' => $inicio,
            ];
            $inicio = round($inicio + $largo, 2);
        }

        return $porciones;
    }

    /**
     * La ocupación de cada día de la semana, en todas las salas juntas, para el gráfico del resumen
     * (EF-22.2). Sólo los días que tuvieron funciones con ventas.
     *
     * @return list<array{dia: string, ocupacion: int, funciones: int}>
     */
    private static function ocupacionPorDia(DatosDelReporte $datos): array
    {
        $porDia = [];
        foreach ($datos->funciones as $funcion) {
            $ventas = $datos->ventasDe($funcion->id);
            if ($ventas === null || $funcion->cupo === 0) {
                continue;
            }
            $dia = (int) $funcion->fechaHora->format('N');
            [$funciones, $vendidas, $cupo] = $porDia[$dia] ?? [0, 0, 0];
            $porDia[$dia] = [$funciones + 1, $vendidas + $ventas->vendidas, $cupo + $funcion->cupo];
        }
        ksort($porDia);

        return array_map(static fn (int $dia, array $acumulado): array => [
            'dia' => ucfirst(self::DIAS[$dia]),
            'ocupacion' => intdiv(200 * $acumulado[1] + $acumulado[2], 2 * $acumulado[2]),
            'funciones' => $acumulado[0],
        ], array_keys($porDia), $porDia);
    }

    /**
     * El embudo de todas las salas y el de cada una, ya escrito (EF-22.13). Cada paso dice cuántos
     * llegaron, qué parte de los que votaron son (el ancho de su barra) y qué parte de los del paso
     * anterior (la conversión), con su plata.
     *
     * @return array<string, mixed>
     */
    private static function embudo(EmbudoDelPeriodo $embudo): array
    {
        $escrito = static function (Embudo $sala): array {
            $votaron = $sala->personas['votaron'];
            $pasos = [];
            $anterior = null;
            foreach (self::PASOS_DEL_EMBUDO as $clave => $nombre) {
                $personas = $sala->personas[$clave];
                $pasos[] = [
                    'clave' => $clave,
                    'nombre' => $nombre,
                    'personas' => $personas,
                    'del_total' => $votaron === 0 ? 0 : intdiv(200 * $personas + $votaron, 2 * $votaron),
                    'conversion' => $anterior === null || $anterior === 0 ? null : intdiv(200 * $personas + $anterior, 2 * $anterior) . ' %',
                    'monto' => self::pesos($sala->centavos[$clave]),
                ];
                $anterior = $personas;
            }
            $peor = $sala->dondeSePierdeMas();

            return [
                'nombre' => $sala->salaNombre,
                'pasos' => $pasos,
                'votaron' => $votaron,
                'sin_votar' => $sala->sinVotar,
                'sin_votar_monto' => self::pesos($sala->sinVotarCentavos),
                'perdida' => $peor === null ? null : [
                    'desde' => mb_strtolower(self::PASOS_DEL_EMBUDO[$peor['desde']]),
                    'hasta' => mb_strtolower(self::PASOS_DEL_EMBUDO[$peor['hasta']]),
                    // «de los que votaron, reservó el 60 %»: el verbo va con el porcentaje.
                    'verbo' => ['reservaron' => 'reservó', 'pagaron' => 'pagó', 'fueron' => 'fue', 'calificaron' => 'calificó'][$peor['hasta']],
                    'conversion' => "{$peor['conversion']} %",
                    'personas' => $sala->personas[$peor['desde']] - $sala->personas[$peor['hasta']],
                    'monto' => self::pesos(max(0, $sala->centavos[$peor['desde']] - $sala->centavos[$peor['hasta']])),
                    'que_hacer' => self::QUE_HACER_EN_EL_EMBUDO[$peor['hasta']],
                ],
            ];
        };

        return [
            'avisos' => $embudo->avisos,
            'total' => $escrito($embudo->total),
            // Las salas donde nadie votó no tienen embudo: se nombran aparte en la plantilla.
            'salas' => array_map($escrito, array_values(array_filter($embudo->salas, static fn (Embudo $sala): bool => $sala->personas['votaron'] > 0))),
            'sin_votacion' => array_map(static fn (Embudo $sala): string => $sala->salaNombre, array_values(array_filter($embudo->salas, static fn (Embudo $sala): bool => $sala->personas['votaron'] === 0))),
        ];
    }

    /**
     * La moderación y la conducta del período, ya escritas (EF-22.4).
     *
     * @return array<string, mixed>
     */
    private static function moderacion(ModeracionDelPeriodo $moderacion): array
    {
        $causas = ['retiro' => 'la retiró su organizador', 'vencimiento' => 'por un documento vencido', 'denuncia' => 'por una denuncia'];

        return [
            'avisos' => $moderacion->avisos,
            'salas' => array_map(static fn (ModeracionDeSala $sala): array => [
                'nombre' => $sala->salaNombre,
                'denuncias' => $sala->denuncias,
                'total_denuncias' => $sala->totalDeDenuncias(),
                'suspension' => $sala->suspension === null ? null : ($causas[$sala->suspension] ?? $sala->suspension),
                'ausentes' => $sala->ausentes,
                'negativas' => $sala->negativas,
                'bajo_umbral' => $sala->bajoUmbral,
                'espectadores' => $sala->espectadores,
            ], $moderacion->salas),
            'solicitudes' => [
                // EF-22.10: la devuelta, que espera al solicitante, tampoco está resuelta.
                'pendientes' => ($moderacion->solicitudes['pendiente'] ?? 0) + ($moderacion->solicitudes['en_revision_manual'] ?? 0)
                    + ($moderacion->solicitudes['devuelta'] ?? 0),
                'aprobadas' => $moderacion->solicitudes['aprobada'] ?? 0,
                'rechazadas' => $moderacion->solicitudes['rechazada'] ?? 0,
                'vencidas' => $moderacion->solicitudes['vencida'] ?? 0,
            ],
            'otras_denuncias' => $moderacion->otrasDenuncias,
            'total_otras_denuncias' => array_sum($moderacion->otrasDenuncias),
            'otras_suspendidas' => $moderacion->otrasSuspendidas,
        ];
    }

    /** «+ $ 31.000» o «− $ 8.400»: el signo, además del color, dice si ganó o perdió. */
    private static function conSigno(int $centavos): string
    {
        return ($centavos < 0 ? '− ' : '+ ') . self::pesos(abs($centavos));
    }

    /**
     * @return array{titulo: string, votaciones: int, votos: int, porcentaje: string}
     */
    /**
     * El ranking de cada sala, ya escrito (EF-22.19).
     *
     * @param array{salas: list<RankingDeSala>, avisos: list<string>} $ranking
     *
     * @return array{avisos: list<string>, salas: list<array{sala: string, participaron: int, espectadores: list<array{posicion: int, nombre: string, fue: int, voto: int, califico: int, pagado: string, puntaje: int}>}>, puntos: array{ir: int, votar: int, calificar: int}}
     */
    private static function ranking(array $ranking): array
    {
        return [
            'avisos' => $ranking['avisos'],
            'salas' => array_map(static fn (RankingDeSala $sala): array => [
                'sala' => $sala->salaNombre,
                'participaron' => $sala->participaron,
                'espectadores' => array_map(static fn (int $i, EspectadorDeSala $e): array => [
                    'posicion' => $i + 1,
                    // Sin el nombre, el final del id: alcanza para distinguirlos en la tabla.
                    'nombre' => $e->nombre ?? 'Espectador …' . substr($e->usuarioId, -6),
                    'fue' => $e->fue,
                    'voto' => $e->voto,
                    'califico' => $e->califico,
                    'pagado' => self::pesos($e->pagado),
                    'puntaje' => $e->puntaje(),
                ], array_keys($sala->espectadores), $sala->espectadores),
            ], $ranking['salas']),
            'puntos' => ['ir' => EspectadorDeSala::PUNTOS_POR_IR, 'votar' => EspectadorDeSala::PUNTOS_POR_VOTAR, 'calificar' => EspectadorDeSala::PUNTOS_POR_CALIFICAR],
        ];
    }

    /**
     * Los géneros de cada sala (EF-22.18), agrupados por sala para la plantilla.
     *
     * @param list<GeneroDeSala> $generos
     *
     * @return list<array{sala: string, generos: list<array{genero: string, funciones: int, ocupacion: string, ocupacion_valor: int, votos: ?string, promedio: ?string}>}>
     */
    private static function generos(array $generos): array
    {
        $porSala = [];
        foreach ($generos as $genero) {
            $porSala[$genero->salaId]['sala'] = $genero->salaNombre;
            $porSala[$genero->salaId]['generos'][] = [
                'genero' => $genero->genero,
                'funciones' => $genero->funciones,
                'ocupacion' => "{$genero->ocupacion} %",
                'ocupacion_valor' => $genero->ocupacion,
                'votos' => $genero->votos === null ? null : "{$genero->votos} %",
                'promedio' => $genero->promedio === null ? null : self::promedio($genero->promedio),
            ];
        }

        return array_values($porSala);
    }

    private static function pedido(PedidoDelPublico $pedido): array
    {
        return [
            'titulo' => $pedido->titulo,
            'votaciones' => $pedido->votaciones,
            'votos' => $pedido->votos,
            'porcentaje' => "{$pedido->porcentaje} %",
        ];
    }

    /**
     * Los totales de cada mes del período y, debajo, las funciones de uno: el que se eligió con
     * ?mes=AAAA-MM o, si no, el último con funciones. Un mes por página es el paginado natural de
     * un reporte mensual; el CSV trae todas juntas.
     */
    public function funciones(Peticion $peticion): Respuesta
    {
        [$periodo, $periodoElegido, $error] = self::periodoPedido($peticion);
        $usuario = self::usuario($peticion);
        $administrador = $usuario->alcanza(Rol::Administrador);
        $sala = $peticion->consulta('sala');
        $sala = is_string($sala) && $sala !== '' ? $sala : null;
        if ($error !== null) {
            return $this->vista('reportes_funciones.html.twig', ['periodo' => $periodoElegido, 'error' => $error, 'administrador' => $administrador, 'sala' => $sala], 422);
        }

        $datos = $this->reportes->delOrganizador($periodo, $administrador ? null : $usuario->id);
        $salas = TotalesDelPeriodo::salas($datos);
        // Una sala que no tuvo funciones en el período (o que no es suya) no filtra nada: se ignora.
        $sala = $sala !== null && isset($salas[$sala]) ? $sala : null;
        $funciones = TotalesDelPeriodo::deLaSala($datos, $sala);
        $meses = TotalesDelPeriodo::porMes($datos, $funciones);
        $mes = $peticion->consulta('mes');
        $mes = is_string($mes) && isset($meses[$mes]) ? $mes : array_key_last($meses);
        $filtros = ['desde' => $periodoElegido['desde'], 'hasta' => $periodoElegido['hasta'], ...($sala === null ? [] : ['sala' => $sala])];

        return $this->vista('reportes_funciones.html.twig', [
            'periodo' => $periodoElegido,
            'administrador' => $administrador,
            'avisos' => $datos->avisos,
            'sala' => $sala,
            'salas' => array_map(static fn (string $id, string $nombre): array => ['valor' => $id, 'etiqueta' => $nombre], array_keys($salas), $salas),
            'meses' => array_map(fn (string $clave, TotalDeFunciones $total): array => [
                ...self::totales($total),
                'nombre' => self::nombreDelMes($clave),
                'url' => $this->ruta('/reportes/funciones?' . http_build_query([...$filtros, 'mes' => $clave])),
                'actual' => $clave === $mes,
            ], array_keys($meses), $meses),
            'total' => self::totales(TotalesDelPeriodo::total($datos, $funciones)),
            'mes' => $mes === null ? null : self::nombreDelMes($mes),
            'funciones' => array_map(static fn (FuncionFinalizada $funcion): array => [
                ...self::totales(TotalesDelPeriodo::deUna($datos, $funcion)),
                'fecha' => self::DIAS[(int) $funcion->fechaHora->format('N')] . ' ' . $funcion->fechaHora->format('j') . ', ' . $funcion->fechaHora->format('H:i'),
                'sala' => $funcion->salaNombre,
                'obras' => implode(' + ', array_map(static fn (ObraVotada $obra): string => $obra->titulo, $funcion->elegidas())),
            ], array_values(array_filter($funciones, static fn (FuncionFinalizada $funcion): bool => $funcion->fechaHora->format('Y-m') === $mes))),
            'csv' => $this->ruta('/reportes/funciones.csv?' . http_build_query($filtros)),
        ]);
    }

    /**
     * Todas las funciones del período (y de la sala, si se eligió una) en CSV. Con un período que
     * no sirve, el mismo 422 que la pantalla: el enlace sale de ella, así que no debería pasar.
     */
    public function descargar(Peticion $peticion): Respuesta
    {
        [$periodo, $periodoElegido, $error] = self::periodoPedido($peticion);
        $usuario = self::usuario($peticion);
        if ($error !== null) {
            return $this->vista('reportes_funciones.html.twig', ['periodo' => $periodoElegido, 'error' => $error, 'administrador' => $usuario->alcanza(Rol::Administrador), 'sala' => null], 422);
        }
        $datos = $this->reportes->delOrganizador($periodo, $usuario->alcanza(Rol::Administrador) ? null : $usuario->id);
        $sala = $peticion->consulta('sala');

        return (new Respuesta(CsvDeFunciones::armar($datos, TotalesDelPeriodo::deLaSala($datos, is_string($sala) && $sala !== '' ? $sala : null))))
            ->conCabecera('Content-Type', 'text/csv; charset=utf-8')
            ->conCabecera('Content-Disposition', 'attachment; filename="' . self::nombreDelCsv($periodo) . '"');
    }

    /**
     * Las obras del dueño en las funciones finalizadas del período, en cualquier sala, y con
     * ?obra= el detalle de una. Una obra que no aparece es un 404: no se distingue una ajena de
     * una propia sin funciones en el período, así no se sabe si existe. Desde la lista se llega
     * con el mismo período, donde sí aparece.
     *
     * @throws ExcepcionHttp 404 si la obra no está entre las suyas del período
     */
    public function obras(Peticion $peticion): Respuesta
    {
        [$periodo, $periodoElegido, $error] = self::periodoPedido($peticion);
        $usuario = self::usuario($peticion);
        $administrador = $usuario->alcanza(Rol::Administrador);
        if ($error !== null) {
            return $this->vista('reportes_obras.html.twig', ['periodo' => $periodoElegido, 'error' => $error, 'administrador' => $administrador], 422);
        }
        $datos = $this->reportes->delDueno($periodo, $usuario->id);
        $obras = ObrasDelDueno::obras($datos, $usuario->id);
        $filtros = ['desde' => $periodoElegido['desde'], 'hasta' => $periodoElegido['hasta']];
        $pedida = $peticion->consulta('obra');
        // Si programación no respondió no hay obras, pero tampoco es un 404: va la lista con el aviso.
        if (is_string($pedida) && $pedida !== '' && $datos->avisos === []) {
            $obra = $obras[$pedida] ?? throw ExcepcionHttp::noEncontrada();

            return $this->vista('reportes_obra.html.twig', [
                'periodo' => $periodoElegido,
                'administrador' => $administrador,
                'avisos' => $datos->avisos,
                'volver' => $this->ruta('/reportes/obras?' . http_build_query($filtros)),
                'obra' => [
                    ...self::totales($obra->proyecciones),
                    'titulo' => $obra->titulo,
                    'votaciones' => count($obra->votaciones),
                    'ganadas' => $obra->ganadas(),
                    'votos' => $obra->votos(),
                ],
                'funciones' => array_map(static fn (FuncionFinalizada $funcion): array => [
                    ...self::totales(TotalesDelPeriodo::deUna($datos, $funcion)),
                    'fecha' => self::fechaLarga($funcion),
                    'sala' => $funcion->salaNombre,
                ], $obra->funciones),
                'votaciones_de_la_obra' => array_map(static fn (array $votacion): array => [
                    'fecha' => self::fechaLarga($votacion['funcion']),
                    'sala' => $votacion['funcion']->salaNombre,
                    'votos' => $votacion['votos'],
                    'total' => $votacion['total'],
                    'porcentaje' => intdiv(200 * $votacion['votos'] + $votacion['total'], 2 * $votacion['total']) . ' %',
                    'gano' => $votacion['gano'],
                ], $obra->votaciones),
            ]);
        }

        // Las tarjetas suman cada función una vez, aunque proyectara dos obras suyas.
        $proyectadas = [];
        foreach ($obras as $obra) {
            foreach ($obra->funciones as $funcion) {
                $proyectadas[$funcion->id] = $funcion;
            }
        }

        return $this->vista('reportes_obras.html.twig', [
            'periodo' => $periodoElegido,
            'administrador' => $administrador,
            'avisos' => $datos->avisos,
            'resumen' => [...self::totales(TotalesDelPeriodo::total($datos, array_values($proyectadas))), 'obras' => count($obras)],
            'obras' => array_values(array_map(fn (ReporteDeObra $obra): array => [
                ...self::totales($obra->proyecciones),
                'titulo' => $obra->titulo,
                'url' => $this->ruta('/reportes/obras?' . http_build_query([...$filtros, 'obra' => $obra->obraId])),
                'votaciones' => count($obra->votaciones),
                'ganadas' => $obra->ganadas(),
                'votos' => $obra->votos(),
            ], $obras)),
        ]);
    }

    /** «sábado 7 de noviembre, 21:00»: el dueño ve funciones de varios meses juntas. */
    private static function fechaLarga(FuncionFinalizada $funcion): string
    {
        $fecha = $funcion->fechaHora;

        return self::DIAS[(int) $fecha->format('N')] . " {$fecha->format('j')} de " . self::MESES[(int) $fecha->format('n')] . ", {$fecha->format('H:i')}";
    }

    /**
     * El período del filtro: el elegido o, si no se eligió nada, todo. Un día vacío no pone límite
     * de ese lado (Periodo::entre()), y el filtro lo sigue mostrando vacío. Si el elegido no sirve,
     * vuelve el error y lo que se había escrito, para mostrarlo en el formulario.
     *
     * @return array{Periodo, array{desde: string, hasta: string, texto: ?string}, ?string}
     */
    private static function periodoPedido(Peticion $peticion): array
    {
        $desde = $peticion->consulta('desde');
        $hasta = $peticion->consulta('hasta');
        $periodo = Periodo::todo(new DateTimeImmutable('today'));
        $error = null;
        if (is_string($desde) || is_string($hasta)) {
            try {
                $periodo = Periodo::entre(is_string($desde) ? $desde : '', is_string($hasta) ? $hasta : '');
            } catch (ReglaIncumplida $regla) {
                $error = $regla->getMessage();
            }
        }

        return [$periodo, [
            'desde' => is_string($desde) && $error !== null ? $desde : ($periodo->sinDesde ? '' : $periodo->desde->format('Y-m-d')),
            'hasta' => is_string($hasta) && $error !== null ? $hasta : ($periodo->sinHasta ? '' : $periodo->hasta->format('Y-m-d')),
            // Con un error, el período por defecto no es el que se pidió: la bajada no lo nombra.
            'texto' => $error === null ? self::periodo($periodo) : null,
        ], $error];
    }

    /** «funciones-2026-10-01-al-2026-11-30.csv»; sin el primer día, «funciones-hasta-2026-11-30.csv». */
    private static function nombreDelCsv(Periodo $periodo): string
    {
        $hasta = $periodo->hasta->format('Y-m-d');

        return $periodo->sinDesde ? "funciones-hasta-{$hasta}.csv" : "funciones-{$periodo->desde->format('Y-m-d')}-al-{$hasta}.csv";
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }

    /**
     * Lo de una función, un mes o el período, ya escrito. Lo que no se pudo calcular (sin ventas,
     * sin calificaciones) va en null y la plantilla pone una raya.
     *
     * @return array<string, mixed>
     */
    private static function totales(TotalDeFunciones $total): array
    {
        $conVentas = $total->sinVentas < $total->funciones;

        return [
            'funciones' => $total->funciones,
            'cupo' => $total->cupo,
            'vendidas' => $conVentas ? $total->vendidas : null,
            'ocupacion' => $total->ocupacion() === null ? null : "{$total->ocupacion()} %",
            'asistentes' => $conVentas ? $total->asistentes : null,
            'asistencia' => $total->asistencia() === null ? null : "{$total->asistencia()} %",
            'cobrado' => $conVentas ? self::pesos($total->cobradoCentavos) : null,
            'promedio' => $total->promedio() === null ? null : self::promedio($total->promedio()),
            'calificaciones' => $total->calificaciones,
        ];
    }

    /** 15400000 → «$ 154.000», con los centavos sólo si los hay, como el resto del sitio. */
    private static function pesos(int $centavos): string
    {
        return Monto::comoPesos($centavos);
    }

    /** «2026-11» → «Noviembre de 2026». */
    private static function nombreDelMes(string $mes): string
    {
        [$anio, $numero] = explode('-', $mes);

        return ucfirst(self::MESES[(int) $numero]) . " de {$anio}";
    }

    /**
     * @return array<string, mixed>
     */
    private static function rendimiento(RendimientoDeSala $sala): array
    {
        return [
            'nombre' => $sala->salaNombre,
            'funciones' => $sala->funciones,
            'ocupacion' => "{$sala->ocupacion} %",
            'ocupacion_valor' => $sala->ocupacion,
            'asistencia' => $sala->asistencia === null ? null : "{$sala->asistencia} %",
            'asistencia_valor' => $sala->asistencia,
            // El promedio, de 1 a 5, como porcentaje del máximo: el largo de su barra.
            'promedio_valor' => $sala->promedio === null ? null : (int) round($sala->promedio * 20),
            'promedio' => $sala->promedio === null ? null : self::promedio($sala->promedio),
            'conversion' => $sala->conversion === null ? null : "{$sala->conversion} %",
        ];
    }

    /** 4.3 → «4,3», como se escribe en castellano. */
    private static function promedio(float $promedio): string
    {
        return str_replace('.', ',', sprintf('%.1f', $promedio));
    }

    /**
     * «del 1 al 30 de noviembre de 2026», «del 1 de agosto al 29 de octubre de 2026» o, si cambia
     * el año, con los dos. Con un día vacío, «hasta el 30 de noviembre de 2026» o «desde el 1 de
     * octubre de 2026»; sin ninguno, nada: es todo.
     */
    private static function periodo(Periodo $periodo): ?string
    {
        [$desde, $hasta] = [$periodo->desde, $periodo->hasta];
        $mes = static fn (DateTimeImmutable $fecha): string => self::MESES[(int) $fecha->format('n')];
        $dia = static fn (DateTimeImmutable $fecha): string => "{$fecha->format('j')} de {$mes($fecha)} de {$fecha->format('Y')}";
        if ($periodo->sinDesde && $periodo->sinHasta) {
            return null;
        }
        if ($periodo->sinDesde) {
            return "hasta el {$dia($hasta)}";
        }
        if ($periodo->sinHasta) {
            return "desde el {$dia($desde)}";
        }
        if ($desde->format('Y') !== $hasta->format('Y')) {
            $primero = "{$desde->format('j')} de {$mes($desde)} de {$desde->format('Y')}";
        } elseif ($desde->format('n') !== $hasta->format('n')) {
            $primero = "{$desde->format('j')} de {$mes($desde)}";
        } else {
            $primero = $desde->format('j');
        }

        return "del {$primero} al {$hasta->format('j')} de {$mes($hasta)} de {$hasta->format('Y')}";
    }
}
