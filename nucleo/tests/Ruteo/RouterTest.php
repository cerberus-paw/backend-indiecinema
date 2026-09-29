<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Ruteo;

use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Pruebas\Dobles\ControladorDePrueba;
use IndieCinema\Nucleo\Ruteo\Router;
use IndieCinema\Nucleo\Seguridad\Rol;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/', [ControladorDePrueba::class, 'hola']);
        $this->router->get('/salas/{id}', [ControladorDePrueba::class, 'sala'], Rol::Organizador);
        $this->router->post('/salas', [ControladorDePrueba::class, 'hola'], Rol::Organizador);
    }

    public function testResuelveLaRaiz(): void
    {
        [$ruta, $parametros] = $this->router->resolver('GET', '/');

        self::assertSame('/', $ruta->patron);
        self::assertSame([], $parametros);
    }

    public function testDevuelveLosParametrosYElRolMinimo(): void
    {
        [$ruta, $parametros] = $this->router->resolver('GET', '/salas/7');

        self::assertSame(['id' => '7'], $parametros);
        self::assertSame(Rol::Organizador, $ruta->rolMinimo);
    }

    public function testHeadLoAtiendeLaRutaDelGet(): void
    {
        [$ruta] = $this->router->resolver('HEAD', '/');

        self::assertSame('GET', $ruta->metodo);
    }

    public function testUnSegmentoDeMasNoCoincide(): void
    {
        $this->expectExceptionObject(ExcepcionHttp::noEncontrada());

        $this->router->resolver('GET', '/salas/7/extra');
    }

    public function testConOtroMetodoDa405YDiceCualesAcepta(): void
    {
        try {
            $this->router->resolver('DELETE', '/salas');
            self::fail('Tenía que dar 405.');
        } catch (ExcepcionHttp $error) {
            self::assertSame(405, $error->estado);
            self::assertSame(['Allow' => 'POST'], $error->cabeceras);
        }
    }

    public function testUnaRutaInternaNoSePuedeDeclararSinLlamadores(): void
    {
        $this->expectException(LogicException::class);

        $this->router->get('/interno/sesion', [ControladorDePrueba::class, 'hola']);
    }

    public function testInternaExigeElPrefijoInterno(): void
    {
        $this->expectException(LogicException::class);

        $this->router->interna('GET', '/sesion', [ControladorDePrueba::class, 'hola'], ['nginx']);
    }

    public function testInternaGuardaSusLlamadores(): void
    {
        $this->router->interna('get', '/interno/sesion', [ControladorDePrueba::class, 'hola'], ['nginx']);

        [$ruta] = $this->router->resolver('GET', '/interno/sesion');

        self::assertTrue($ruta->esInterna());
        self::assertSame(['nginx'], $ruta->llamadores);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rutasSinNormalizar(): iterable
    {
        yield 'barra final' => ['/salas/', '/salas'];
        yield 'vacía' => ['', '/'];
        yield 'sólo barras' => ['//', '/'];
        yield 'codificada' => ['/salas/caf%C3%A9', '/salas/café'];
    }

    #[DataProvider('rutasSinNormalizar')]
    public function testNormalizaLaRuta(string $cruda, string $normalizada): void
    {
        self::assertSame($normalizada, Peticion::normalizarRuta($cruda));
    }
}
