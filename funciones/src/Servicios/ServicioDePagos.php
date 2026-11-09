<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\AutorDelCambio;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Lo que pasa cuando Mercado Pago avisa de un pago (X-01.4): la única forma de que una reserva
 * pendiente quede confirmada.
 *
 * Del aviso sólo se usa el id del pago: el estado, el monto y la reserva se le preguntan a
 * Mercado Pago. Así da igual que el aviso llegue repetido o fuera de orden (un «creado» después
 * de un «actualizado»): cada vez se mira cómo está el pago ahora, y un pago ya procesado no
 * cambia nada.
 *
 * Si se cobró algo que no tiene una reserva que confirmar (la reserva se canceló, ya la pagó otro
 * pago, o el monto no coincide), se le devuelve la plata al comprador.
 *
 * Un pago que llega después de que venció la reserva (X-01.5) la confirma igual si todavía quedan
 * los lugares, con la función bloqueada como al reservar; si no quedan, la reserva se cancela y el
 * pago se devuelve.
 */
final class ServicioDePagos
{
    /** Si dos avisos compiten por la misma reserva, el que pierde la vuelve a leer una vez. */
    private const INTENTOS = 2;

    /** Lo que dice el historial de una que venció y ya no tenía lugar cuando llegó el pago. */
    public const MOTIVO_SIN_LUGAR = 'Venció el plazo para pagar y el pago llegó cuando ya no quedaban lugares.';

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly RepositorioDeReservas $reservas,
        private readonly FuncionesDeProgramacion $funciones,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return ?Reserva la reserva, si este aviso la confirmó: para mandar el correo una sola vez
     *
     * @throws ExcepcionHttp    503 si Mercado Pago (o programación, con una reserva vencida) no
     *                          responde: el aviso falla y Mercado Pago lo vuelve a mandar
     * @throws RuntimeException si Mercado Pago responde algo que no se entiende
     */
    public function procesar(string $pagoId, DateTimeImmutable $ahora = new DateTimeImmutable()): ?Reserva
    {
        $pago = $this->cliente->pago($pagoId);
        $contexto = ['pago' => $pagoId];
        if ($pago === null) {
            $this->log->warning('Mercado Pago avisó del pago {pago}, pero no lo encuentra.', $contexto);

            return null;
        }
        // Rechazado, devuelto o en cualquier otro estado: no confirma nada. Con binary_mode no
        // quedan pagos pendientes.
        if (($pago['status'] ?? null) !== 'approved') {
            return null;
        }

        $referencia = (string) ($pago['external_reference'] ?? '');
        $reserva = Uuid::esValido($referencia) ? $this->reservas->buscar($referencia) : null;
        if ($reserva === null) {
            // Un pago de la cuenta que no es de una reserva (otra integración, o una prueba a mano):
            // no es nuestro para devolverlo.
            $this->log->warning('El pago {pago} no es de ninguna reserva.', $contexto);

            return null;
        }
        $contexto['reserva'] = $reserva->id;

        if (!self::cobraLaReserva($pago, $reserva)) {
            $this->log->error('El pago {pago} no cobra lo que vale la reserva {reserva}: se devuelve.', $contexto);
            $this->cliente->reembolsar($pagoId);

            return null;
        }

        for ($intento = 0; $intento < self::INTENTOS; ++$intento) {
            // Ya confirmada con este mismo pago: el aviso repetido no hace nada.
            if ($reserva->pagoId === $pagoId) {
                return null;
            }
            if ($reserva->estado !== EstadoReserva::PendientePago) {
                break;
            }
            $confirmada = $reserva->vencida($ahora)
                ? $this->confirmarVencida($reserva, $pagoId, $ahora)
                : $this->confirmar($reserva, $pagoId, $ahora);
            if ($confirmada instanceof Reserva) {
                $this->log->info('El pago {pago} confirmó la reserva {reserva}.', $contexto);

                return $confirmada;
            }
            if ($confirmada === false) {
                $this->log->warning('El pago {pago} llegó con la reserva {reserva} vencida y sin lugar: se cancela y se devuelve.', $contexto);
                $this->cliente->reembolsar($pagoId);

                return null;
            }
            // Otro aviso, o el espectador, la cambió mientras tanto: se mira cómo quedó.
            $reserva = $this->reservas->buscar($reserva->id) ?? throw new RuntimeException("La reserva {$reserva->id} desapareció.");
        }

        // Cancelada, ya pagada con otro pago, o con la asistencia registrada: este cobro sobra.
        $this->log->warning('El pago {pago} llegó con la reserva {reserva} {estado}: se devuelve.', $contexto + ['estado' => $reserva->estado->etiqueta()]);
        $this->cliente->reembolsar($pagoId);

        return null;
    }

    /**
     * @return ?Reserva la confirmada, o null si cambió mientras tanto
     */
    private function confirmar(Reserva $reserva, string $pagoId, DateTimeImmutable $ahora): ?Reserva
    {
        $confirmada = $reserva->confirmarPago($pagoId);
        // El espectador paga: él es quien la confirma, y el motivo dice con qué pago.
        $autor = new AutorDelCambio($reserva->usuarioId, Rol::Espectador, "Pago {$pagoId} aprobado en Mercado Pago.");

        return $this->reservas->guardarPago($confirmada, $autor, $ahora) ? $confirmada : null;
    }

    /**
     * La reserva venció y sus lugares ya no son suyos: se confirma sólo si todavía están libres y
     * la función todavía admite reservas, con la función bloqueada para que nadie se los lleve
     * mientras tanto.
     *
     * @return Reserva|false|null la confirmada; false si no había lugar y se canceló; null si
     *                            cambió mientras tanto
     *
     * @throws ExcepcionHttp 503 si programación no responde
     */
    private function confirmarVencida(Reserva $reserva, string $pagoId, DateTimeImmutable $ahora): Reserva|false|null
    {
        $funcion = $this->funciones->buscar($reserva->funcionId);

        return $this->reservas->conLaFuncionBloqueada($reserva->funcionId, function () use ($reserva, $funcion, $pagoId, $ahora): Reserva|false|null {
            if (self::quedaLugar($reserva, $funcion, $this->reservas->lugaresOcupados($reserva->funcionId, $ahora), $ahora)) {
                return $this->confirmar($reserva, $pagoId, $ahora);
            }
            $autor = new AutorDelCambio($reserva->usuarioId, Rol::Espectador, self::MOTIVO_SIN_LUGAR);

            return $this->reservas->guardarEstado($reserva->cancelar(), $reserva->estado, $autor, $ahora) ? false : null;
        });
    }

    /**
     * @param int $ocupados sin contar la reserva, que ya venció
     */
    private static function quedaLugar(Reserva $reserva, ?FuncionDeProgramacion $funcion, int $ocupados, DateTimeImmutable $ahora): bool
    {
        return $funcion !== null
            && $funcion->motivoParaNoReservar($ahora) === null
            && $reserva->cantidad <= $funcion->cupo - $ocupados;
    }

    /**
     * Que el pago sea por el total de la reserva y en pesos: sin esto, alguien podría pagar una
     * preferencia propia de $1 con la reserva como referencia.
     *
     * @param array<string, mixed> $pago
     */
    private static function cobraLaReserva(array $pago, Reserva $reserva): bool
    {
        $monto = $pago['transaction_amount'] ?? null;

        return is_numeric($monto)
            && number_format((float) $monto, 2, '.', '') === $reserva->total
            && ($pago['currency_id'] ?? null) === 'ARS';
    }
}
