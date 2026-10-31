<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use DateTimeZone;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Servicios\FuncionesDeProgramacion;
use IndieCinema\Funciones\Servicios\ServicioDePanelDeReservas;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use LogicException;

/**
 * Todas las reservas para el administrador (E4-03.3), con el armado de «Usuarios y roles»: la
 * barra lateral, el filtro por estado, la tabla y el paginado. Desde acá anula la que haga falta,
 * y sus lugares quedan libres.
 */
final class ReservasDelAdministrador extends Controlador
{
    private const ZONA = 'America/Argentina/Buenos_Aires';

    /** @var array<string, ?FuncionDeProgramacion> las ya pedidas en esta página */
    private array $funcionesPedidas = [];

    public function __construct(
        private readonly ServicioDePanelDeReservas $servicio,
        private readonly FuncionesDeProgramacion $funciones,
    ) {
    }

    /**
     * GET /administracion/reservas, con ?estado= y ?pagina=. Un estado que no existe se ignora,
     * como un filtro inválido de la cartelera; una página que no existe es 404.
     */
    public function listar(Peticion $peticion): Respuesta
    {
        $anulada = $peticion->consulta('anulada');

        return $this->pantalla($peticion, anulada: is_string($anulada) ? $anulada : null);
    }

    /** POST /administracion/reservas/{id}/anular */
    public function anular(Peticion $peticion): Respuesta
    {
        $administrador = $peticion->usuario() ?? throw new LogicException('Ruta de administrador sin usuario.');
        try {
            $reserva = $this->servicio->anular($administrador, (string) $peticion->parametro('id'));
        } catch (ReglaIncumplida $error) {
            return $this->pantalla($peticion, error: $error->getMessage(), estado: 422);
        }

        // 303 a la lista: recargar no la vuelve a mandar.
        return $this->redirigir("/administracion/reservas?anulada={$reserva->codigo}");
    }

    private function pantalla(Peticion $peticion, ?string $anulada = null, ?string $error = null, int $estado = 200): Respuesta
    {
        $filtro = EstadoReserva::tryFrom((string) $peticion->consulta('estado', ''));
        $numero = filter_var($peticion->consulta('pagina', '1'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($numero === false) {
            throw ExcepcionHttp::noEncontrada();
        }
        [$reservas, $total] = $this->servicio->pagina($filtro, $numero);
        $paginas = max(1, (int) ceil($total / ServicioDePanelDeReservas::POR_PAGINA));
        if ($numero > $paginas) {
            throw ExcepcionHttp::noEncontrada();
        }

        $datos = [
            'reservas' => array_map(fn (Reserva $reserva): array => $this->fila($reserva), $reservas),
            'estados' => array_map(static fn (EstadoReserva $estado): array => ['valor' => $estado->value, 'etiqueta' => $estado->etiqueta()], EstadoReserva::cases()),
            'filtro' => $filtro?->value,
            'paginado' => [
                'numero' => $numero,
                'total_paginas' => $paginas,
                'total' => $total,
                'anterior' => $numero > 1 ? $this->urlDePagina($filtro, $numero - 1) : null,
                'siguiente' => $numero < $paginas ? $this->urlDePagina($filtro, $numero + 1) : null,
                'paginas' => array_map(
                    fn (int $n): array => ['numero' => $n, 'url' => $this->urlDePagina($filtro, $n), 'actual' => $n === $numero],
                    range(1, $paginas),
                ),
            ],
            'anulada' => $anulada !== null && preg_match('/^[A-Z0-9]{8}$/', $anulada) === 1 ? $anulada : null,
        ];
        if ($error !== null) {
            $datos['error_general'] = $error;
        }

        return $this->vista('administracion_reservas.html.twig', $datos, $estado);
    }

    /**
     * Relativa a la página: así no depende del prefijo de funciones, que pone nginx.
     */
    private function urlDePagina(?EstadoReserva $filtro, int $numero): string
    {
        return '?' . http_build_query(array_filter(['estado' => $filtro?->value, 'pagina' => $numero > 1 ? $numero : null]));
    }

    /**
     * @return array{id: string, codigo: string, cantidad: int, total: string, estado: string, estado_etiqueta: string, creada: string, anulable: bool, funcion: ?array{titulo: string, url: string, fecha: ?string}}
     */
    private function fila(Reserva $reserva): array
    {
        $funcion = $this->funcion($reserva->funcionId);

        return [
            'id' => $reserva->id,
            'codigo' => $reserva->codigo,
            'cantidad' => $reserva->cantidad,
            'total' => TextoParaMostrar::monto((float) $reserva->total),
            'estado' => $reserva->estado->value,
            'estado_etiqueta' => $reserva->estado->etiqueta(),
            'creada' => TextoParaMostrar::fecha($reserva->creadaEn->setTimezone(new DateTimeZone(self::ZONA))),
            'anulable' => $reserva->estado === EstadoReserva::Confirmada,
            'funcion' => $funcion === null ? null : [
                'titulo' => $funcion->titulo(),
                'url' => "/cartelera/{$funcion->id}",
                'fecha' => $funcion->fechaHora === null ? null : TextoParaMostrar::fecha($funcion->fechaHora),
            ],
        ];
    }

    /**
     * La función de una reserva, para mostrar su título: una vez por función aunque tenga varias
     * reservas en la página. Si programación no responde, la fila va sin el título y la página
     * sigue: el administrador igual puede anular.
     */
    private function funcion(string $id): ?FuncionDeProgramacion
    {
        if (!array_key_exists($id, $this->funcionesPedidas)) {
            try {
                $this->funcionesPedidas[$id] = $this->funciones->buscar($id);
            } catch (ExcepcionHttp) {
                $this->funcionesPedidas[$id] = null;
            }
        }

        return $this->funcionesPedidas[$id];
    }
}
