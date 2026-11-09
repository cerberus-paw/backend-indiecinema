<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use SensitiveParameter;

/**
 * La firma de los avisos de Mercado Pago (X-01.4): la ruta del aviso es pública y sin sesión,
 * así que sólo se le cree si la firmó quien tiene la clave secreta del webhook.
 *
 * Mercado Pago manda la cabecera x-signature («ts=1704908010,v1=618c…») y x-request-id. v1 es el
 * HMAC-SHA256, con la clave, de un texto armado con el id del recurso de la dirección (data.id),
 * el x-request-id y el ts:
 *
 *   id:<data.id>;request-id:<x-request-id>;ts:<ts>;
 *
 * Si el aviso no trae alguno de los dos primeros, esa parte no va. Un data.id alfanumérico va en
 * minúsculas.
 *
 * La firma no frena que alguien repita un aviso verdadero: eso lo cubre que el aviso no se crea,
 * sino que se le pregunta a Mercado Pago por el pago, y que un pago no se procesa dos veces.
 */
final class FirmaDeMercadoPago
{
    /**
     * @param ?string $firma       la cabecera x-signature
     * @param ?string $idDelPedido la cabecera x-request-id
     * @param string  $recurso     el data.id de la dirección del aviso
     */
    public static function esValida(?string $firma, ?string $idDelPedido, string $recurso, #[SensitiveParameter] string $clave): bool
    {
        if ($firma === null || $clave === '') {
            return false;
        }
        $partes = self::partes($firma);
        $ts = $partes['ts'] ?? '';
        $v1 = $partes['v1'] ?? '';
        if ($ts === '' || preg_match('/^[0-9a-f]{64}$/', $v1) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', self::manifiesto($recurso, $idDelPedido, $ts), $clave), $v1);
    }

    /**
     * El texto que se firma. Público para que las pruebas firmen como Mercado Pago.
     */
    public static function manifiesto(string $recurso, ?string $idDelPedido, string $ts): string
    {
        $manifiesto = '';
        if ($recurso !== '') {
            $manifiesto .= 'id:' . (ctype_alnum($recurso) ? strtolower($recurso) : $recurso) . ';';
        }
        if ($idDelPedido !== null && $idDelPedido !== '') {
            $manifiesto .= "request-id:{$idDelPedido};";
        }

        return $manifiesto . "ts:{$ts};";
    }

    /**
     * «ts=1,v1=abc» → ['ts' => '1', 'v1' => 'abc'], sin espacios.
     *
     * @return array<string, string>
     */
    private static function partes(string $firma): array
    {
        $partes = [];
        foreach (explode(',', $firma) as $parte) {
            [$clave, $valor] = array_pad(explode('=', $parte, 2), 2, '');
            $partes[trim($clave)] = trim($valor);
        }

        return $partes;
    }
}
