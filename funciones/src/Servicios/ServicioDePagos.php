<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\AutorDelCambio;
use IndieCinema\Funciones\Modelo\EstadoReserva;
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
 */
final class ServicioDePagos
{
    /** Si dos avisos compiten por la misma reserva, el que pierde la vuelve a leer una vez. */
    private const INTENTOS = 2;

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly RepositorioDeReservas $reservas,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return ?Reserva la reserva, si este aviso la confirmó: para mandar el correo una sola vez
     *
     * @throws ExcepcionHttp    503 si Mercado Pago no responde: el aviso falla y Mercado Pago lo
     *                          vuelve a mandar
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
            $confirmada = $reserva->confirmarPago($pagoId);
            // El espectador paga: él es quien la confirma, y el motivo dice con qué pago.
            $autor = new AutorDelCambio($reserva->usuarioId, Rol::Espectador, "Pago {$pagoId} aprobado en Mercado Pago.");
            if ($this->reservas->guardarPago($confirmada, $autor, $ahora)) {
                $this->log->info('El pago {pago} confirmó la reserva {reserva}.', $contexto);

                return $confirmada;
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
