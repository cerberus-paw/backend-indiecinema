<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Controladores;

use DateTimeImmutable;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Recaudacion\Modelo\DiaFlojo;
use IndieCinema\Recaudacion\Modelo\PedidoDelPublico;
use IndieCinema\Recaudacion\Modelo\Periodo;
use IndieCinema\Recaudacion\Modelo\ReglaIncumplida;
use IndieCinema\Recaudacion\Modelo\RendimientoDeSala;
use IndieCinema\Recaudacion\Modelo\TituloParaRepetir;
use IndieCinema\Recaudacion\Modelo\Umbrales;
use IndieCinema\Recaudacion\Servicios\ServicioDeReportes;
use IndieCinema\Recaudacion\Servicios\Sugerencias;
use LogicException;

/**
 * GET /recaudacion/reportes: lo que pasó en las salas en un período y qué conviene hacer
 * (EF-09.1). El organizador ve las suyas y el administrador, todas. Primero las sugerencias, que
 * es lo que pidió el profe el 11/11: un reporte que sólo muestra números es una máquina de
 * escribir; uno que dice qué día conviene una promoción sirve para decidir.
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
        $usuario = $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
        $desde = $peticion->consulta('desde');
        $hasta = $peticion->consulta('hasta');
        $error = null;
        $periodo = Periodo::porDefecto(new DateTimeImmutable('today'));
        if (is_string($desde) || is_string($hasta)) {
            try {
                $periodo = Periodo::entre(is_string($desde) ? $desde : '', is_string($hasta) ? $hasta : '');
            } catch (ReglaIncumplida $regla) {
                $error = $regla->getMessage();
            }
        }
        $administrador = $usuario->alcanza(Rol::Administrador);
        $periodoElegido = [
            'desde' => is_string($desde) && $error !== null ? $desde : $periodo->desde->format('Y-m-d'),
            'hasta' => is_string($hasta) && $error !== null ? $hasta : $periodo->hasta->format('Y-m-d'),
            'texto' => self::periodo($periodo),
        ];
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
