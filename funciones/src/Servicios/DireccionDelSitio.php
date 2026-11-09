<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use IndieCinema\Nucleo\Configuracion;
use RuntimeException;

/**
 * La dirección pública del sitio ([sitio] url), para los enlaces que salen de funciones hacia
 * afuera: el del comprobante en el correo, y adónde vuelve y avisa Mercado Pago.
 */
final class DireccionDelSitio
{
    /**
     * @return string sin la barra final
     *
     * @throws RuntimeException si falta o no es http(s)
     */
    public static function de(Configuracion $configuracion): string
    {
        $url = rtrim((string) $configuracion->requerir('sitio.url'), '/');
        $esquema = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($esquema, ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
            throw new RuntimeException('«sitio.url» de config.ini tiene que ser una dirección http(s).');
        }

        return $url;
    }

    /**
     * La dirección de una página de funciones, con su prefijo: «https://…/funcion/reservas/…».
     *
     * @throws RuntimeException como de()
     */
    public static function deFunciones(Configuracion $configuracion, string $ruta): string
    {
        return self::de($configuracion) . rtrim((string) $configuracion->obtener('app.prefijo', ''), '/') . $ruta;
    }
}
