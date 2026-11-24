<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use DateTimeZone;
use IndieCinema\Funciones\Modelo\AutorDelCambio;
use IndieCinema\Funciones\Modelo\CambioDeReserva;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Servicios\FuncionesDeProgramacion;
use IndieCinema\Funciones\Servicios\ServicioDePanelDeReservas;
use IndieCinema\Funciones\Servicios\UsuariosDeCuentas;
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
 * y sus lugares quedan libres. Cada una tiene su detalle con el historial de sus estados (EF-18.6).
 */
final class ReservasDelAdministrador extends Controlador
{
    private const ZONA = 'America/Argentina/Buenos_Aires';

    /** Cuántas páginas se muestran a cada lado de la actual en el paginado, como «Usuarios y roles». */
    private const PAGINAS_ALREDEDOR = 2;

    public function __construct(
        private readonly ServicioDePanelDeReservas $servicio,
        private readonly FuncionesDeProgramacion $funciones,
        private readonly UsuariosDeCuentas $usuarios,
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
     * GET /administracion/reservas/{id}: la reserva y su historial (EF-18.6), de la creación al
     * último cambio, con quién hizo cada uno, también los del sistema, y la devolución del pago si
     * la hubo. Es lo que se mira ante un reclamo: «yo no la cancelé».
     *
     * @throws ExcepcionHttp 404 si no existe
     */
    public function ver(Peticion $peticion): Respuesta
    {
        [$reserva, $historial] = $this->servicio->conSuHistorial((string) $peticion->parametro('id'));
        $funciones = $this->funcionesDe([$reserva]);
        $nombres = $this->usuarios->nombresDe(array_values(array_filter([
            $reserva->usuarioId,
            ...array_map(static fn (CambioDeReserva $cambio): ?string => $cambio->autorId, $historial),
        ])));

        return $this->vista('administracion_reserva.html.twig', [
            'reserva' => [
                ...self::fila($reserva, $funciones[$reserva->funcionId] ?? null),
                'espectador' => $nombres[$reserva->usuarioId] ?? null,
                'pago' => $reserva->pagoId,
                'cupon' => $reserva->cupon,
            ],
            'historial' => self::historial($reserva, $historial, $nombres),
        ]);
    }

    /**
     * Las líneas del historial para la pantalla: primero la creación (con el estado en que nació,
     * que es el anterior del primer cambio), después cada cambio y, si se devolvió el pago, la
     * devolución, que no es un cambio de estado y queda en la reserva (EF-06.4).
     *
     * @param list<CambioDeReserva>  $cambios
     * @param array<string, string>  $nombres id => nombre, de cuentas; vacío si no respondió
     *
     * @return list<array{fecha: string, estado: string, estado_etiqueta: string, anterior: ?string, quien: string, motivo: ?string}>
     */
    private static function historial(Reserva $reserva, array $cambios, array $nombres): array
    {
        $zona = new DateTimeZone(self::ZONA);
        $inicial = $cambios === [] ? $reserva->estado : $cambios[0]->anterior;
        $lineas = [[
            'fecha' => TextoParaMostrar::fecha($reserva->creadaEn->setTimezone($zona)),
            'estado' => $inicial->value,
            'estado_etiqueta' => $inicial->etiqueta(),
            'anterior' => null,
            'quien' => self::quien('espectador', $reserva->usuarioId, $nombres),
            'motivo' => 'La reservó.',
        ]];
        foreach ($cambios as $cambio) {
            $lineas[] = [
                'fecha' => TextoParaMostrar::fecha($cambio->hechoEn->setTimezone($zona)),
                'estado' => $cambio->nuevo->value,
                'estado_etiqueta' => $cambio->nuevo->etiqueta(),
                'anterior' => $cambio->anterior->etiqueta(),
                'quien' => self::quien($cambio->autorRol, $cambio->autorId, $nombres),
                'motivo' => $cambio->motivo,
            ];
        }
        if ($reserva->reembolsadaEn !== null) {
            $lineas[] = [
                'fecha' => TextoParaMostrar::fecha($reserva->reembolsadaEn->setTimezone($zona)),
                'estado' => $reserva->estado->value,
                'estado_etiqueta' => 'reembolsada',
                'anterior' => null,
                'quien' => self::quien(AutorDelCambio::SISTEMA, null, $nombres),
                'motivo' => "Se devolvió el pago en Mercado Pago (reembolso {$reserva->reembolsoId}).",
            ];
        }

        return $lineas;
    }

    /**
     * Quién hizo un cambio, para leer: «El sistema», o el rol con el nombre si cuentas lo dio
     * («Organizador · Ana Pérez»).
     *
     * @param array<string, string> $nombres
     */
    private static function quien(string $rol, ?string $id, array $nombres): string
    {
        if ($rol === AutorDelCambio::SISTEMA || $id === null) {
            return 'El sistema';
        }
        $nombre = $nombres[$id] ?? null;

        return ucfirst($rol) . ($nombre === null ? '' : " · {$nombre}");
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
                'paginas' => $this->paginas($numero, $paginas, $filtro),
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
    /**
     * Los números del paginado: la primera, la última y las de alrededor de la actual, con null
     * donde se saltean páginas («…»). Con 32 páginas, en la 1: 1 2 3 4 5 … 32; en la 10: 1 … 8 9
     * 10 11 12 … 32. Antes iban todas, y con cientos de reservas la barra eran decenas de números.
     * Es la misma ventana que la de «Usuarios y roles» (AdministracionDeUsuarios de cuentas).
     *
     * @return list<?array{numero: int, url: string, actual: bool}>
     */
    private function paginas(int $actual, int $total, ?EstadoReserva $filtro): array
    {
        // La ventana tiene siempre el mismo ancho, también cerca de los extremos.
        $ancho = 2 * self::PAGINAS_ALREDEDOR + 1;
        $desde = max(1, min($actual - self::PAGINAS_ALREDEDOR, $total - $ancho + 1));
        $hasta = min($total, $desde + $ancho - 1);
        $numeros = array_values(array_unique([1, ...range($desde, $hasta), $total]));

        $paginas = [];
        $anterior = 0;
        foreach ($numeros as $numero) {
            if ($numero > $anterior + 1) {
                $paginas[] = null;
            }
            $paginas[] = ['numero' => $numero, 'url' => $this->urlDePagina($filtro, $numero), 'actual' => $numero === $actual];
            $anterior = $numero;
        }

        return $paginas;
    }

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
