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
 * «Mis puntos» y el canje (EF-17.1 / IC-596), con las asistencias de un funciones de mentira
 * (Dobles/funciones.php): Eva fue a doce funciones y Ema a tres.
 */
final class PuntosTest extends TestCase
{
    use ConBaseDePruebas;
    use ConDoblesDeSubsistemas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const EVA = '77777777-7777-4777-8777-777777777777';
    private const EMA = '88888888-8888-4888-8888-888888888888';

    private static string $funciones;

    private BaseDeDatos $base;

    public static function setUpBeforeClass(): void
    {
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
            'nginx' => ['secreto' => self::SECRETO],
            'interno' => ['token' => 'token-de-recaudacion'],
            'subsistemas' => ['funciones' => self::$funciones],
            'llamadores' => ['funciones' => hash('sha256', 'token-de-funciones')],
        ]);
    }

    private function pedir(string $metodo, string $ruta, string $usuario = self::EVA, bool $interno = false): Respuesta
    {
        $cabeceras = $interno
            ? ['Authorization' => 'Bearer token-de-funciones', 'X-Llamador' => 'funciones']
            : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => 'espectador', 'X-Usuario-Nombre' => 'Eva'];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('recaudacion', fopen('php://memory', 'w')));
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        return PaginasParaValidar::anotar($metodo, "/recaudacion{$ruta}", $aplicacion->atender(new Peticion(
            $metodo,
            (string) parse_url($ruta, PHP_URL_PATH),
            $consulta,
            $metodo === 'POST' ? ['_csrf' => self::CSRF] : [],
            $cabeceras,
            ['csrf' => self::CSRF],
        )));
    }

    public function testElSaldoSonDiezPorAsistencia(): void
    {
        $respuesta = $this->pedir('GET', '/puntos');
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        PaginasParaValidar::guardar('mis-puntos', $respuesta->cuerpo());
        self::assertStringContainsString('120 <span>puntos</span>', $respuesta->cuerpo());
        self::assertStringContainsString('Asististe a una función (reserva ASIS0012)', $respuesta->cuerpo());
    }

    public function testCanjeaUnCuponQueRestaCienPuntos(): void
    {
        $canje = $this->pedir('POST', '/puntos/canjear');
        self::assertSame(303, $canje->estado(), $canje->cuerpo());
        $codigo = (string) $this->base->valor('SELECT codigo FROM cupon WHERE usuario_id = ?', [self::EVA]);
        self::assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $codigo);
        self::assertSame("/recaudacion/puntos?canjeado={$codigo}", $canje->cabecera('Location'));

        $pagina = $this->pedir('GET', "/puntos?canjeado={$codigo}")->cuerpo();
        self::assertStringContainsString("Tu código es {$codigo}", $pagina);
        self::assertStringContainsString('20 <span>puntos</span>', $pagina);
        self::assertStringContainsString('Disponible', $pagina);

        // Con 20 no le alcanza para otro.
        $otro = $this->pedir('POST', '/puntos/canjear');
        self::assertSame(422, $otro->estado());
        self::assertStringContainsString('Todavía no te alcanzan los puntos', $otro->cuerpo());
        self::assertSame(1, (int) $this->base->valor('SELECT COUNT(*) FROM cupon'));
    }

    public function testSinPuntosSuficientesNoCanjea(): void
    {
        self::assertSame(422, $this->pedir('POST', '/puntos/canjear', self::EMA)->estado());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM cupon'));
    }

    public function testMuestraElCuponUsadoSegunFunciones(): void
    {
        $this->base->ejecutar("INSERT INTO cupon (codigo, usuario_id, porcentaje, puntos, creado_en, vence_en) VALUES ('USADO234', ?, 20, 100, NOW(), NOW() + INTERVAL 60 DAY)", [self::EVA]);

        $pagina = $this->pedir('GET', '/puntos')->cuerpo();
        self::assertStringContainsString('USADO234', $pagina);
        self::assertStringContainsString('Usado', $pagina);
    }

    public function testFuncionesConsultaElCupon(): void
    {
        $this->base->ejecutar("INSERT INTO cupon (codigo, usuario_id, porcentaje, puntos, creado_en, vence_en) VALUES ('K7M2Q9XR', ?, 20, 100, '2026-11-18 12:00:00', '2027-01-17 12:00:00')", [self::EVA]);

        $respuesta = $this->pedir('GET', '/interno/cupones/K7M2Q9XR', interno: true);
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $datos = json_decode($respuesta->cuerpo(), true);
        self::assertSame(self::EVA, $datos['usuario_id']);
        self::assertSame(20, $datos['porcentaje']);
        self::assertSame(404, $this->pedir('GET', '/interno/cupones/NOEXISTE', interno: true)->estado());
    }
}
