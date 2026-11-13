<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\AutorDelCambio;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;

/**
 * El seguimiento de las reservas (E4-03): el organizador ve las de sus funciones y registra la
 * asistencia en la puerta, el administrador ve todas y anula la que haga falta, y al cancelarse
 * una función se cancelan sus reservas. Cada cambio queda en el historial con su autor.
 */
final class ServicioDePanelDeReservas
{
    /** Las reservas que ve el administrador por página. */
    public const POR_PAGINA = 20;

    /**
     * @param ?ServicioDeReembolsos $reembolsos la devolución del pago al anular y al cancelarse la
     *                                          función (EF-06.4); sin él, la reserva se cancela y
     *                                          no se devuelve nada
     */
    public function __construct(
        private readonly FuncionesDeProgramacion $funciones,
        private readonly RepositorioDeReservas $reservas,
        private readonly ?ServicioDeReembolsos $reembolsos = null,
    ) {
    }

    /**
     * Una función del organizador, para su panel. La de otro organizador es 404, como si no
     * existiera: así no se puede averiguar qué ids existen.
     *
     * @throws ExcepcionHttp 404 si no existe o es de otro; 503 si programación no responde
     */
    public function funcionPropia(Usuario $organizador, string $funcionId): FuncionDeProgramacion
    {
        $funcion = $this->funciones->buscar($funcionId);
        if ($funcion === null || !$funcion->esDe($organizador->id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $funcion;
    }

    /**
     * La función del organizador con todas sus reservas, de la primera a la última.
     *
     * @return array{FuncionDeProgramacion, list<Reserva>}
     *
     * @throws ExcepcionHttp 404 si no existe o es de otro; 503 si programación no responde
     */
    public function deLaFuncion(Usuario $organizador, string $funcionId): array
    {
        $funcion = $this->funcionPropia($organizador, $funcionId);

        return [$funcion, $this->reservas->deLaFuncion($funcion->id)];
    }

    /**
     * Registra en la puerta que entró quien reservó: la reserva pasa a «asistió», una sola vez.
     * El código se acepta como lo dicte el espectador: en minúsculas o con espacios y guiones.
     *
     * @throws ExcepcionHttp   404 si la función no existe o es de otro
     * @throws ReglaIncumplida si la función se canceló, no hay una reserva con ese código para esta
     *                         función, ya está registrada, está cancelada, o cambió mientras tanto
     */
    public function registrarAsistencia(Usuario $organizador, string $funcionId, string $codigo, DateTimeImmutable $ahora = new DateTimeImmutable()): Reserva
    {
        $funcion = $this->funcionPropia($organizador, $funcionId);
        if ($funcion->estado === 'cancelada') {
            throw new ReglaIncumplida('La función está cancelada: no hay asistencia que registrar.');
        }
        $codigo = self::codigo($codigo);
        if ($codigo === '') {
            throw new ReglaIncumplida('Escribí el código de la reserva.');
        }
        $reserva = $this->reservas->porCodigo($funcion->id, $codigo)
            ?? throw new ReglaIncumplida("No hay ninguna reserva con el código {$codigo} para esta función.");
        match ($reserva->estado) {
            EstadoReserva::Asistio => throw new ReglaIncumplida("La reserva {$codigo} ya tiene la asistencia registrada."),
            EstadoReserva::Cancelada => throw new ReglaIncumplida("La reserva {$codigo} está cancelada: sus lugares se liberaron."),
            // Sin el pago acreditado no hay entrada: el espectador todavía la puede pagar.
            EstadoReserva::PendientePago => throw new ReglaIncumplida("La reserva {$codigo} todavía no se pagó."),
            // La función ya terminó y la tarea la dejó ausente, pero entró: se registra tarde.
            EstadoReserva::Confirmada, EstadoReserva::Ausente => null,
        };
        $conAsistencia = $reserva->registrarAsistencia();
        if (!$this->reservas->guardarEstado($conAsistencia, $reserva->estado, AutorDelCambio::de($organizador, Rol::Organizador), $ahora)) {
            throw new ReglaIncumplida("La reserva {$codigo} cambió mientras tanto. Volvé a cargar la página para ver en qué quedó.");
        }

        return $conAsistencia;
    }

    /**
     * Todas las reservas, para el panel del administrador.
     *
     * @param int $numero desde 1
     *
     * @return array{0: list<Reserva>, 1: int} las de la página y cuántas hay
     */
    public function pagina(?EstadoReserva $estado, int $numero): array
    {
        return $this->reservas->pagina($estado, $numero, self::POR_PAGINA);
    }

    /**
     * El administrador anula una reserva confirmada: queda cancelada y sus lugares se liberan, y
     * si estaba pagada se le devuelve la plata al espectador (EF-06.4). Si el reembolso no sale, la
     * anulación queda igual y el panel la muestra con el reembolso pendiente.
     *
     * @throws ExcepcionHttp   404 si no existe
     * @throws ReglaIncumplida si no está confirmada, o cambió mientras tanto
     */
    public function anular(Usuario $administrador, string $id, DateTimeImmutable $ahora = new DateTimeImmutable()): Reserva
    {
        $reserva = (Uuid::esValido($id) ? $this->reservas->buscar($id) : null) ?? throw ExcepcionHttp::noEncontrada();
        if ($reserva->estado !== EstadoReserva::Confirmada) {
            throw new ReglaIncumplida("La reserva {$reserva->codigo} ya está {$reserva->estado->etiqueta()}: no se puede anular.");
        }
        $anulada = $reserva->cancelar();
        $autor = AutorDelCambio::de($administrador, Rol::Administrador, 'Anulada por el administrador.');
        if (!$this->reservas->guardarEstado($anulada, $reserva->estado, $autor, $ahora)) {
            throw new ReglaIncumplida("La reserva {$reserva->codigo} cambió mientras tanto. Volvé a cargar la página para ver en qué quedó.");
        }

        return $this->reembolsos?->reembolsar($anulada, $ahora) ?? $anulada;
    }

    /**
     * Cancela las reservas confirmadas de una función que se canceló (E4-03.4), y las que esperaban
     * el pago, que ya no tienen para qué pagarse. Las que ya tienen la asistencia o ya estaban
     * canceladas quedan como están. Llamarla dos veces no cambia nada: la segunda no encuentra
     * ninguna que cancelar, y una reembolsada no se vuelve a reembolsar (EF-06.4).
     *
     * A cada una que estaba pagada se le devuelve la plata, una por una: si Mercado Pago se cae en
     * la tercera, las dos primeras ya están devueltas y las demás quedan pendientes.
     *
     * @param string $organizadorId quién canceló la función, para el historial
     *
     * @return list<Reserva> las que se cancelaron ahora, para avisarles a sus espectadores
     */
    public function cancelarDeLaFuncion(string $funcionId, string $organizadorId, DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        $autor = new AutorDelCambio($organizadorId, Rol::Organizador, 'Se canceló la función.');
        $canceladas = [];
        foreach ($this->reservas->deLaFuncion($funcionId) as $reserva) {
            if (!in_array($reserva->estado, [EstadoReserva::Confirmada, EstadoReserva::PendientePago], true)) {
                continue;
            }
            $cancelada = $reserva->cancelar();
            // Si el espectador la canceló justo antes (o se acreditó el pago), ya no está como se
            // leyó y no se toca.
            if ($this->reservas->guardarEstado($cancelada, $reserva->estado, $autor, $ahora)) {
                $canceladas[] = $this->reembolsos?->reembolsar($cancelada, $ahora) ?? $cancelada;
            }
        }

        return $canceladas;
    }

    /**
     * La función terminó (EF-04.1): las reservas confirmadas que no tienen la asistencia quedan
     * ausentes, y las que ya entraron, las canceladas y las que esperan el pago quedan como están
     * (una pendiente vence sola, X-01.5). Como cancelarDeLaFuncion(), repetirla no cambia nada:
     * programación la vuelve a pedir si no recibió la respuesta.
     *
     * @param string $organizadorId el de la función, autor del cambio en el historial
     *
     * @return int cuántas quedaron ausentes ahora
     */
    public function marcarAusentes(string $funcionId, string $organizadorId, DateTimeImmutable $ahora = new DateTimeImmutable()): int
    {
        $autor = new AutorDelCambio($organizadorId, Rol::Organizador, 'La función terminó sin la asistencia registrada.');
        $ausentes = 0;
        foreach ($this->reservas->deLaFuncion($funcionId) as $reserva) {
            if ($reserva->estado !== EstadoReserva::Confirmada) {
                continue;
            }
            // Si el organizador registró la asistencia justo antes, ya no está confirmada y no se toca.
            if ($this->reservas->guardarEstado($reserva->marcarAusente(), $reserva->estado, $autor, $ahora)) {
                ++$ausentes;
            }
        }

        return $ausentes;
    }

    /**
     * Los números del panel del organizador: cuántos lugares están reservados y cuántos quedan,
     * cuántos ya entraron y cuánta plata entró.
     *
     * Lo recaudado es sólo lo que de verdad se cobró (EF-06.5): las reservas con pago de Mercado
     * Pago que no se devolvió, el mismo criterio que el neto que reparte recaudación. Una
     * pendiente todavía no pagó, y una confirmada con el cobro apagado —o a la gorra— no pagó
     * nunca, aunque su total diga 3500. Por eso no se suma sobre las que ocupan lugares: una
     * cancelada que todavía no se reembolsó tiene la plata en la cuenta y sigue contando, y el
     * total baja en el momento en que vuelve.
     *
     * @param list<Reserva> $reservas las de la función
     *
     * @return array{reservados: int, libres: int, asistentes: int, recaudado: float, reservas: int}
     */
    public static function resumen(FuncionDeProgramacion $funcion, array $reservas, DateTimeImmutable $ahora = new DateTimeImmutable()): array
    {
        // Una pendiente que venció ya no retiene sus lugares, aunque la tarea no la haya cancelado.
        $enPie = array_values(array_filter($reservas, static fn (Reserva $reserva): bool => $reserva->estado->ocupaLugares() && !$reserva->vencida($ahora)));
        $reservados = array_sum(array_map(static fn (Reserva $reserva): int => $reserva->cantidad, $enPie));

        return [
            'reservados' => $reservados,
            'libres' => max(0, $funcion->cupo - $reservados),
            'asistentes' => array_sum(array_map(
                static fn (Reserva $reserva): int => $reserva->estado === EstadoReserva::Asistio ? $reserva->cantidad : 0,
                $reservas,
            )),
            'recaudado' => array_sum(array_map(
                static fn (Reserva $reserva): float => $reserva->cobrada() ? (float) $reserva->total : 0.0,
                $reservas,
            )),
            'reservas' => count($enPie),
        ];
    }

    /** «ab12 cd-34» → «AB12CD34»: como lo dicta el espectador, sin lo que no es del código. */
    private static function codigo(string $codigo): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $codigo));
    }
}
