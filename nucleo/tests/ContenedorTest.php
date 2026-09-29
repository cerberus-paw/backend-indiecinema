<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Contenedor;
use IndieCinema\Nucleo\Pruebas\Dobles\ControladorDePrueba;
use IndieCinema\Nucleo\Pruebas\Dobles\ServicioDePrueba;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ContenedorTest extends TestCase
{
    public function testArmaUnaClaseConSusDependencias(): void
    {
        $contenedor = new Contenedor();

        $controlador = $contenedor->obtener(ControladorDePrueba::class);

        self::assertInstanceOf(ControladorDePrueba::class, $controlador);
    }

    public function testDevuelveSiempreLaMismaInstancia(): void
    {
        $contenedor = new Contenedor();

        self::assertSame($contenedor->obtener(ServicioDePrueba::class), $contenedor->obtener(ServicioDePrueba::class));
    }

    public function testLaFabricaSeUsaRecienCuandoSePide(): void
    {
        $contenedor = new Contenedor();
        $llamadas = 0;
        $contenedor->fabrica(ServicioDePrueba::class, function () use (&$llamadas): ServicioDePrueba {
            $llamadas++;

            return new ServicioDePrueba();
        });

        self::assertSame(0, $llamadas);
        $contenedor->obtener(ServicioDePrueba::class);
        $contenedor->obtener(ServicioDePrueba::class);
        self::assertSame(1, $llamadas);
    }

    public function testNoAdivinaLosParametrosQueNoSonClases(): void
    {
        $this->expectException(LogicException::class);

        // Configuracion recibe un arreglo: hay que registrarla, no se puede construir sola.
        (new Contenedor())->obtener(Configuracion::class);
    }
}
