<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\Encabezados;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El orden de los encabezados de cada página de moderación (E4-10.2): un único h1 y ningún salto
 * de nivel, como los recorre un lector de pantalla. Las páginas de la solicitud de sala se mudaron
 * de programación (EF-07.2), con su prueba. Una página nueva entra con una línea en paginas().
 */
final class OrdenDeEncabezadosTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const USUARIO = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const CSRF = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private Configuracion $configuracion;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'moderacion', 'prefijo' => '/moderacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => sys_get_temp_dir() . '/sin-uso'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            // Las páginas no llaman a nadie ni mandan correos: en el puerto 1 no escucha nadie.
            'subsistemas' => ['cuentas' => 'http://127.0.0.1:1', 'programacion' => 'http://127.0.0.1:1'],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => 'smtp://127.0.0.1:1', 'remitente' => 'no-responder@indiecinema.test'],
            'solicitudes' => ['destino' => 'equipo@indiecinema.test'],
        ]);
        self::baseConTablasNuevas($this->configuracion);
    }

    /**
     * @return iterable<string, array{string, ?string, string, int}>
     */
    public static function paginas(): iterable
    {
        yield 'solicitar sala' => ['/solicitar-sala', 'espectador', 'GET', 200];
        yield 'solicitar sala con errores' => ['/solicitar-sala', 'espectador', 'POST', 422];
        yield 'bandeja de solicitudes' => ['/solicitudes', 'administrador', 'GET', 200];
        yield 'historial de solicitudes' => ['/solicitudes?ver=historial', 'administrador', 'GET', 200];
    }

    #[DataProvider('paginas')]
    public function testUnSoloH1YSinSaltosDeNivel(string $url, ?string $rol, string $metodo, int $estado): void
    {
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => self::USUARIO,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Ana Prueba'),
        ];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('moderacion', fopen('php://memory', 'w')));

        $respuesta = $aplicacion->atender(new Peticion(
            $metodo,
            (string) parse_url($url, PHP_URL_PATH),
            $consulta,
            $metodo === 'POST' ? ['_csrf' => self::CSRF] : [],
            $cabeceras,
            ['csrf' => self::CSRF],
        ));

        self::assertSame($estado, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame([], Encabezados::problemas($respuesta->cuerpo()));
    }
}
