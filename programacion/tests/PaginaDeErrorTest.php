<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La página de error del paquete front, de punta a punta: desde un error se vuelve a la portada,
 * y cada botón lleva a una ruta que existe. Con un MySQL de verdad, porque sigue los enlaces
 * hasta la portada y la cartelera.
 */
final class PaginaDeErrorTest extends TestCase
{
    use ConBaseDePruebas;

    private Configuracion $configuracion;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
        ]);
        self::baseConTablasNuevas($this->configuracion);
    }

    private function pedir(string $url): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', $url, [], [], [], [], []));
    }

    /**
     * Los botones de la página de error, en orden: [url, texto].
     *
     * @return list<array{string, string}>
     */
    private static function botones(Respuesta $respuesta): array
    {
        preg_match('#<div class="error-acciones">.*?</div>#s', $respuesta->cuerpo(), $acciones);
        preg_match_all('#<a href="([^"]+)" class="boton[^"]*">([^<]+)</a>#', $acciones[0] ?? '', $botones, PREG_SET_ORDER);

        return array_map(static fn (array $boton): array => [$boton[1], $boton[2]], $botones);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paginasQueNoExisten(): iterable
    {
        yield 'una ruta que no existe' => ['/no-existe'];
        yield 'una función que no existe' => ['/cartelera/' . Uuid::nuevo()];
        yield 'una página de la cartelera que no existe' => ['/cartelera?pagina=999'];
    }

    #[DataProvider('paginasQueNoExisten')]
    public function testDesdeUn404SeVuelveALaPortada(string $url): void
    {
        $error = $this->pedir($url);

        self::assertSame(404, $error->estado());
        [$principal, $secundario] = self::botones($error) + [null, null];
        self::assertSame(['/', 'Volver al inicio'], $principal);
        self::assertSame(['/cartelera', 'Ver la cartelera'], $secundario);
        // Y los dos llevan a una pantalla que existe: la portada y la cartelera.
        self::assertSame(200, $this->pedir('/')->estado());
        self::assertStringContainsString('class="portada-presentacion"', $this->pedir('/')->cuerpo());
        self::assertSame(200, $this->pedir('/cartelera')->estado());
    }

    public function testSinSesionElBotonPrincipalEsIngresarYElOtroVuelveALaPortada(): void
    {
        $error = $this->pedir('/organizador/salas');

        self::assertSame(401, $error->estado());
        self::assertSame([['/cuenta/ingresar', 'Ingresar'], ['/', 'Volver al inicio']], self::botones($error));
    }
}
