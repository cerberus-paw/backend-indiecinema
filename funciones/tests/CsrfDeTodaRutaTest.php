<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Nucleo\Pruebas\Csrf\CompruebaElCsrfDeTodaRuta;
use PHPUnit\Framework\TestCase;

/**
 * Que ninguna ruta de funciones que cambie datos pase sin el token CSRF (E4-11.1). Lo que se
 * prueba está en el trait, que recorre las rutas del subsistema: acá sólo se dice cuáles son y
 * con qué configuración se arma la aplicación.
 *
 * La configuración lleva los otros subsistemas y el correo con valores de mentira: el token se
 * revisa antes de llegar al controlador, así que nada de eso se usa, pero la aplicación se arma
 * igual que en las otras pruebas.
 */
final class CsrfDeTodaRutaTest extends TestCase
{
    use CompruebaElCsrfDeTodaRuta;

    protected static function raizDelSubsistema(): string
    {
        return dirname(__DIR__);
    }

    protected static function configuracionDelSubsistema(): array
    {
        return [
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'nginx' => ['secreto' => self::SECRETO_PARA_EL_CSRF],
            'subsistemas' => ['programacion' => 'http://programacion.invalido', 'cuentas' => 'http://cuentas.invalido'],
            'interno' => ['token' => 'token-de-funciones'],
            'correo' => ['servidor' => 'smtp://localhost:1', 'remitente' => 'no-responder@indiecinema.test'],
            'sitio' => ['url' => 'http://localhost:8080'],
        ];
    }
}
