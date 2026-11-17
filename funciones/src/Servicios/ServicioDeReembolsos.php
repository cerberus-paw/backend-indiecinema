<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La devolución del pago cuando una reserva pagada se cancela (EF-06.4): la cancela el espectador,
 * la anula el administrador o se cancela la función, y los tres pasan por acá.
 *
 * Devuelve una sola vez. La marca es reembolso_id en la reserva, que se anota con una sola
 * sentencia: si dos cancelaciones compiten, una la gana y la otra no le pide nada a Mercado Pago.
 * Y si llegara a pedírselo igual, el pedido lleva la clave de idempotencia reembolso-{pagoId}, así
 * que Mercado Pago contesta el reembolso que ya hizo en lugar de devolver la plata de nuevo.
 *
 * No lanza nada: la reserva ya está cancelada, y que Mercado Pago no responda no puede deshacer la
 * cancelación ni dejar al espectador con un error. Lo que falla queda en el log y, en la reserva,
 * como un reembolso pendiente —cancelada, con pago y sin reembolso—, que el administrador ve en su
 * panel.
 */
final class ServicioDeReembolsos
{
    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly RepositorioDeReservas $reservas,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * Devuelve el pago de la reserva que se acaba de cancelar, si hay algo que devolver: una a la
     * gorra, una confirmada con el cobro apagado, una que venció sin pagar y una ya reembolsada no
     * tienen nada.
     *
     * @return Reserva cómo quedó: con su reembolso si salió, y como entró si no había nada que
     *                 devolver o si no se pudo
     */
    public function reembolsar(Reserva $cancelada, DateTimeImmutable $ahora = new DateTimeImmutable()): Reserva
    {
        if (!$cancelada->seReembolsa()) {
            return $cancelada;
        }
        $pagoId = (string) $cancelada->pagoId;
        $contexto = ['reserva' => $cancelada->id, 'pago' => $pagoId];

        try {
            // Se pagó cuando el cobro estaba prendido, y ahora está apagado o mal configurado: sin
            // credenciales no hay con qué devolver, y queda pendiente.
            if ($this->cliente->credenciales() === null) {
                $this->log->error('La reserva {reserva} se canceló con el pago {pago} y el cobro apagado: el reembolso queda pendiente.', $contexto);

                return $cancelada;
            }
            $reembolsoId = $this->cliente->reembolsar($pagoId);
            // Sin el id no se puede anotar, y el cliente ya lo dejó en el log: queda pendiente, y
            // reintentarlo es seguro por la clave de idempotencia.
            if ($reembolsoId === null) {
                return $cancelada;
            }
            $reembolsada = $cancelada->conReembolso($reembolsoId, $ahora);
            if (!$this->reservas->guardarReembolso($reembolsada)) {
                $this->log->info('El reembolso de la reserva {reserva} ya estaba anotado.', $contexto);

                return $this->reservas->buscar($cancelada->id) ?? $cancelada;
            }
            $this->log->info('Se devolvió el pago {pago} de la reserva {reserva}, con el reembolso {reembolso}.', $contexto + ['reembolso' => $reembolsoId]);

            return $reembolsada;
        } catch (Throwable $error) {
            // Mercado Pago no respondió, lo rechazó, o la base no lo tomó: la cancelación sigue en
            // pie y el reembolso queda pendiente a la vista del administrador.
            $this->log->error('No se pudo devolver el pago {pago} de la reserva {reserva}: {motivo}', $contexto + ['motivo' => $error->getMessage()]);

            return $cancelada;
        }
    }
}
