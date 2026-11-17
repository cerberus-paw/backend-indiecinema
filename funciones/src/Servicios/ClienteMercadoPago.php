<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use JsonException;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Llama a la API de Mercado Pago con el access token de la cuenta vendedora (X-01). Usa el curl
 * de PHP, sin el SDK, como ClienteInterno del núcleo: son tres pedidos y no hace falta sumar una
 * dependencia con todo lo que trae.
 *
 * Si Mercado Pago no responde, no deja la página colgada: corta con un 503. Espera más que la API
 * interna (cinco segundos y no dos): es un servicio de afuera, por internet.
 */
final class ClienteMercadoPago
{
    private readonly ?CredencialesDeMercadoPago $credenciales;
    private readonly ?RuntimeException $errorDeConfiguracion;

    public function __construct(
        Configuracion $configuracion,
        private readonly LoggerInterface $log,
        private readonly int $esperaMaximaMs = 5000,
    ) {
        // El contenedor lo arma en cada pedido que lo usa: un error de configuración se guarda y
        // salta al usarlo (credenciales()), con el motivo en el log, en lugar de romper el
        // armado del controlador, que el contenedor podría tapar.
        try {
            $this->credenciales = CredencialesDeMercadoPago::desdeConfiguracion($configuracion);
            $this->errorDeConfiguracion = null;
        } catch (RuntimeException $error) {
            $this->credenciales = null;
            $this->errorDeConfiguracion = $error;
        }
    }

    /**
     * Las credenciales, o null si el cobro está apagado.
     *
     * @throws RuntimeException si [mercadopago] está prendido y le falta algo
     */
    public function credenciales(): ?CredencialesDeMercadoPago
    {
        if ($this->errorDeConfiguracion !== null) {
            throw $this->errorDeConfiguracion;
        }

        return $this->credenciales;
    }

    /**
     * Crea la preferencia de Checkout Pro: lo que el espectador va a pagar, y adónde vuelve.
     *
     * @param array<string, mixed> $preferencia el cuerpo de POST /checkout/preferences
     *
     * @return array{id: string, init_point: string}
     *
     * @throws ExcepcionHttp    503 si Mercado Pago no responde o falla
     * @throws RuntimeException si rechaza la preferencia: es un error nuestro
     */
    public function crearPreferencia(array $preferencia, string $claveDeIdempotencia): array
    {
        $respuesta = $this->pedir('POST', '/checkout/preferences', $preferencia, $claveDeIdempotencia);
        if (!is_array($respuesta) || !is_string($respuesta['id'] ?? null) || !is_string($respuesta['init_point'] ?? null)) {
            throw new RuntimeException('Mercado Pago no devolvió el id y el init_point de la preferencia.');
        }

        return ['id' => $respuesta['id'], 'init_point' => $respuesta['init_point']];
    }

    /**
     * Un pago, como lo tiene Mercado Pago ahora: lo que se le cree, y no lo que dice el aviso.
     *
     * @return array<string, mixed>|null null si no existe
     *
     * @throws ExcepcionHttp    503 si Mercado Pago no responde o falla
     * @throws RuntimeException si responde otro error, o algo que no es un pago
     */
    public function pago(string $id): ?array
    {
        $pago = $this->pedir('GET', '/v1/payments/' . rawurlencode($id), null);
        if ($pago !== null && !is_array($pago)) {
            throw new RuntimeException('Mercado Pago no devolvió un pago.');
        }

        return $pago;
    }

    /**
     * Devuelve el pago entero al comprador. Con el pago como clave de idempotencia: pedirlo dos
     * veces no lo devuelve dos veces, así que reintentarlo es seguro.
     *
     * @return ?string el id del reembolso, para dejarlo en la reserva (EF-06.4); null si la
     *                 respuesta no lo trae, y entonces el reembolso se hizo pero queda sin anotar:
     *                 el próximo intento lo vuelve a pedir, y la clave de idempotencia hace que
     *                 Mercado Pago conteste el mismo en lugar de devolver la plata de nuevo
     *
     * @throws ExcepcionHttp    503 si Mercado Pago no responde o falla
     * @throws RuntimeException si lo rechaza, por ejemplo porque ya se devolvió
     */
    public function reembolsar(string $pagoId): ?string
    {
        $respuesta = $this->pedir('POST', '/v1/payments/' . rawurlencode($pagoId) . '/refunds', [], "reembolso-{$pagoId}");
        $id = is_array($respuesta) ? $respuesta['id'] ?? null : null;
        // Un reembolso sin id no se puede anotar, pero la plata ya volvió: no es un error que corte
        // la cancelación.
        if (!is_string($id) && !is_int($id)) {
            $this->log->warning('Mercado Pago devolvió el pago {pago} sin el id del reembolso.', ['pago' => $pagoId]);

            return null;
        }

        return (string) $id;
    }

    /**
     * @param array<string, mixed>|null $datos se mandan como JSON
     *
     * @return mixed el JSON de la respuesta ya decodificado, o null si no existe (404)
     *
     * @throws ExcepcionHttp    503 si no responde a tiempo o falla del lado de Mercado Pago
     * @throws RuntimeException si responde otro error: un token vencido da 401, por ejemplo
     */
    private function pedir(string $metodo, string $ruta, ?array $datos, ?string $claveDeIdempotencia = null): mixed
    {
        $credenciales = $this->credenciales() ?? throw new LogicException('Se llamó a Mercado Pago con el cobro apagado.');
        $cabeceras = [
            'Authorization: Bearer ' . $credenciales->accessToken,
            'Accept: application/json',
        ];
        if ($datos !== null) {
            $cabeceras[] = 'Content-Type: application/json';
        }
        // Si el pedido se repite (un reintento), Mercado Pago no hace dos veces lo mismo.
        if ($claveDeIdempotencia !== null) {
            $cabeceras[] = 'X-Idempotency-Key: ' . $claveDeIdempotencia;
        }

        $curl = curl_init($credenciales->api . $ruta);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $cabeceras,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => $this->esperaMaximaMs,
            CURLOPT_TIMEOUT_MS => $this->esperaMaximaMs,
        ]);
        if ($datos !== null) {
            // Vacío va como objeto ({}), no como lista ([]): un reembolso total no lleva campos.
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($datos === [] ? new \stdClass() : $datos, JSON_THROW_ON_ERROR));
        }
        $cuerpo = curl_exec($curl);
        $estado = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        // Nunca el token ni el cuerpo: el de un pago trae datos del comprador.
        $contexto = ['metodo' => $metodo, 'ruta' => $ruta];

        if (!is_string($cuerpo)) {
            $this->log->warning('Mercado Pago no respondió a {metodo} {ruta}: {motivo}', $contexto + ['motivo' => curl_error($curl)]);

            throw ExcepcionHttp::servicioNoDisponible();
        }
        if ($estado >= 500) {
            $this->log->warning('Mercado Pago respondió {estado} a {metodo} {ruta}.', $contexto + ['estado' => $estado]);

            throw ExcepcionHttp::servicioNoDisponible();
        }
        if ($estado === 404) {
            return null;
        }
        if ($estado < 200 || $estado >= 300) {
            throw new RuntimeException("Mercado Pago respondió {$estado} a {$metodo} {$ruta}"
                . ($estado === 401 ? ': revisar mercadopago.access_token.' : '.'));
        }

        try {
            return $cuerpo === '' ? null : json_decode($cuerpo, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException("Mercado Pago no respondió JSON a {$metodo} {$ruta}.", 0, $error);
        }
    }
}
