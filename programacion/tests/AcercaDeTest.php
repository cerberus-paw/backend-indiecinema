<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use PHPUnit\Framework\TestCase;

/**
 * GET /acerca-de de punta a punta: la aplicación entera con las rutas de programación y las
 * plantillas del paquete front. Es estática, así que no necesita la base.
 *
 * Corre en desarrollo, con las variables estrictas de Twig: una que falte en la plantilla rompe
 * la prueba en vez de quedar vacía.
 */
final class AcercaDeTest extends TestCase
{
    use ConSaltoAlContenido;

    private const SECRETO = 'secreto-de-nginx';

    /**
     * @param array<string, string> $cabeceras
     */
    private function pedir(string $url, array $cabeceras = []): Respuesta
    {
        $configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion, new Log('programacion', fopen('php://memory', 'w')));

        return PaginasParaValidar::anotar('GET', $url, $aplicacion->atender(new Peticion('GET', $url, [], [], $cabeceras, [], [])));
    }

    /**
     * El encabezado y el pie de la página, sin el contenido.
     */
    private static function encabezadoYPie(string $cuerpo): string
    {
        preg_match('#<header class="encabezado.*?</header>#s', $cuerpo, $encabezado);
        preg_match('#<footer.*?</footer>#s', $cuerpo, $pie);

        return ($encabezado[0] ?? '') . ($pie[0] ?? '');
    }

    public function testCuentaQuienesSomosYComoFunciona(): void
    {
        $respuesta = $this->pedir('/acerca-de');

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('<h1 class="acerca-de-titulo">Acerca de IndieCinema</h1>', $cuerpo);
        self::assertStringContainsString('<title>Acerca de IndieCinema · IndieCinema</title>', $cuerpo);
        self::assertStringContainsString('<section id="quienes-somos"', $cuerpo);
        self::assertStringContainsString('<section id="como-funciona"', $cuerpo);
        self::assertStringContainsString('href="/como-funciona"', $cuerpo);
        self::assertStringContainsString('Grupo Cerberus', $cuerpo);
    }

    public function testLosEnlacesDelEncabezadoAbrenLaPagina(): void
    {
        $enLaPagina = $this->pedir('/acerca-de')->cuerpo();

        // «Acerca de» y «Cómo funciona», y los dos llevan a una ruta que existe.
        self::assertMatchesRegularExpression('#<a href="/acerca-de" class="nav-enlace[^"]*">Acerca de</a>#', $enLaPagina);
        self::assertStringContainsString('<a href="/como-funciona" class="nav-enlace">Cómo funciona</a>', $enLaPagina);
        // En la página, «Acerca de» queda marcado como activo.
        self::assertStringContainsString('<a href="/acerca-de" class="nav-enlace activo">Acerca de</a>', $enLaPagina);
        $encabezadoYPie = self::encabezadoYPie($enLaPagina);
        self::assertStringContainsString('href="/como-funciona"', $encabezadoYPie);

        $comoFunciona = $this->pedir('/como-funciona');
        self::assertSame(200, $comoFunciona->estado(), $comoFunciona->cuerpo());
        self::assertStringContainsString('<a href="/como-funciona" class="nav-enlace activo">Cómo funciona</a>', $comoFunciona->cuerpo());
    }

    public function testComoFuncionaExplicaElCircuitoYLosRoles(): void
    {
        $respuesta = $this->pedir('/como-funciona');

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('<h1 class="acerca-de-titulo">Cómo funciona IndieCinema</h1>', $cuerpo);
        self::assertStringContainsString('<title>Cómo funciona · IndieCinema</title>', $cuerpo);
        self::assertSame(6, substr_count($cuerpo, '<li class="acerca-de-paso">'));
        self::assertSame(4, substr_count($cuerpo, '<li class="acerca-de-rol">'));
        self::assertStringContainsString('Espectador', $cuerpo);
        self::assertStringContainsString('Organizador', $cuerpo);
        self::assertStringContainsString('Dueño de la obra', $cuerpo);
        self::assertStringContainsString('Moderador', $cuerpo);
        self::assertSaltaAlContenido($cuerpo);
    }

    public function testLosEncabezadosNoSaltanNiveles(): void
    {
        preg_match_all('#<h([1-6])[ >]#', $this->pedir('/acerca-de')->cuerpo(), $niveles);

        self::assertSame('1', $niveles[1][0] ?? null, 'La página no empieza por el h1.');
        $anterior = 0;
        foreach (array_map('intval', $niveles[1]) as $nivel) {
            self::assertLessThanOrEqual($anterior + 1, $nivel, 'Un encabezado salta un nivel.');
            $anterior = $nivel;
        }
    }

    public function testLoPrimeroQueTomaElFocoEsSaltarAlContenido(): void
    {
        self::assertSaltaAlContenido($this->pedir('/acerca-de')->cuerpo());
    }

    public function testSeVeConSesion(): void
    {
        $respuesta = $this->pedir('/acerca-de', ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'X-Rol' => 'espectador']);

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('Acerca de IndieCinema', $respuesta->cuerpo());
    }
}
