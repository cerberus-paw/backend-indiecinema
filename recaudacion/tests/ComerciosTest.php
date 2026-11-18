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
 * Los comercios asociados de punta a punta (EF-17.3 / IC-598): el administrador los da de alta,
 * los edita y los da de baja, y la página pública muestra sólo los activos, sin pedir sesión.
 */
final class ComerciosTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ADMINISTRADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private BaseDeDatos $base;

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
        ]);
    }

    /**
     * @param array<string, string> $cuerpo
     */
    private function pedir(string $metodo, string $ruta, ?string $rol = 'administrador', array $cuerpo = []): Respuesta
    {
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => self::ADMINISTRADORA,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Ada'),
        ];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('recaudacion', fopen('php://memory', 'w')));
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        return PaginasParaValidar::anotar($metodo, "/recaudacion{$ruta}", $aplicacion->atender(new Peticion(
            $metodo,
            (string) parse_url($ruta, PHP_URL_PATH),
            $consulta,
            $metodo === 'POST' ? ['_csrf' => self::CSRF, ...$cuerpo] : $cuerpo,
            $cabeceras,
            ['csrf' => self::CSRF],
        )));
    }

    /**
     * @param array<string, string> $cambios
     *
     * @return array<string, string>
     */
    private static function comercio(array $cambios = []): array
    {
        return [
            'nombre' => 'Bar La Estación',
            'beneficio' => '15 % de descuento antes o después de la función',
            'direccion' => 'San Martín 410',
            'localidad' => 'Luján',
            ...$cambios,
        ];
    }

    private function idDe(string $nombre): string
    {
        return (string) $this->base->valor('SELECT id FROM comercio WHERE nombre = ?', [$nombre]);
    }

    public function testLaPaginaPublicaSeVeSinSesionYAvisaSiNoHayComercios(): void
    {
        $respuesta = $this->pedir('GET', '/beneficios', rol: null);
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        PaginasParaValidar::guardar('beneficios-vacia', $respuesta->cuerpo());
        self::assertStringContainsString('Todavía no hay comercios asociados', $respuesta->cuerpo());
    }

    public function testElAdministradorDaDeAltaYLaPaginaPublicaLosAgrupaPorLocalidad(): void
    {
        self::assertSame(303, $this->pedir('POST', '/administracion/comercios', cuerpo: self::comercio())->estado());
        $this->pedir('POST', '/administracion/comercios', cuerpo: self::comercio(['nombre' => 'Pizzería El Galpón', 'localidad' => 'Mercedes']));

        $admin = $this->pedir('GET', '/administracion/comercios?guardado=1');
        self::assertSame(200, $admin->estado());
        PaginasParaValidar::guardar('administracion-comercios', $admin->cuerpo());
        self::assertStringContainsString('Guardamos el comercio', $admin->cuerpo());

        $publica = $this->pedir('GET', '/beneficios', rol: null)->cuerpo();
        PaginasParaValidar::guardar('beneficios', $publica);
        self::assertStringContainsString('Bar La Estación', $publica);
        self::assertStringContainsString('15 % de descuento', $publica);
        // Por localidad, en orden: Luján antes que Mercedes.
        self::assertLessThan(strpos($publica, 'Mercedes'), strpos($publica, 'Luján'));
    }

    public function testValidaLosCampos(): void
    {
        $respuesta = $this->pedir('POST', '/administracion/comercios', cuerpo: self::comercio(['nombre' => '  ', 'beneficio' => str_repeat('x', 301)]));
        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Escribí el nombre del comercio.', $respuesta->cuerpo());
        self::assertStringContainsString('Hasta 300 caracteres.', $respuesta->cuerpo());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM comercio'));
    }

    public function testEditarYDarDeBajaYReactivar(): void
    {
        $this->pedir('POST', '/administracion/comercios', cuerpo: self::comercio());
        $id = $this->idDe('Bar La Estación');

        $edicion = $this->pedir('GET', "/administracion/comercios/{$id}");
        self::assertSame(200, $edicion->estado());
        PaginasParaValidar::guardar('administracion-comercio', $edicion->cuerpo());
        $this->pedir('POST', "/administracion/comercios/{$id}", cuerpo: self::comercio(['beneficio' => '20 % los miércoles']));
        self::assertStringContainsString('20 % los miércoles', $this->pedir('GET', '/beneficios', rol: null)->cuerpo());

        $this->pedir('POST', "/administracion/comercios/{$id}/activo", cuerpo: ['activo' => '0']);
        self::assertStringNotContainsString('Bar La Estación', $this->pedir('GET', '/beneficios', rol: null)->cuerpo());
        self::assertStringContainsString('De baja', $this->pedir('GET', '/administracion/comercios')->cuerpo());

        $this->pedir('POST', "/administracion/comercios/{$id}/activo", cuerpo: ['activo' => '1']);
        self::assertStringContainsString('Bar La Estación', $this->pedir('GET', '/beneficios', rol: null)->cuerpo());
    }

    public function testSoloElAdministradorLosAdministra(): void
    {
        self::assertSame(403, $this->pedir('GET', '/administracion/comercios', rol: 'organizador')->estado());
        self::assertSame(403, $this->pedir('POST', '/administracion/comercios', rol: 'organizador', cuerpo: self::comercio())->estado());
        self::assertSame(404, $this->pedir('GET', '/administracion/comercios/no-existe')->estado());
    }
}
