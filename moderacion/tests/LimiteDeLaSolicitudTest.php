<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use PHPUnit\Framework\TestCase;

/**
 * La solicitud de sala lleva la foto y dos PDF de hasta 5 MB cada uno (EF-07.4): el pedido entero
 * tiene que entrar en lo que admiten PHP y nginx. Se lee la configuración que usan los contenedores,
 * porque un límite corto no da error en las pruebas: el navegador ve la conexión cortada.
 */
final class LimiteDeLaSolicitudTest extends TestCase
{
    private const MB = 1024 * 1024;

    private static function raiz(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * Un tamaño como los escriben php.ini y nginx: 5M, 16m.
     */
    private static function bytes(string $valor): int
    {
        self::assertMatchesRegularExpression('/^\d+[mMkK]?$/', $valor);
        $numero = (int) $valor;

        return match (strtolower(substr($valor, -1))) {
            'm' => $numero * self::MB,
            'k' => $numero * 1024,
            default => $numero,
        };
    }

    private static function deIni(string $clave): int
    {
        $ini = parse_ini_file(self::raiz() . '/php/php.ini', false, INI_SCANNER_RAW);
        self::assertIsArray($ini);
        self::assertArrayHasKey($clave, $ini);

        return self::bytes((string) $ini[$clave]);
    }

    private static function sitio(): string
    {
        return (string) file_get_contents(self::raiz() . '/nginx/templates/sitio.inc.template');
    }

    public function testCadaArchivoSigueHastaCincoMegas(): void
    {
        self::assertSame(5 * self::MB, self::deIni('upload_max_filesize'));
    }

    public function testPhpAdmiteLaFotoYLosDosDocumentosEnUnPedido(): void
    {
        self::assertGreaterThan(3 * self::deIni('upload_max_filesize'), self::deIni('post_max_size'));
    }

    public function testNginxAdmiteElPedidoEnteroSoloEnLaSolicitud(): void
    {
        self::assertSame(1, preg_match('#location ~ \^/solicitar-sala/\?\$ \{(.*?)\n\}#s', self::sitio(), $solicitud));
        self::assertSame(1, preg_match('/client_max_body_size (\w+);/', $solicitud[1], $limite));
        self::assertGreaterThan(3 * self::deIni('upload_max_filesize'), self::bytes($limite[1]));
        self::assertLessThanOrEqual(self::deIni('post_max_size'), self::bytes($limite[1]));
    }

    /**
     * Las demás rutas reciben a lo sumo una imagen: un pedido grande lo frena nginx antes de que
     * llegue a PHP.
     */
    public function testElRestoDelSitioSigueEnSeisMegas(): void
    {
        self::assertSame(1, preg_match('/^client_max_body_size (\w+);/m', self::sitio(), $general));
        self::assertSame(6 * self::MB, self::bytes($general[1]));
        self::assertSame(2, substr_count(self::sitio(), 'client_max_body_size'), 'Sólo el general y el de la solicitud.');
    }
}
