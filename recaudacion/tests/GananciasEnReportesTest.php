<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use PHPUnit\Framework\TestCase;

/**
 * «¿Gana o pierde plata?» en los reportes (EF-22.1), de punta a punta: la aplicación con un MySQL
 * de verdad y los dobles de programación y funciones de ReportesTest. Las funciones del doble
 * todavía no se repartieron, así que se calculan con lo cobrado y la comisión.
 */
final class GananciasEnReportesTest extends TestCase
{
    use ConBaseDePruebas;
    use ConDoblesDeSubsistemas;

    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ADMINISTRADORA = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const GALPON = '10000000-0000-4000-8000-000000000001';
    private const NORTE = '10000000-0000-4000-8000-000000000002';
    private const NOVIEMBRE = '/reportes?desde=2026-11-01&hasta=2026-11-30&tema=plata';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private static string $programacion;
    private static string $funciones;

    private BaseDeDatos $base;

    public static function setUpBeforeClass(): void
    {
        self::$programacion = self::levantar('programacion.php');
        self::$funciones = self::levantar('funciones.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::apagarDobles();
    }

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas($this->configuracion());
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'recaudacion', 'prefijo' => '/recaudacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'subsistemas' => ['programacion' => self::$programacion, 'funciones' => self::$funciones],
            'interno' => ['token' => 'token-de-recaudacion'],
            'reportes' => ['costo_funcion' => 20000],
        ]);
    }

    /**
     * @param array<string, string> $cuerpo
     */
    private function pedir(string $ruta, string $usuario = self::ORGANIZADORA, string $rol = 'organizador', string $metodo = 'GET', array $cuerpo = []): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('recaudacion', fopen('php://memory', 'w')));
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        return PaginasParaValidar::anotar($metodo, $ruta, $aplicacion->atender(new Peticion($metodo, (string) parse_url($ruta, PHP_URL_PATH), $consulta, $cuerpo, [
            'X-Nginx-Secreto' => 'secreto-de-nginx',
            'X-Usuario-Id' => $usuario,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Olga Organizadora'),
        ], ['csrf' => self::CSRF], [])));
    }

    /**
     * @param array<string, string> $campos
     */
    private function cargarCosto(array $campos, string $usuario = self::ORGANIZADORA, string $rol = 'organizador'): Respuesta
    {
        return $this->pedir('/reportes/costos', $usuario, $rol, 'POST', $campos + ['_csrf' => self::CSRF]);
    }

    private static function texto(Respuesta $respuesta): string
    {
        return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($respuesta->cuerpo())));
    }

    public function testElOrganizadorVeSiSuSalaGanaOPierde(): void
    {
        $respuesta = $this->pedir(self::NOVIEMBRE);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $texto = self::texto($respuesta);
        self::assertStringContainsString('¿Gana o pierde plata?', $texto);
        self::assertMatchesRegularExpression('/El Galpón 4 funciones, 4 sin repartir todavía/', $texto);
        self::assertStringContainsString('$ 20.000 por función, de referencia', $texto);
        self::assertStringContainsString('Mercado Pago se queda el 7,61 %', $texto);
        // Sólo sus salas, y sin la plataforma: es del administrador.
        self::assertStringNotContainsString('Cineclub Norte', $texto);
        self::assertStringNotContainsString('Lo que le cuesta', $texto);
        self::assertMatchesRegularExpression('#class="reporte-resultado reporte-(gana|pierde)"#', $respuesta->cuerpo());
    }

    public function testElAdministradorVeTambienLaPlataforma(): void
    {
        $texto = self::texto($this->pedir(self::NOVIEMBRE, self::ADMINISTRADORA, 'administrador'));

        self::assertStringContainsString('Cineclub Norte', $texto);
        self::assertMatchesRegularExpression('/Plataforma [+−] \$ [\d.,]+ (gana|pierde) en 30 días/u', $texto);
        self::assertStringContainsString('Lo que le cuesta', $texto);
    }

    public function testElOrganizadorCargaElCostoDeSuSalaYCambiaElResultado(): void
    {
        $respuesta = $this->cargarCosto(['sala' => self::GALPON, 'costo' => '9.000.000', 'desde' => '2026-11-01', 'hasta' => '2026-11-30']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('/recaudacion/reportes?desde=2026-11-01&hasta=2026-11-30&tema=plata&costo_cargado=1#ganancias', $respuesta->cabecera('Location'));
        self::assertSame('9000000.00', (string) $this->base->valor('SELECT costo_funcion FROM costo_sala WHERE sala_id = ?', [self::GALPON]));

        $despues = $this->pedir(self::NOVIEMBRE . '&costo_cargado=1');
        self::assertStringContainsString('Guardaste el costo por función', self::texto($despues));
        // Con $ 9 millones por función, el Galpón pierde seguro.
        self::assertStringContainsString('$ 9.000.000 por función', self::texto($despues));
        self::assertStringContainsString('reporte-resultado reporte-pierde', $despues->cuerpo());
        self::assertStringNotContainsString('reporte-resultado reporte-gana', $despues->cuerpo());
    }

    public function testElOrganizadorNoCargaElCostoDeUnaSalaAjena(): void
    {
        self::assertSame(403, $this->cargarCosto(['sala' => self::NORTE, 'costo' => '1000'])->estado());
        self::assertNull($this->base->valor('SELECT costo_funcion FROM costo_sala WHERE sala_id = ?', [self::NORTE]));
    }

    public function testElAdministradorCargaElDeCualquierSala(): void
    {
        self::assertSame(303, $this->cargarCosto(['sala' => self::NORTE, 'costo' => '1000'], self::ADMINISTRADORA, 'administrador')->estado());
        self::assertSame('1000.00', (string) $this->base->valor('SELECT costo_funcion FROM costo_sala WHERE sala_id = ?', [self::NORTE]));
    }

    public function testUnCostoQueNoEsUnMontoVuelveConElError(): void
    {
        $respuesta = $this->cargarCosto(['sala' => self::GALPON, 'costo' => 'mucho']);

        self::assertSame(303, $respuesta->estado());
        self::assertStringContainsString('costo_error=', (string) $respuesta->cabecera('Location'));
        self::assertNull($this->base->valor('SELECT costo_funcion FROM costo_sala WHERE sala_id = ?', [self::GALPON]));
        self::assertStringContainsString(
            'Escribí el costo en pesos',
            self::texto($this->pedir(self::NOVIEMBRE . '&costo_error=' . rawurlencode('Escribí el costo en pesos, sin signos: por ejemplo, 45000.'))),
        );
        // Otro texto en la URL no se muestra.
        self::assertStringNotContainsString('Cualquier cosa', self::texto($this->pedir(self::NOVIEMBRE . '&costo_error=Cualquier+cosa')));
    }

    public function testSinElTokenCsrfNoSeGuarda(): void
    {
        $respuesta = $this->pedir('/reportes/costos', metodo: 'POST', cuerpo: ['sala' => self::GALPON, 'costo' => '1000', '_csrf' => str_repeat('d', 64)]);

        self::assertSame(403, $respuesta->estado());
    }

    /**
     * EF-22.14: con $ 60.000 por función el Galpón pierde y tiene su promoción de los miércoles;
     * con el de referencia ($ 20.000) gana holgado, y la promoción deja lugar a por qué no la tiene.
     */
    public function testALaSalaQueGanaHolgadoNoLeSugierePromocionesYDicePorQue(): void
    {
        $promociones = '/reportes?desde=2026-11-01&hasta=2026-11-30&tema=promociones';
        $this->cargarCosto(['sala' => self::GALPON, 'costo' => '60000']);
        $antes = self::texto($this->pedir($promociones));
        self::assertStringContainsString('Probá una promoción los miércoles', $antes);
        self::assertStringNotContainsString('No necesitan promoción', $antes);

        $this->base->ejecutar('DELETE FROM costo_sala');

        $despues = self::texto($this->pedir($promociones));
        self::assertStringNotContainsString('Probá una promoción los miércoles', $despues);
        self::assertMatchesRegularExpression('/No necesitan promoción El Galpón: ganó \+ \$ [\d.,]+ sobre \$ 80.000 de costo, un [\d.]+ % más de lo que le costó abrir\./u', $despues);
        self::assertStringContainsString('Ningún día quedó flojo en las salas que necesitan una promoción.', $despues);
    }
}
