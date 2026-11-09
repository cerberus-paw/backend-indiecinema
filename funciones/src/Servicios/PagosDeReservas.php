<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeInterface;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use RuntimeException;

/**
 * El cobro de una reserva con Checkout Pro (X-01.2): arma la preferencia con lo que paga el
 * espectador, adónde vuelve después y adónde avisa Mercado Pago cuando el pago se acredita.
 *
 * La preferencia no confirma nada: la reserva queda pendiente hasta el aviso del pago verificado.
 */
final class PagosDeReservas
{
    /** El aviso del pago, en funciones (rutas.php): desde afuera, con el prefijo /funcion. */
    public const RUTA_DEL_AVISO = '/pagos/aviso';

    /** Lo que se ve en el resumen de la tarjeta: hasta 13 caracteres, sin tildes. */
    private const DESCRIPTOR = 'INDIECINEMA';

    public function __construct(
        private readonly ClienteMercadoPago $cliente,
        private readonly Configuracion $configuracion,
    ) {
    }

    /**
     * Cuánto tiene el espectador para pagar, o null si el cobro está apagado y una función paga se
     * confirma sin cobrar.
     *
     * @throws RuntimeException si [mercadopago] está prendido y le falta algo
     */
    public function minutosParaPagar(): ?int
    {
        return $this->cliente->credenciales()?->minutosParaPagar;
    }

    /**
     * Si el cobro está prendido: las pantallas muestran el paso «Pago» sólo entonces.
     */
    public function activos(): bool
    {
        return $this->cliente->credenciales() !== null;
    }

    /**
     * Que se va a poder armar la preferencia. Se llama antes de reservar, como
     * AvisosDeReservas::comprobarConfiguracion(): un error de configuración no deja la reserva a
     * medias.
     *
     * @throws RuntimeException si [mercadopago] está prendido y le falta algo, o falta [sitio] url
     */
    public function comprobarConfiguracion(): void
    {
        if ($this->cliente->credenciales() !== null) {
            DireccionDelSitio::de($this->configuracion);
        }
    }

    /**
     * La reserva pendiente con su preferencia ya creada en Mercado Pago.
     *
     * @throws ExcepcionHttp    503 si Mercado Pago no responde
     * @throws RuntimeException si la rechaza, o falta [sitio] url
     */
    public function crearPreferencia(Reserva $reserva, FuncionDeProgramacion $funcion): Reserva
    {
        // La reserva como clave: si el pedido se repite, Mercado Pago devuelve la misma.
        $preferencia = $this->cliente->crearPreferencia($this->preferencia($reserva, $funcion), "preferencia-{$reserva->id}");

        return $reserva->conPreferencia($preferencia['id'], $preferencia['init_point']);
    }

    /**
     * El cuerpo de POST /checkout/preferences.
     *
     * external_reference es el id de la reserva: con él, el aviso del pago dice qué reserva pagó.
     * binary_mode deja el pago aprobado o rechazado, sin pendientes (un pago en efectivo que se
     * acredita en días no le sirve a una reserva que vence en minutos). La preferencia vence con
     * la reserva: después, Mercado Pago ya no deja pagarla.
     *
     * El regreso automático y el aviso piden una dirección pública: en localhost no van, y el pago
     * queda pendiente hasta que vence (ver el README).
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException si falta [sitio] url
     */
    public function preferencia(Reserva $reserva, FuncionDeProgramacion $funcion): array
    {
        $regreso = DireccionDelSitio::deFunciones($this->configuracion, "/reservas/{$reserva->id}");
        $preferencia = [
            'items' => [[
                'id' => $funcion->id,
                'title' => mb_substr("Entrada para «{$funcion->titulo()}»", 0, 250),
                'description' => mb_substr("{$funcion->salaNombre} · {$funcion->salaLocalidad}", 0, 250),
                'category_id' => 'tickets',
                'quantity' => $reserva->cantidad,
                // El total con dos decimales dividido por la cantidad da el precio por lugar de la
                // reserva, que es el de cuando se reservó.
                'unit_price' => round((float) $reserva->total / $reserva->cantidad, 2),
                'currency_id' => 'ARS',
            ]],
            'external_reference' => $reserva->id,
            'back_urls' => ['success' => $regreso, 'pending' => $regreso, 'failure' => $regreso],
            'binary_mode' => true,
            'statement_descriptor' => self::DESCRIPTOR,
            'expires' => true,
            'expiration_date_from' => $reserva->creadaEn->format(DateTimeInterface::RFC3339_EXTENDED),
            'expiration_date_to' => $reserva->venceEn?->format(DateTimeInterface::RFC3339_EXTENDED),
        ];
        if (str_starts_with($regreso, 'https://')) {
            $preferencia['auto_return'] = 'approved';
            $preferencia['notification_url'] = DireccionDelSitio::deFunciones($this->configuracion, self::RUTA_DEL_AVISO);
        }

        return $preferencia;
    }
}
