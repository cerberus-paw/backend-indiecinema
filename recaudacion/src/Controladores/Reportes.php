<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Recaudacion\Modelo\DiaFlojo;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\ObraVotada;
use IndieCinema\Recaudacion\Modelo\PedidoDelPublico;
use IndieCinema\Recaudacion\Modelo\Periodo;
use IndieCinema\Recaudacion\Modelo\ReglaIncumplida;
use IndieCinema\Recaudacion\Modelo\RendimientoDeSala;
use IndieCinema\Recaudacion\Modelo\TituloParaRepetir;
use IndieCinema\Recaudacion\Modelo\TotalDeFunciones;
use IndieCinema\Recaudacion\Modelo\Umbrales;
use IndieCinema\Recaudacion\Servicios\ServicioDeReportes;
use IndieCinema\Recaudacion\Servicios\Sugerencias;
use IndieCinema\Recaudacion\Servicios\TotalesDelPeriodo;
use LogicException;

/**
 * Los reportes de recaudación: lo que pasó en las salas en un período. El organizador ve las suyas
 * y el administrador, todas.
 *
 * - GET /recaudacion/reportes: qué conviene hacer (EF-09.1). Va primero porque es lo que pidió el
 *   profe el 11/11: un reporte que sólo muestra números es una máquina de escribir; uno que dice
 *   qué día conviene una promoción sirve para decidir.
 * - GET /recaudacion/reportes/funciones: los totales de cada mes y las funciones de uno (EF-09.2).
 * - GET /recaudacion/reportes/funciones.csv: todas las funciones del período, para una planilla.
 */
final class Reportes extends Controlador
{
    private const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    private const MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    private readonly Umbrales $umbrales;

    public function __construct(
        private readonly ServicioDeReportes $reportes,
        Configuracion $configuracion,
    ) {
        $this->umbrales = Umbrales::desdeConfiguracion($configuracion);
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        [$periodo, $periodoElegido, $error] = self::periodoPedido($peticion);
        $usuario = self::usuario($peticion);
        $administrador = $usuario->alcanza(Rol::Administrador);
        if ($error !== null) {
            return $this->vista('reportes.html.twig', ['periodo' => $periodoElegido, 'error' => $error, 'administrador' => $administrador], 422);
        }

        $datos = $this->reportes->delOrganizador($periodo, $administrador ? null : $usuario->id);
        $sugerencias = new Sugerencias($this->umbrales);
        $total = $sugerencias->total($datos);

        return $this->vista('reportes.html.twig', [
            'periodo' => $periodoElegido,
            'administrador' => $administrador,
            'avisos' => $datos->avisos,
            'funciones' => count($datos->funciones),
            'sin_ventas' => $datos->ventas === null,
            'resumen' => $total === null ? null : self::rendimiento($total),
            'dias_flojos' => array_map(static fn (DiaFlojo $dia): array => [
                'sala' => $dia->salaNombre,
                'dia' => self::DIAS[$dia->dia],
                'funciones' => $dia->funciones,
                'ocupacion' => "{$dia->ocupacion} %",
                'resto' => $dia->ocupacionResto === null ? null : "{$dia->ocupacionResto} %",
            ], $sugerencias->diasFlojos($datos)),
            'titulos' => array_map(static fn (TituloParaRepetir $titulo): array => [
                'titulo' => $titulo->titulo,
                'funciones' => $titulo->funciones,
                'ocupacion' => "{$titulo->ocupacion} %",
                'asistencia' => "{$titulo->asistencia} %",
                'promedio' => self::promedio($titulo->promedio),
            ], $sugerencias->titulosParaRepetir($datos)),
            'pedidos' => array_map(static fn (PedidoDelPublico $pedido): array => [
                'titulo' => $pedido->titulo,
                'votaciones' => $pedido->votaciones,
                'votos' => $pedido->votos,
                'porcentaje' => "{$pedido->porcentaje} %",
            ], $sugerencias->pedidosDelPublico($datos)),
            'salas' => array_map(self::rendimiento(...), $sugerencias->salasQueRinden($datos)),
            'umbrales' => [
                'dia_flojo' => "{$this->umbrales->diaFlojoOcupacionMaxima} %",
                'dia_flojo_funciones' => $this->umbrales->diaFlojoFuncionesMinimas,
                'repetir_ocupacion' => "{$this->umbrales->repetirOcupacionMinima} %",
                'repetir_asistencia' => "{$this->umbrales->repetirAsistenciaMinima} %",
                'repetir_promedio' => self::promedio($this->umbrales->repetirPromedioMinimo),
                'pedido' => "{$this->umbrales->pedidoVotosMinimos} %",
            ],
        ]);
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
            ->conCabecera('Content-Disposition', "attachment; filename=\"funciones-{$periodo->desde->format('Y-m-d')}-al-{$periodo->hasta->format('Y-m-d')}.csv\"");
    }

    /**
     * El período del filtro: el elegido o, si no se eligió nada, los últimos noventa días. Si el
     * elegido no sirve, vuelve el error y lo que se había escrito, para mostrarlo en el formulario.
     *
     * @return array{Periodo, array{desde: string, hasta: string, texto: ?string}, ?string}
     */
    private static function periodoPedido(Peticion $peticion): array
    {
        $desde = $peticion->consulta('desde');
        $hasta = $peticion->consulta('hasta');
        $periodo = Periodo::porDefecto(new DateTimeImmutable('today'));
        $error = null;
        if (is_string($desde) || is_string($hasta)) {
            try {
                $periodo = Periodo::entre(is_string($desde) ? $desde : '', is_string($hasta) ? $hasta : '');
            } catch (ReglaIncumplida $regla) {
                $error = $regla->getMessage();
            }
        }

        return [$periodo, [
            'desde' => is_string($desde) && $error !== null ? $desde : $periodo->desde->format('Y-m-d'),
            'hasta' => is_string($hasta) && $error !== null ? $hasta : $periodo->hasta->format('Y-m-d'),
            // Con un error, el período por defecto no es el que se pidió: la bajada no lo nombra.
            'texto' => $error === null ? self::periodo($periodo) : null,
        ], $error];
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
        $texto = number_format(intdiv($centavos, 100), 0, ',', '.');

        return '$ ' . ($centavos % 100 === 0 ? $texto : $texto . ',' . sprintf('%02d', $centavos % 100));
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
            'asistencia' => $sala->asistencia === null ? null : "{$sala->asistencia} %",
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
     * el año, con los dos.
     */
    private static function periodo(Periodo $periodo): string
    {
        [$desde, $hasta] = [$periodo->desde, $periodo->hasta];
        $mes = static fn (DateTimeImmutable $fecha): string => self::MESES[(int) $fecha->format('n')];
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
