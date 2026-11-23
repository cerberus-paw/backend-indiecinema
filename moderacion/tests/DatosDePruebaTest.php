<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use IndieCinema\Moderacion\Repositorios\RepositorioDeDenuncias;
use IndieCinema\Moderacion\Servicios\ArchivosDeSolicitudes;
use IndieCinema\Moderacion\Servicios\DatosDePrueba;
use IndieCinema\Moderacion\Servicios\FuncionesDeProgramacion;
use IndieCinema\Moderacion\Servicios\SalasDeProgramacion;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Las denuncias de prueba de moderación (EF-22.11): dejan el Alambique a una denuncia del aviso,
 * con una alta abierta sobre una de sus funciones, contra la programación de mentira.
 */
final class DatosDePruebaTest extends TestCase
{
    use ConBaseDePruebas;
    use ConServidoresDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    // El organizador que conoce Dobles/cuentas.php, y el administrador de los datos de prueba.
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const ADMINISTRADOR = '00000000-0000-4000-8000-000000000001';

    private static ServidorSmtpDePrueba $correo;

    private BaseDeDatos $base;

    public static function setUpBeforeClass(): void
    {
        self::iniciarServidores();
        self::$correo = ServidorSmtpDePrueba::iniciar();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerServidores();
        self::$correo->detener();
    }

    protected function setUp(): void
    {
        self::reiniciarServidores();
        self::$correo->vaciar();
        $this->base = self::baseConTablasNuevas($this->configuracion());
    }

    private function configuracion(string $entorno = 'desarrollo'): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'moderacion', 'prefijo' => '/moderacion', 'entorno' => $entorno],
            'archivos' => ['carpeta' => sys_get_temp_dir() . '/sin-archivos'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['cuentas' => self::$urls['cuentas'], 'programacion' => self::$urls['programacion'], 'funciones' => self::$urls['funciones']],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
            'denuncias' => ['aviso_cantidad' => 3, 'cartelera_cantidad' => 5, 'dias' => 30],
        ]);
    }

    private function datos(string $entorno = 'desarrollo', bool $confirmar = false): DatosDePrueba
    {
        $configuracion = $this->configuracion($entorno);
        $cliente = ClienteInterno::desdeConfiguracion($configuracion, new Log('moderacion', fopen('php://memory', 'w')));

        return DatosDePrueba::para(
            $configuracion,
            new RepositorioDeDenuncias($this->base),
            new SalasDeProgramacion($cliente, new ArchivosDeSolicitudes($configuracion)),
            new FuncionesDeProgramacion($cliente),
            $confirmar,
        );
    }

    /**
     * El Alambique y su función, con los ids fijos de los datos de prueba de programación.
     */
    private static function programacionConElAlambique(): void
    {
        self::salasEnProgramacion([DatosDePrueba::SALA => ['nombre' => 'Cine Club El Alambique', 'localidad' => 'Luján', 'organizador_id' => self::ORGANIZADOR, 'habilitada' => true]]);
        self::obrasEnProgramacion(['00000002-0000-4000-8000-000000000001' => ['titulo' => 'Metrópolis', 'dueno_id' => self::ORGANIZADOR, 'estado' => 'aprobada']]);
        self::funcionesEnProgramacion([DatosDePrueba::FUNCION => [
            'estado' => 'programada',
            'fecha_hora' => '2099-01-10T20:00:00',
            'precio_base' => 2500,
            'cupo' => 80,
            'titulos' => ['Metrópolis'],
            'titulos_elegidos' => true,
            'sala' => ['id' => DatosDePrueba::SALA, 'nombre' => 'Cine Club El Alambique', 'localidad' => 'Luján', 'habilitada' => true, 'direccion' => 'San Martín 420'],
            'organizador_id' => self::ORGANIZADOR,
            'obras_ids' => ['00000002-0000-4000-8000-000000000001'],
        ]]);
    }

    /**
     * @param array<string, string> $cuerpo
     */
    private function pedir(string $metodo, string $url, array $cuerpo = []): Respuesta
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('moderacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo + ['_csrf' => self::CSRF], [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => self::ADMINISTRADOR,
            'X-Rol' => 'administrador',
        ], ['csrf' => self::CSRF]));
    }

    /**
     * La demo: la bandeja muestra el Alambique a uno del aviso y su función para suspender; el
     * administrador lo denuncia, lo toma y el organizador recibe el aviso. Correrlo de nuevo no
     * agrega nada.
     */
    public function testDejaLaSalaAUnaDelAvisoYLaDemoLlegaAlCorreo(): void
    {
        self::programacionConElAlambique();

        self::assertSame([true, true, true], array_values($this->datos()->cargar()));
        self::assertSame([false, false, false], array_values($this->datos()->cargar()));
        self::assertSame(3, (int) $this->base->valor('SELECT COUNT(*) FROM denuncia'));

        $bandeja = $this->pedir('GET', '/denuncias')->cuerpo();
        self::assertStringContainsString('Para suspender', $bandeja);
        self::assertStringContainsString('2 de 3 para aviso · 2 de 5 para cartelera', $bandeja);

        $sala = DatosDePrueba::SALA;
        self::assertSame(303, $this->pedir('POST', "/denuncias/nueva?tipo=sala&id={$sala}", ['categoria' => 'falsa', 'detalle' => 'El precio no era el de la ficha.'])->estado());
        self::assertSame(303, $this->pedir('POST', "/denuncias/sala/{$sala}/resolver", ['accion' => 'tomar', 'motivo' => 'El precio estaba mal.'])->estado());
        self::assertCount(1, self::$correo->correos());
        self::assertStringContainsString('Tu sala acumula denuncias', self::$correo->correos()[0]['mensaje']);
    }

    public function testSinLaSalaEnProgramacionNoCargaNada(): void
    {
        try {
            $this->datos()->cargar();
            self::fail('Cargó sin la sala de prueba.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('No se cargó nada.', $error->getMessage());
        }
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM denuncia'));
    }

    public function testEnProduccionPideLaConfirmacion(): void
    {
        self::programacionConElAlambique();

        $this->expectException(RuntimeException::class);
        $this->datos('produccion');
    }
}
