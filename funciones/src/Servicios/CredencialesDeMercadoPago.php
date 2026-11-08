<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use IndieCinema\Nucleo\Configuracion;
use RuntimeException;
use SensitiveParameter;

/**
 * Lo que funciones necesita para cobrar con Checkout Pro (X-01): la sección [mercadopago] de su
 * config.ini. Es el único subsistema que la tiene: cada contenedor monta sólo su configuración,
 * así que el access token no llega a cuentas ni a programación.
 *
 * Mientras la sección no esté o diga activo = false, las funciones pagas se confirman sin cobrar,
 * como antes del extra: así el sitio sigue andando hasta que el VPS tenga sus credenciales.
 */
final readonly class CredencialesDeMercadoPago
{
    /** La API de Mercado Pago; en las pruebas, un doble local. */
    public const API = 'https://api.mercadopago.com';

    private const MINUTOS_POR_DEFECTO = 15;
    /** Un día: más que eso, una reserva sin pagar le quita el lugar a otro demasiado tiempo. */
    private const MINUTOS_MAXIMOS = 1440;

    /**
     * @param string $accessToken  el de la cuenta vendedora (APP_USR-…): la de prueba mientras
     *                             el sitio no cobre de verdad
     * @param string $claveWebhook la clave secreta con la que Mercado Pago firma los avisos
     */
    public function __construct(
        #[SensitiveParameter] public string $accessToken,
        #[SensitiveParameter] public string $claveWebhook,
        public int $minutosParaPagar = self::MINUTOS_POR_DEFECTO,
        public string $api = self::API,
    ) {
    }

    /**
     * Las credenciales, o null si el cobro está apagado.
     *
     * @throws RuntimeException si está activo y falta un valor, o el plazo no es un número de
     *                          minutos razonable: mejor que el subsistema no arranque a que una
     *                          reserva falle a mitad de camino
     */
    public static function desdeConfiguracion(Configuracion $configuracion): ?self
    {
        if ($configuracion->obtener('mercadopago.activo', false) !== true) {
            return null;
        }

        $minutos = $configuracion->obtener('mercadopago.minutos_para_pagar', self::MINUTOS_POR_DEFECTO);
        if (!is_int($minutos) || $minutos < 1 || $minutos > self::MINUTOS_MAXIMOS) {
            throw new RuntimeException('«mercadopago.minutos_para_pagar» tiene que ser un número de minutos entre 1 y ' . self::MINUTOS_MAXIMOS . '.');
        }
        $api = (string) $configuracion->obtener('mercadopago.api', self::API);
        if (!str_starts_with($api, 'https://') && !str_starts_with($api, 'http://')) {
            throw new RuntimeException('«mercadopago.api» tiene que ser una URL http(s).');
        }

        return new self(
            (string) $configuracion->requerir('mercadopago.access_token'),
            (string) $configuracion->requerir('mercadopago.clave_webhook'),
            $minutos,
            rtrim($api, '/'),
        );
    }
}
