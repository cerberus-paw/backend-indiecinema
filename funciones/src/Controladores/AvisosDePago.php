<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Servicios\AvisosDeReservas;
use IndieCinema\Funciones\Servicios\ClienteMercadoPago;
use IndieCinema\Funciones\Servicios\FirmaDeMercadoPago;
use IndieCinema\Funciones\Servicios\FuncionesDeProgramacion;
use IndieCinema\Funciones\Servicios\ServicioDePagos;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * POST /pagos/aviso: el webhook de Mercado Pago (X-01.4). Es una ruta pública, sin sesión ni token
 * CSRF (Router::webhook()), así que lo primero es la firma: sin ella, 401 y no se hace nada.
 *
 * Mercado Pago vuelve a mandar el aviso hasta que recibe un 2xx. Por eso un aviso que ya se
 * procesó, o uno que no es de un pago, responde 200; y si algo de este lado no anda (Mercado Pago
 * o programación no responden), el error deja que lo reintente.
 */
final class AvisosDePago extends Controlador
{
    public function __construct(
        private readonly ServicioDePagos $pagos,
        private readonly ClienteMercadoPago $cliente,
        private readonly AvisosDeReservas $avisos,
        private readonly FuncionesDeProgramacion $funciones,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @throws ExcepcionHttp 404 con el cobro apagado; 400 sin el id del pago; 503 si Mercado
     *                       Pago no responde
     */
    public function recibir(Peticion $peticion): Respuesta
    {
        $credenciales = $this->cliente->credenciales() ?? throw ExcepcionHttp::noEncontrada();

        // El aviso con el formato de los webhooks: ?data.id=…&type=payment (PHP pasa el punto a
        // guion bajo en $_GET). El de IPN (?topic=payment&id=…) no viene firmado, y el mismo pago
        // llega también como webhook: se contesta 200 sin hacer nada, para que no se reintente.
        $tipo = $peticion->consulta('type') ?? $peticion->campo('type');
        if ($tipo !== 'payment') {
            return $this->json(['procesado' => false]);
        }
        $datos = $peticion->campo('data');
        $pagoId = (string) ($peticion->consulta('data_id') ?? $peticion->consulta('data.id') ?? (is_array($datos) ? ($datos['id'] ?? '') : ''));
        if (preg_match('/^\d{1,20}$/D', $pagoId) !== 1) {
            throw ExcepcionHttp::solicitudInvalida('El aviso no trae el id del pago.');
        }

        if (!FirmaDeMercadoPago::esValida($peticion->cabecera('x-signature'), $peticion->cabecera('x-request-id'), $pagoId, $credenciales->claveWebhook)) {
            // Sin datos del pedido: no hace falta guardar lo que mandó quien no firmó.
            $this->log->warning('Llegó un aviso de pago sin una firma válida.');

            return $this->json(['error' => 'Firma inválida.'], 401);
        }

        $confirmada = $this->pagos->procesar($pagoId);
        $respuesta = $this->json(['procesado' => true]);

        // El correo del comprobante sale recién ahora, y sólo con el aviso que la confirmó: uno
        // repetido no lo vuelve a mandar. Después de responder, para no hacer esperar a Mercado Pago.
        return $confirmada === null ? $respuesta : $respuesta->alTerminar(fn (): bool => $this->avisarConfirmada($confirmada));
    }

    /**
     * @return bool si salió el correo
     */
    private function avisarConfirmada(Reserva $reserva): bool
    {
        try {
            $funcion = $this->funciones->buscar($reserva->funcionId);
        } catch (ExcepcionHttp | RuntimeException $error) {
            $funcion = null;
        }
        if ($funcion === null) {
            // La reserva ya quedó confirmada; el comprobante está en «Mis reservas».
            $this->log->warning('Sin la función de la reserva {reserva}, no salió el correo del pago confirmado.', ['reserva' => $reserva->id]);

            return false;
        }

        try {
            return $this->avisos->reservaConfirmada($reserva, $funcion);
        } catch (RuntimeException $error) {
            // Falta [sitio] url: ya no hay a quién responderle, así que queda en el log.
            $this->log->error('No salió el correo del pago confirmado de la reserva {reserva}: {motivo}', ['reserva' => $reserva->id, 'motivo' => $error->getMessage()]);

            return false;
        }
    }
}
