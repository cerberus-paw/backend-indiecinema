<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use PHPUnit\Framework\TestCase;

final class AplicacionTest extends TestCase
{
    private function aplicacion(string $entorno = 'produccion'): Aplicacion
    {
        return new Aplicacion(__DIR__ . '/Dobles/subsistema', new Configuracion([
            'app' => ['subsistema' => 'prueba', 'prefijo' => '/cuenta', 'entorno' => $entorno],
        ]));
    }

    /**
     * @param array<string, string> $cabeceras
     */
    private function pedir(string $metodo, string $ruta, array $cabeceras = [], string $entorno = 'produccion'): Respuesta
    {
        return $this->aplicacion($entorno)->atender(new Peticion($metodo, $ruta, cabeceras: $cabeceras));
    }

    public function testDespachaAlControladorConSusDependencias(): void
    {
        $respuesta = $this->pedir('GET', '/');

        self::assertSame(200, $respuesta->estado());
        self::assertSame('hola', $respuesta->cuerpo());
    }

    public function testPasaLosParametrosDeLaRuta(): void
    {
        $respuesta = $this->pedir('GET', '/salas/42');

        self::assertSame('{"id":"42"}', $respuesta->cuerpo());
    }

    public function testUnaRutaInexistenteDa404ConLaPaginaDeError(): void
    {
        $respuesta = $this->pedir('GET', '/no-existe');

        self::assertSame(404, $respuesta->estado());
        self::assertStringContainsString('No encontramos lo que buscabas.', $respuesta->cuerpo());
    }

    public function testOtroMetodoDa405ConLosPermitidos(): void
    {
        $respuesta = $this->pedir('POST', '/');

        self::assertSame(405, $respuesta->estado());
        self::assertSame('GET', $respuesta->cabecera('Allow'));
    }

    public function testUnErrorEnProduccionNoMuestraElDetalle(): void
    {
        // El error tiene que quedar en el log aunque el usuario no vea el detalle.
        $this->expectErrorLog();

        $respuesta = $this->pedir('GET', '/falla');

        self::assertSame(500, $respuesta->estado());
        self::assertStringContainsString('Algo salió mal', $respuesta->cuerpo());
        self::assertStringNotContainsString('detalle interno', $respuesta->cuerpo());
    }

    public function testUnErrorEnDesarrolloMuestraElDetalle(): void
    {
        // El error tiene que quedar en el log aunque el usuario no vea el detalle.
        $this->expectErrorLog();

        $respuesta = $this->pedir('GET', '/falla', entorno: 'desarrollo');

        self::assertSame(500, $respuesta->estado());
        self::assertStringContainsString('detalle interno', $respuesta->cuerpo());
    }

    public function testQuienPideJsonRecibeElErrorEnJson(): void
    {
        // El error tiene que quedar en el log aunque el usuario no vea el detalle.
        $this->expectErrorLog();

        $respuesta = $this->pedir('GET', '/falla', ['Accept' => 'application/json']);

        self::assertSame(500, $respuesta->estado());
        self::assertSame('{"error":"Algo salió mal. Probá de nuevo en un rato."}', $respuesta->cuerpo());
    }

    public function testLasPlantillasEscapanElHtml(): void
    {
        $respuesta = $this->pedir('GET', '/plantilla');

        self::assertStringContainsString('&lt;script&gt;', $respuesta->cuerpo());
        self::assertStringNotContainsString('<script>', $respuesta->cuerpo());
    }

    public function testRedirigirAgregaElPrefijoDelSubsistema(): void
    {
        $respuesta = $this->pedir('GET', '/redirige');

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/cuenta/destino', $respuesta->cabecera('Location'));
    }
}
