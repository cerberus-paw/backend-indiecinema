<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use IndieCinema\Funciones\Servicios\CredencialesDeMercadoPago;
use IndieCinema\Nucleo\Configuracion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * La sección [mercadopago] de config.ini (X-01.1): apagada no pide nada, y prendida no deja
 * arrancar con un valor que falta.
 */
final class CredencialesDeMercadoPagoTest extends TestCase
{
    /**
     * @param array<string, mixed> $mercadopago
     */
    private static function credenciales(array $mercadopago): ?CredencialesDeMercadoPago
    {
        return CredencialesDeMercadoPago::desdeConfiguracion(new Configuracion(['mercadopago' => $mercadopago]));
    }

    public function testSinLaSeccionElCobroEstaApagado(): void
    {
        self::assertNull(CredencialesDeMercadoPago::desdeConfiguracion(new Configuracion([])));
    }

    public function testConActivoEnFalseNoPideLasCredenciales(): void
    {
        self::assertNull(self::credenciales(['activo' => false]));
    }

    /**
     * Sólo true lo prende: un «1» o un «si» escrito como texto no.
     */
    public function testUnActivoQueNoEsTrueLoDejaApagado(): void
    {
        self::assertNull(self::credenciales(['activo' => 'si', 'access_token' => 'TEST-1', 'clave_webhook' => 'clave']));
    }

    public function testPrendidoLeeLasCredencialesYElPlazo(): void
    {
        $credenciales = self::credenciales([
            'activo' => true,
            'access_token' => 'TEST-123',
            'clave_webhook' => 'clave-secreta',
            'minutos_para_pagar' => 20,
        ]);

        self::assertNotNull($credenciales);
        self::assertSame('TEST-123', $credenciales->accessToken);
        self::assertSame('clave-secreta', $credenciales->claveWebhook);
        self::assertSame(20, $credenciales->minutosParaPagar);
        self::assertSame(CredencialesDeMercadoPago::API, $credenciales->api);
    }

    public function testSinPlazoSonQuinceMinutos(): void
    {
        $credenciales = self::credenciales(['activo' => true, 'access_token' => 'APP_USR-1', 'clave_webhook' => 'clave']);

        self::assertNotNull($credenciales);
        self::assertSame(15, $credenciales->minutosParaPagar);
    }

    public function testLaApiSePuedeCambiarParaLasPruebasSinLaBarraFinal(): void
    {
        $credenciales = self::credenciales(['activo' => true, 'access_token' => 'TEST-1', 'clave_webhook' => 'clave', 'api' => 'http://127.0.0.1:9000/']);

        self::assertSame('http://127.0.0.1:9000', $credenciales?->api);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function configuracionesInvalidas(): iterable
    {
        yield 'sin access token' => [['activo' => true, 'clave_webhook' => 'clave'], 'mercadopago.access_token'];
        yield 'con el access token vacío' => [['activo' => true, 'access_token' => '', 'clave_webhook' => 'clave'], 'mercadopago.access_token'];
        yield 'sin la clave del webhook' => [['activo' => true, 'access_token' => 'TEST-1'], 'mercadopago.clave_webhook'];
        yield 'con el plazo en cero' => [['activo' => true, 'access_token' => 'TEST-1', 'clave_webhook' => 'clave', 'minutos_para_pagar' => 0], 'minutos_para_pagar'];
        yield 'con más de un día' => [['activo' => true, 'access_token' => 'TEST-1', 'clave_webhook' => 'clave', 'minutos_para_pagar' => 1441], 'minutos_para_pagar'];
        yield 'con el plazo como texto' => [['activo' => true, 'access_token' => 'TEST-1', 'clave_webhook' => 'clave', 'minutos_para_pagar' => 'quince'], 'minutos_para_pagar'];
        yield 'con una API que no es http' => [['activo' => true, 'access_token' => 'TEST-1', 'clave_webhook' => 'clave', 'api' => 'ftp://mp'], 'mercadopago.api'];
    }

    /**
     * @param array<string, mixed> $mercadopago
     */
    #[DataProvider('configuracionesInvalidas')]
    public function testPrendidoNoArrancaConUnValorQueFalta(array $mercadopago, string $clave): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($clave);

        self::credenciales($mercadopago);
    }

    /**
     * El ejemplo que se copia a config.ini trae la sección apagada, con «cambiar» en los secretos:
     * en producción el subsistema no arranca hasta que se cambian.
     */
    public function testElEjemploTraeLaSeccionApagadaYConLosSecretosPorCambiar(): void
    {
        $ejemplo = Configuracion::desdeArchivo(dirname(__DIR__, 2) . '/config/config.ejemplo.ini');

        self::assertNull(CredencialesDeMercadoPago::desdeConfiguracion($ejemplo));
        self::assertSame(Configuracion::VALOR_DE_EJEMPLO, $ejemplo->obtener('mercadopago.access_token'));
        self::assertSame(Configuracion::VALOR_DE_EJEMPLO, $ejemplo->obtener('mercadopago.clave_webhook'));
        self::assertSame(15, $ejemplo->obtener('mercadopago.minutos_para_pagar'));
    }
}
