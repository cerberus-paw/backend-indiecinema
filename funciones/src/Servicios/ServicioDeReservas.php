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
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Las reservas de los espectadores (E4-01): que nunca se confirmen más lugares que el cupo,
 * aunque dos personas reserven a la vez.
 */
final class ServicioDeReservas
{
    /** Cuántas veces se sortea el código si sale uno repetido, que ya es muy raro una vez. */
    private const INTENTOS_DE_CODIGO = 3;

    /** Las reservas por página de «Mis reservas»: las funciones de una página van en un pedido. */
    public const POR_PAGINA = 20;

    /**
     * @param ?PagosDeReservas      $pagos      el cobro con Mercado Pago; sin él, como con el cobro
     *                                          apagado, toda reserva nace confirmada
     * @param ?ServicioDeReembolsos $reembolsos la devolución del pago al cancelar (EF-06.4); sin
     *                                          él, la reserva se cancela y no se devuelve nada
     */
    public function __construct(
        private readonly FuncionesDeProgramacion $funciones,
        private readonly RepositorioDeReservas $reservas,
        private readonly ?PagosDeReservas $pagos = null,
        private readonly ?ServicioDeReembolsos $reembolsos = null,
    ) {
    }

    /**
     * La función para la página de la reserva.
     *
     * @throws ExcepcionHttp 404 si no existe o su sala no está publicada; 503 si programación no
     *                       responde
     */
    public function funcion(string $funcionId): FuncionDeProgramacion
    {
        $funcion = $this->funciones->buscar($funcionId);
        // Una sala sin publicar no muestra sus funciones: es lo mismo que si no existiera.
        if ($funcion === null || !$funcion->salaHabilitada) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $funcion;
    }

    /**
     * Una reserva del espectador, con su función. La de otro es 404, como si no existiera: así no
     * se puede averiguar qué ids existen.
     *
     * @return array{Reserva, ?FuncionDeProgramacion} la función es null si programación ya no la
     *                                                muestra (su sala se dio de baja)
     *
     * @throws ExcepcionHttp 404
     */
    public function delEspectador(Usuario $espectador, string $id): array
    {
        $reserva = Uuid::esValido($id) ? $this->reservas->buscar($id) : null;
        if ($reserva === null || !hash_equals($reserva->usuarioId, $espectador->id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return [$reserva, $this->funciones->buscar($reserva->funcionId)];
    }

    /**
     * Una página de «Mis reservas» (E4-04.1): sólo las del espectador, de la más nueva a la más
     * vieja y en cualquier estado.
     *
     * @param int $numero desde 1
     *
     * @return array{0: list<Reserva>, 1: int} las de la página y cuántas tiene en total
     */
    public function pagina(Usuario $espectador, int $numero): array
    {
        return $this->reservas->delUsuario($espectador->id, $numero, self::POR_PAGINA);
    }

    /**
     * Las funciones de esas reservas, en un solo pedido a programación.
     *
     * @param list<Reserva> $reservas
     *
     * @return array<string, FuncionDeProgramacion> con el id como clave; las que programación ya
     *                                              no muestra no están
     *
     * @throws ExcepcionHttp    503 si programación no responde
     * @throws RuntimeException si responde algo que no son funciones
     */
    public function funcionesDe(array $reservas): array
    {
        return $this->funciones->buscarVarias(array_values(array_unique(array_map(static fn (Reserva $reserva): string => $reserva->funcionId, $reservas))));
    }

    /**
     * Si el espectador todavía puede cancelar la reserva: confirmada, y antes de que empiece la
     * función. Después ya no tiene sentido soltar los lugares, y quien entró ya usó la entrada.
     * Si programación ya no muestra la función (su sala se dio de baja), se puede.
     */
    public static function seCancela(Reserva $reserva, ?FuncionDeProgramacion $funcion, DateTimeImmutable $ahora = new DateTimeImmutable()): bool
    {
        return $reserva->estado->puedePasarA(EstadoReserva::Cancelada)
            && ($funcion?->fechaHora === null || $funcion->fechaHora > $ahora);
    }

    /**
     * Cancela la reserva del espectador: sus lugares vuelven a quedar libres para otros. No
     * bloquea la función como reservar(): soltar lugares nunca pasa el cupo.
     *
     * Si la había pagado, se le devuelve la plata (EF-06.4). Si Mercado Pago no la devuelve, la
     * reserva queda cancelada igual: el espectador no se come un error por algo que se arregla del
     * otro lado, y el reembolso queda pendiente para el administrador.
     *
     * @throws ExcepcionHttp   404 si no es suya
     * @throws ReglaIncumplida si ya no está confirmada, la función ya empezó, o cambió mientras
     *                         tanto (el organizador registró la asistencia)
     */
    public function cancelar(Usuario $espectador, string $id, DateTimeImmutable $ahora = new DateTimeImmutable()): Reserva
    {
        [$reserva, $funcion] = $this->delEspectador($espectador, $id);
        // Los mensajes para el espectador, más claros que el del ciclo de vida.
        match ($reserva->estado) {
            EstadoReserva::Cancelada => throw new ReglaIncumplida('La reserva ya está cancelada.'),
            EstadoReserva::Asistio => throw new ReglaIncumplida('Ya registraron tu asistencia: la reserva no se puede cancelar.'),
            EstadoReserva::Ausente => throw new ReglaIncumplida('La función ya terminó: la reserva ya no se puede cancelar.'),
            // Una pendiente se cancela igual: suelta los lugares sin esperar a que venza.
            EstadoReserva::Confirmada, EstadoReserva::PendientePago => null,
        };
        if (!self::seCancela($reserva, $funcion, $ahora)) {
            throw new ReglaIncumplida('La función ya empezó: la reserva ya no se puede cancelar.');
        }
        $cancelada = $reserva->cancelar();
        if (!$this->reservas->guardarEstado($cancelada, $reserva->estado, AutorDelCambio::de($espectador, Rol::Espectador), $ahora)) {
            throw new ReglaIncumplida('La reserva cambió mientras tanto. Volvé a cargarla para ver en qué quedó.');
        }

        // Después de guardarla: sólo se devuelve el pago de una cancelación que de verdad quedó.
        return $this->reembolsos?->reembolsar($cancelada, $ahora) ?? $cancelada;
    }

    /**
     * Los lugares que quedan libres: el cupo que dio programación menos lo que ocupan las reservas
     * de acá. Nunca menos de cero, por si el organizador bajó el cupo después de que reservaron.
     *
     * Es lo que funciones sabe de la función; si está «agotada» para mostrar lo decide
     * programación, que es la que conoce el estado y la fecha.
     */
    public function lugaresLibres(FuncionDeProgramacion $funcion, DateTimeImmutable $ahora = new DateTimeImmutable()): int
    {
        return max(0, $funcion->cupo - $this->reservas->lugaresOcupados($funcion->id, $ahora));
    }

    /**
     * Lo más que puede tener reservado una misma persona en esta función.
     */
    public static function maximoPorPersona(FuncionDeProgramacion $funcion): int
    {
        return min(Reserva::MAXIMO_POR_PERSONA, $funcion->cupo);
    }

    /**
     * Cuántos lugares más puede reservar el espectador en esta función sin superar el tope personal.
     */
    public function lugaresRestantesPara(FuncionDeProgramacion $funcion, string $usuarioId): int
    {
        $yaReservados = $this->reservas->lugaresOcupadosPorUsuario($funcion->id, $usuarioId);

        return max(0, self::maximoPorPersona($funcion) - $yaReservados);
    }

    /**
     * El mensaje cuando el pedido supera el tope por persona: dice siempre cuál es el máximo.
     */
    public static function mensajeDeMaximoPorPersona(int $yaReservados, int $restantes, int $maximo): string
    {
        if ($yaReservados === 0) {
            return "Se pueden reservar hasta {$maximo} lugares por persona en cada función.";
        }
        if ($restantes === 0) {
            return "Ya tenés reservados los {$maximo} lugares máximos por persona para esta función.";
        }

        $lugaresTexto = $yaReservados === 1 ? '1 lugar reservado' : "{$yaReservados} lugares reservados";
        $sumarTexto = $restantes === 1 ? '1 lugar más' : "hasta {$restantes} lugares más";

        return "Ya tenés {$lugaresTexto}: podés sumar {$sumarTexto} (máximo {$maximo} por persona).";
    }

    /**
     * Reserva $cantidad lugares de la función para el espectador.
     *
     * El cupo se pide a programación antes de bloquear: así la espera por la API interna no
     * frena a las otras reservas de la función.
     *
     * Si es paga y el cobro está prendido, la reserva queda pendiente de pago con su preferencia de
     * Checkout Pro (X-01.2), que se crea después de soltar la función: la espera por Mercado Pago
     * tampoco frena a las otras. Si Mercado Pago no la crea, la reserva se cancela, para que no
     * retenga lugares que nadie puede pagar.
     *
     * @throws ExcepcionHttp    404 si la función no existe o su sala no está publicada; 503 si
     *                          programación o Mercado Pago no responden, o la función queda
     *                          bloqueada demasiado
     * @throws ReglaIncumplida  si la cantidad no es válida, la función ya no admite reservas o no
     *                          quedan tantos lugares
     * @throws RuntimeException si Mercado Pago rechaza la preferencia: es un error nuestro
     */
    public function reservar(
        Usuario $espectador,
        string $funcionId,
        int $cantidad,
        DateTimeImmutable $ahora = new DateTimeImmutable(),
    ): Reserva {
        $funcion = $this->funcion($funcionId);
        $reserva = Reserva::nueva($funcion, $espectador->id, $cantidad, $ahora, $this->pagos?->minutosParaPagar());
        $reserva = $this->agregar($funcion, $reserva, $ahora);
        if ($reserva->estado !== EstadoReserva::PendientePago || $this->pagos === null) {
            return $reserva;
        }

        try {
            $conPreferencia = $this->pagos->crearPreferencia($reserva, $funcion);
        } catch (Throwable $error) {
            $this->reservas->guardarEstado($reserva->cancelar(), $reserva->estado, AutorDelCambio::de($espectador, Rol::Espectador, 'No se pudo iniciar el pago.'), $ahora);

            throw $error;
        }
        // Si el espectador ya la canceló desde otra pestaña, no hay nada que cobrar: queda como
        // la dejó, y el comprobante lo muestra.
        if (!$this->reservas->guardarPreferencia($conPreferencia)) {
            return $this->reservas->buscar($reserva->id) ?? $reserva;
        }

        return $conPreferencia;
    }

    /**
     * Guarda la reserva si quedan lugares, con la función bloqueada. Si no quedan tantos, el
     * trabajo bloqueado lanza la ReglaIncumplida con cuántos quedan.
     *
     * @throws ExcepcionHttp 503 si la función queda bloqueada demasiado
     */
    private function agregar(FuncionDeProgramacion $funcion, Reserva $reserva, DateTimeImmutable $ahora): Reserva
    {
        return $this->reservas->conLaFuncionBloqueada($funcion->id, function () use ($funcion, $reserva, $ahora): Reserva {
            $libres = $this->lugaresLibres($funcion, $ahora);
            if ($reserva->cantidad > $libres) {
                throw new ReglaIncumplida(match ($libres) {
                    0 => 'La función está agotada.',
                    1 => 'Queda 1 lugar.',
                    default => "Quedan {$libres} lugares.",
                });
            }
            $yaReservados = $this->reservas->lugaresOcupadosPorUsuario($funcion->id, $reserva->usuarioId, $ahora);
            $maximo = self::maximoPorPersona($funcion);
            $restantes = max(0, $maximo - $yaReservados);
            if ($reserva->cantidad > $restantes) {
                throw new ReglaIncumplida(self::mensajeDeMaximoPorPersona($yaReservados, $restantes, $maximo));
            }
            for ($intento = 0; $intento < self::INTENTOS_DE_CODIGO; ++$intento) {
                if ($this->reservas->agregar($reserva)) {
                    return $reserva;
                }
                $reserva = $reserva->conOtroCodigo();
            }
            throw new LogicException('Salió el mismo código de reserva ' . self::INTENTOS_DE_CODIGO . ' veces: revisar el sorteo.');
        });
    }
}
