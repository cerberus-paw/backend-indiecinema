<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Nucleo\Pruebas\Csrf\CompruebaElCsrfDeTodaRuta;
use PHPUnit\Framework\TestCase;

/**
 * Que ninguna ruta de cuentas que cambie datos pase sin el token CSRF (E4-11.1). Lo que se prueba
 * está en el trait, que recorre las rutas del subsistema: acá sólo se dice cuáles son y con qué
 * configuración se arma la aplicación.
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
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'nginx' => ['secreto' => self::SECRETO_PARA_EL_CSRF],
        ];
    }
}
