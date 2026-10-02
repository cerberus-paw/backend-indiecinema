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
    private const SECRETO = 'secreto-de-nginx';
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function aplicacion(string $entorno = 'produccion'): Aplicacion
    {
        return new Aplicacion(__DIR__ . '/Dobles/subsistema', new Configuracion([
            'app' => ['subsistema' => 'prueba', 'prefijo' => '/cuenta', 'entorno' => $entorno],
            'nginx' => ['secreto' => self::SECRETO],
        ]));
    }

    /**
     * @param array<string, string> $cabeceras
     * @param array<string, mixed>  $cuerpo
     * @param array<string, string> $cookies
     */
    private function pedir(
        string $metodo,
        string $ruta,
        array $cabeceras = [],
        string $entorno = 'produccion',
        array $cuerpo = [],
        array $cookies = [],
    ): Respuesta {
        return $this->aplicacion($entorno)->atender(new Peticion($metodo, $ruta, [], $cuerpo, $cabeceras, $cookies));
    }

    /**
     * Las cabeceras que agrega nginx para un usuario con sesión.
     *
     * @return array<string, string>
     */
    private static function sesion(string $rol, string $secreto = self::SECRETO): array
    {
        return ['X-Nginx-Secreto' => $secreto, 'X-Usuario-Id' => 'u-1', 'X-Rol' => $rol];
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

    public function testSinElSecretoDeNginxEnLaConfiguracionNoArranca(): void
    {
        $this->expectExceptionMessage('nginx.secreto');

        new Aplicacion(__DIR__ . '/Dobles/subsistema', new Configuracion([
            'app' => ['subsistema' => 'prueba', 'prefijo' => '/cuenta'],
        ]));
    }

    public function testUnaRutaConRolSinSesionDa401(): void
    {
        $respuesta = $this->pedir('GET', '/organizador');

        self::assertSame(401, $respuesta->estado());
        self::assertStringContainsString('Tenés que ingresar', $respuesta->cuerpo());
    }

    public function testUnaRutaDeOrganizadorRechazaAUnEspectador(): void
    {
        $respuesta = $this->pedir('GET', '/organizador', self::sesion('espectador'));

        self::assertSame(403, $respuesta->estado());
    }

    public function testUnaRutaDeOrganizadorDejaPasarAlOrganizadorYAlAdministrador(): void
    {
        self::assertSame(200, $this->pedir('GET', '/organizador', self::sesion('organizador'))->estado());
        self::assertSame(200, $this->pedir('GET', '/organizador', self::sesion('administrador'))->estado());
    }

    public function testLasCabecerasDeIdentidadSinElSecretoNoDanAcceso(): void
    {
        // Queda en el log: es alguien de la red interna haciéndose pasar por otro.
        $this->expectErrorLog();

        $respuesta = $this->pedir('GET', '/organizador', self::sesion('administrador', 'otro-secreto'));

        self::assertSame(401, $respuesta->estado());
    }

    public function testLaPrimeraVisitaRecibeLaCookieCsrf(): void
    {
        $cookies = $this->pedir('GET', '/')->cookies();

        self::assertCount(1, $cookies);
        self::assertSame('csrf', $cookies[0]['nombre']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cookies[0]['valor']);
        self::assertSame(
            ['path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax'],
            $cookies[0]['opciones'],
        );
    }

    public function testConUnaCookieCsrfValidaNoLaVuelveAMandar(): void
    {
        $respuesta = $this->pedir('GET', '/', cookies: ['csrf' => self::TOKEN]);

        self::assertSame([], $respuesta->cookies());
    }

    public function testUnaCookieCsrfMalFormadaSeReemplaza(): void
    {
        $cookies = $this->pedir('GET', '/', cookies: ['csrf' => 'cualquier-cosa'])->cookies();

        self::assertCount(1, $cookies);
        self::assertNotSame('cualquier-cosa', $cookies[0]['valor']);
    }

    public function testLaPaginaDeErrorTambienDejaLaCookieCsrf(): void
    {
        $respuesta = $this->pedir('GET', '/no-existe');

        self::assertSame(404, $respuesta->estado());
        self::assertCount(1, $respuesta->cookies());
    }

    public function testUnPostSinTokenCsrfSeRechaza(): void
    {
        $respuesta = $this->pedir('POST', '/formulario', cookies: ['csrf' => self::TOKEN]);

        self::assertSame(403, $respuesta->estado());
        self::assertStringContainsString('La página venció', $respuesta->cuerpo());
    }

    public function testUnPostConOtroTokenCsrfSeRechaza(): void
    {
        $otro = str_repeat('b', 64);
        $respuesta = $this->pedir('POST', '/formulario', cuerpo: ['_csrf' => $otro], cookies: ['csrf' => self::TOKEN]);

        self::assertSame(403, $respuesta->estado());
    }

    public function testUnPostSinLaCookieCsrfSeRechazaAunqueTraigaToken(): void
    {
        $respuesta = $this->pedir('POST', '/formulario', cuerpo: ['_csrf' => self::TOKEN]);

        self::assertSame(403, $respuesta->estado());
    }

    public function testUnPostConElTokenDeLaCookiePasa(): void
    {
        $respuesta = $this->pedir('POST', '/formulario', cuerpo: ['_csrf' => self::TOKEN], cookies: ['csrf' => self::TOKEN]);

        self::assertSame(200, $respuesta->estado());
    }

    public function testElJavaScriptPuedeMandarElTokenEnUnaCabecera(): void
    {
        $respuesta = $this->pedir('POST', '/formulario', ['X-CSRF-Token' => self::TOKEN], cookies: ['csrf' => self::TOKEN]);

        self::assertSame(200, $respuesta->estado());
    }

    public function testUnaRutaInternaNoUsaTokenCsrf(): void
    {
        $respuesta = $this->pedir('POST', '/interno/prueba');

        self::assertSame(200, $respuesta->estado());
        self::assertSame([], $respuesta->cookies());
    }
}
