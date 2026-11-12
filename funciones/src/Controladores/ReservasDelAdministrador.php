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
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Todas las reservas para el administrador (E4-03.3), con el armado de «Usuarios y roles»: la
 * barra lateral, el filtro por estado, la tabla y el paginado. Desde acá anula la que haga falta,
 * y sus lugares quedan libres.
 */
final class ReservasDelAdministrador extends Controlador
{
    private const ZONA = 'America/Argentina/Buenos_Aires';

    public function __construct(
        private readonly ServicioDePanelDeReservas $servicio,
        private readonly FuncionesDeProgramacion $funciones,
        private readonly LoggerInterface $log,
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

    /**
     * POST /administracion/reservas/{id}/anular. En JSON contesta la fila como quedó, para que el
     * panel la cambie sin recargar la lista (E4-05.5); si no, redirige como siempre.
     */
    public function anular(Peticion $peticion): Respuesta
    {
        $administrador = $peticion->usuario() ?? throw new LogicException('Ruta de administrador sin usuario.');
        $enJson = $peticion->aceptaJson();
        try {
            $reserva = $this->servicio->anular($administrador, (string) $peticion->parametro('id'));
        } catch (ReglaIncumplida $error) {
            // El mensaje de la regla es para el administrador («La reserva ya está cancelada.»).
            return $enJson
                ? $this->json(['error' => $error->getMessage()], 422)
                : $this->pantalla($peticion, error: $error->getMessage(), estado: 422);
        }

        if ($enJson) {
            return $this->json(['reserva' => self::estadoDeLaFila($reserva)]);
        }

        // 303 a la lista: recargar no la vuelve a mandar.
        return $this->redirigir("/administracion/reservas?anulada={$reserva->codigo}");
    }

    /**
     * Lo único que cambia de la fila al anular: su estado, qué pasó con el reembolso y si todavía
     * se puede anular. La función va aparte en fila(), y pedírsela de nuevo a programación por una
     * anulación sería un pedido al pedo: esa celda queda como está.
     *
     * @return array{id: string, codigo: string, estado: string, estado_etiqueta: string, reembolso: ?string, anulable: bool}
     */
    private static function estadoDeLaFila(Reserva $reserva): array
    {
        return [
            'id' => $reserva->id,
            'codigo' => $reserva->codigo,
            'estado' => $reserva->estado->value,
            'estado_etiqueta' => $reserva->estado->etiqueta(),
            'reembolso' => self::reembolso($reserva),
            'anulable' => $reserva->estado === EstadoReserva::Confirmada,
        ];
    }

    /**
     * Qué pasó con la plata de la reserva (EF-06.4): «hecho» si se devolvió, «pendiente» si se
     * canceló estando pagada y la devolución no salió —Mercado Pago no respondió o la rechazó—, y
     * null si no había nada que devolver: a la gorra, con el cobro apagado, o nunca pagada.
     *
     * Pendiente es el único que el administrador tiene que atender: la plata sigue en la cuenta.
     */
    private static function reembolso(Reserva $reserva): ?string
    {
        if ($reserva->reembolsoId !== null) {
            return 'hecho';
        }

        return $reserva->reembolsoPendiente() ? 'pendiente' : null;
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

        $funciones = $this->funcionesDe($reservas);
        $datos = [
            'reservas' => array_map(static fn (Reserva $reserva): array => self::fila($reserva, $funciones[$reserva->funcionId] ?? null), $reservas),
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
     * @return array{id: string, codigo: string, cantidad: int, total: string, estado: string, estado_etiqueta: string, reembolso: ?string, creada: string, anulable: bool, funcion: ?array{titulo: string, url: string, fecha: ?string}}
     */
    private static function fila(Reserva $reserva, ?FuncionDeProgramacion $funcion): array
    {
        return [
            'id' => $reserva->id,
            'codigo' => $reserva->codigo,
            'cantidad' => $reserva->cantidad,
            'total' => TextoParaMostrar::monto((float) $reserva->total),
            'estado' => $reserva->estado->value,
            'estado_etiqueta' => $reserva->estado->etiqueta(),
            'reembolso' => self::reembolso($reserva),
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
     * Las funciones de las reservas de la página, para mostrar su título: todas en un pedido a
     * programación, y no uno por fila (hasta 20 seguidos, de 2 s cada uno si no respondía). Si
     * programación no responde, las filas van sin el título y la página sigue: el administrador
     * igual puede anular.
     *
     * @param list<Reserva> $reservas
     *
     * @return array<string, FuncionDeProgramacion> con el id como clave
     */
    private function funcionesDe(array $reservas): array
    {
        try {
            return $this->funciones->buscarVarias(array_map(static fn (Reserva $reserva): string => $reserva->funcionId, $reservas));
        } catch (ExcepcionHttp) {
            // ClienteInterno ya dejó en el log que programación no respondió.
            return [];
        } catch (RuntimeException $error) {
            $this->log->error('Las reservas sin el título de su función: {mensaje}', ['mensaje' => $error->getMessage()]);

            return [];
        }
    }
}
