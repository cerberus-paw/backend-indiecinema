<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use IndieCinema\Funciones\Servicios\FirmaDeMercadoPago;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La firma de los avisos de Mercado Pago (X-01.4): sólo pasa la que se hizo con la clave secreta,
 * sobre este mismo pago y este mismo pedido.
 */
final class FirmaDeMercadoPagoTest extends TestCase
{
    private const CLAVE = 'clave-secreta-de-los-avisos';
    private const PAGO = '123456789';
    private const PEDIDO = 'bb56a2f1-6aae-46ac-982e-9dcd3581d08e';
    private const TS = '1704908010';

    /**
     * Como la arma Mercado Pago.
     */
    private static function firma(string $recurso = self::PAGO, ?string $pedido = self::PEDIDO, string $clave = self::CLAVE, string $ts = self::TS): string
    {
        return 'ts=' . $ts . ',v1=' . hash_hmac('sha256', "id:{$recurso};" . ($pedido === null ? '' : "request-id:{$pedido};") . "ts:{$ts};", $clave);
    }

    public function testElManifiestoEsElDeLaDocumentacion(): void
    {
        self::assertSame('id:123456789;request-id:' . self::PEDIDO . ';ts:1704908010;', FirmaDeMercadoPago::manifiesto(self::PAGO, self::PEDIDO, self::TS));
        // Sin x-request-id, esa parte no va; un id alfanumérico va en minúsculas.
        self::assertSame('id:abc123;ts:1;', FirmaDeMercadoPago::manifiesto('ABC123', null, '1'));
    }

    public function testPasaLaFirmaHechaConLaClave(): void
    {
        self::assertTrue(FirmaDeMercadoPago::esValida(self::firma(), self::PEDIDO, self::PAGO, self::CLAVE));
    }

    public function testPasaConEspaciosEntreLasPartes(): void
    {
        $firma = str_replace(',', ', ', self::firma());

        self::assertTrue(FirmaDeMercadoPago::esValida($firma, self::PEDIDO, self::PAGO, self::CLAVE));
    }

    public function testPasaSinXRequestIdSiSeFirmoSinEl(): void
    {
        self::assertTrue(FirmaDeMercadoPago::esValida(self::firma(pedido: null), null, self::PAGO, self::CLAVE));
    }

    /**
     * @return iterable<string, array{?string, ?string, string, string}>
     */
    public static function firmasQueNoPasan(): iterable
    {
        yield 'sin firma' => [null, self::PEDIDO, self::PAGO, self::CLAVE];
        yield 'vacía' => ['', self::PEDIDO, self::PAGO, self::CLAVE];
        yield 'con otra clave' => [self::firma(clave: 'otra'), self::PEDIDO, self::PAGO, self::CLAVE];
        // La de un aviso verdadero no sirve para otro pago, ni para otro pedido.
        yield 'de otro pago' => [self::firma(), self::PEDIDO, '987654321', self::CLAVE];
        yield 'de otro pedido' => [self::firma(), 'otro-pedido', self::PAGO, self::CLAVE];
        yield 'sin el ts' => [preg_replace('/^ts=\d+,/', '', self::firma()), self::PEDIDO, self::PAGO, self::CLAVE];
        yield 'con otro ts' => [str_replace('ts=' . self::TS, 'ts=1704908011', self::firma()), self::PEDIDO, self::PAGO, self::CLAVE];
        yield 'sin v1' => ['ts=' . self::TS, self::PEDIDO, self::PAGO, self::CLAVE];
        yield 'con un v1 que no es hexadecimal' => ['ts=' . self::TS . ',v1=zz', self::PEDIDO, self::PAGO, self::CLAVE];
        // Con la clave sin configurar, ni siquiera la firma de una clave vacía.
        yield 'con la clave vacía' => [self::firma(clave: ''), self::PEDIDO, self::PAGO, ''];
    }

    #[DataProvider('firmasQueNoPasan')]
    public function testNoPasa(?string $firma, ?string $pedido, string $recurso, string $clave): void
    {
        self::assertFalse(FirmaDeMercadoPago::esValida($firma, $pedido, $recurso, $clave));
    }
}
