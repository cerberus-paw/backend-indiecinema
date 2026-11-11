<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recaudación arranca con el núcleo, sus rutas y el paquete front, con la configuración del
 * ejemplo: el mismo archivo que se copia a config.ini (EF-02.1). Todavía no tiene pantallas, así
 * que alcanza con la API interna y la página de error, que además es la que revisa el validador
 * del W3C del CI: sin ninguna página guardada, ese paso falla.
 */
final class ArranqueTest extends TestCase
{
    private const TOKEN_DE_EJEMPLO = 'cambiar';
    private const SECRETO_DE_EJEMPLO = 'cambiar';

    /**
     * @param array<string, string> $cabeceras
     */
    private function pedir(string $ruta, array $cabeceras = []): Respuesta
    {
        $configuracion = Configuracion::desdeArchivo(dirname(__DIR__) . '/config/config.ejemplo.ini');
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion, new Log('recaudacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', $ruta, [], [], $cabeceras, [], []));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function llamadores(): iterable
    {
        yield 'cuentas' => ['cuentas'];
        yield 'programación' => ['programacion'];
        yield 'funciones' => ['funciones'];
        yield 'moderación' => ['moderacion'];
    }

    #[DataProvider('llamadores')]
    public function testRespondeAlEstadoInternoConElTokenDeCadaSubsistema(string $llamador): void
    {
        $respuesta = $this->pedir('/interno/estado', [
            'X-Llamador' => $llamador,
            'Authorization' => 'Bearer ' . self::TOKEN_DE_EJEMPLO,
        ]);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(['subsistema' => 'recaudacion', 'llamador' => $llamador], json_decode($respuesta->cuerpo(), true));
    }

    public function testSinTokenOConUnLlamadorQueNoEstaNoResponde(): void
    {
        self::assertSame(403, $this->pedir('/interno/estado')->estado());
        self::assertSame(403, $this->pedir('/interno/estado', [
            'X-Llamador' => 'nginx',
            'Authorization' => 'Bearer ' . self::TOKEN_DE_EJEMPLO,
        ])->estado());
        self::assertSame(403, $this->pedir('/interno/estado', [
            'X-Llamador' => 'funciones',
            'Authorization' => 'Bearer otro-token',
        ])->estado());
    }

    /**
     * Sin pantallas todavía: la portada del subsistema es la página de error del sitio, con el
     * encabezado y el pie del paquete front, y con la sesión que verificó nginx.
     */
    public function testSinPantallasRespondeLaPaginaDeErrorDelSitioConLaSesion(): void
    {
        $respuesta = $this->pedir('/', [
            'X-Nginx-Secreto' => self::SECRETO_DE_EJEMPLO,
            'X-Usuario-Id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'X-Rol' => 'organizador',
            'X-Usuario-Nombre' => rawurlencode('Olga Organizadora'),
        ]);

        self::assertSame(404, $respuesta->estado());
        self::assertStringContainsString('<header class="encabezado', $respuesta->cuerpo());
        self::assertStringContainsString('<footer', $respuesta->cuerpo());
        self::assertStringContainsString('Olga Organizadora', $respuesta->cuerpo());
        // anotar() guarda sólo las de 200 y 422: ésta va a mano, para que el validador del CI
        // tenga una página de recaudación mientras no haya pantallas (EF-06 las suma).
        PaginasParaValidar::guardar('recaudacion-error-404', $respuesta->cuerpo());
    }
}
