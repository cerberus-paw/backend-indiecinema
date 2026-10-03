<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use PHPUnit\Framework\TestCase;

/**
 * El inicio y el cierre de sesión de punta a punta: la aplicación entera con las rutas de
 * cuentas, la plantilla del paquete front y un MySQL de verdad con las tablas de las migraciones.
 */
final class IngresoTest extends TestCase
{
    use ConBaseDePruebas;

    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const CONTRASENA = 'butaca 2026';

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private RepositorioDeUsuarios $usuarios;
    private string $mariana;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->usuarios = new RepositorioDeUsuarios($this->base);
        $usuario = Usuario::nuevo('Mariana Rossi', 'mariana@ejemplo.com', password_hash(self::CONTRASENA, PASSWORD_ARGON2ID));
        $this->usuarios->agregar($usuario);
        $this->mariana = $usuario->id;
    }

    /**
     * @param array<string, mixed>  $cuerpo
     * @param array<string, string> $cookies además de la del token CSRF
     */
    private function pedir(string $metodo, string $ruta, array $cuerpo = [], array $cookies = []): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('cuentas', fopen('php://memory', 'w+')));

        return $aplicacion->atender(new Peticion($metodo, $ruta, [], $cuerpo, [], $cookies + ['csrf' => self::CSRF]));
    }

    /**
     * @param array<string, string> $cookies
     */
    private function ingresar(string $correo = 'mariana@ejemplo.com', string $contrasena = self::CONTRASENA, array $cookies = []): Respuesta
    {
        return $this->pedir('POST', '/ingresar', ['_csrf' => self::CSRF, 'email' => $correo, 'password' => $contrasena], $cookies);
    }

    /**
     * @return array{nombre: string, valor: string, opciones: array<string, mixed>}|null
     */
    private static function cookieDeSesion(Respuesta $respuesta): ?array
    {
        foreach ($respuesta->cookies() as $cookie) {
            if ($cookie['nombre'] === 'sesion') {
                return $cookie;
            }
        }

        return null;
    }

    private function sesiones(): int
    {
        return $this->base->valor('SELECT COUNT(*) FROM sesion');
    }

    public function testMuestraElFormularioConElToken(): void
    {
        $respuesta = $this->pedir('GET', '/ingresar');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('action="/cuenta/ingresar"', $respuesta->cuerpo());
        self::assertStringContainsString('name="_csrf" value="' . self::CSRF . '"', $respuesta->cuerpo());
        self::assertStringNotContainsString('No pudimos iniciar tu sesión', $respuesta->cuerpo());
    }

    public function testConLaContrasenaCorrectaAbreLaSesionYMandaLaCookie(): void
    {
        // El correo no distingue mayúsculas, como en el registro.
        $respuesta = $this->ingresar('Mariana@Ejemplo.COM');

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/', $respuesta->cabecera('Location'));
        $cookie = self::cookieDeSesion($respuesta);
        self::assertNotNull($cookie);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cookie['valor']);
        self::assertSame('/', $cookie['opciones']['path']);
        self::assertTrue($cookie['opciones']['httponly']);
        self::assertTrue($cookie['opciones']['secure']);
        self::assertSame('Lax', $cookie['opciones']['samesite']);
        self::assertArrayNotHasKey('domain', $cookie['opciones']);

        // En la base, la sesión de Mariana con la huella de la cookie y el mismo vencimiento.
        $sesion = $this->base->fila('SELECT usuario_id, vence_en FROM sesion WHERE huella = ?', [hash('sha256', $cookie['valor'])]);
        self::assertSame($this->mariana, $sesion['usuario_id'] ?? null);
        self::assertSame((new DateTimeImmutable((string) $sesion['vence_en'], new DateTimeZone('UTC')))->getTimestamp(), $cookie['opciones']['expires']);
    }

    public function testElErrorNoDiceSiFallaElCorreoOLaContrasena(): void
    {
        $contrasenaIncorrecta = $this->ingresar(contrasena: 'butaca 2027');
        $correoInexistente = $this->ingresar('nadie@ejemplo.com');

        foreach ([$contrasenaIncorrecta, $correoInexistente] as $respuesta) {
            self::assertSame(422, $respuesta->estado());
            self::assertStringContainsString('El correo o la contraseña no son correctos.', $respuesta->cuerpo());
            self::assertNull(self::cookieDeSesion($respuesta));
        }
        self::assertSame(0, $this->sesiones());
        // Vuelve el correo que escribió, nunca la contraseña.
        self::assertStringContainsString('value="nadie@ejemplo.com"', $correoInexistente->cuerpo());
        self::assertStringNotContainsString('butaca 2027', $contrasenaIncorrecta->cuerpo());
    }

    public function testSinCorreoNiContrasenaMarcaLosCampos(): void
    {
        $respuesta = $this->ingresar('', '');

        self::assertSame(422, $respuesta->estado());
        self::assertMatchesRegularExpression('#id="error-email"[^>]*>.*Falta el correo.#s', $respuesta->cuerpo());
        self::assertMatchesRegularExpression('#id="error-password"[^>]*>.*Falta la contraseña.#s', $respuesta->cuerpo());
    }

    public function testUnaCuentaSuspendidaNoEntra(): void
    {
        $this->usuarios->cambiarEstado($this->mariana, EstadoCuenta::Suspendida);

        $respuesta = $this->ingresar();

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Tu cuenta está suspendida.', $respuesta->cuerpo());
        self::assertSame(0, $this->sesiones());
    }

    public function testCadaIngresoGeneraUnIdentificadorNuevoYCierraElAnterior(): void
    {
        $primera = self::cookieDeSesion($this->ingresar())['valor'] ?? '';

        $segunda = self::cookieDeSesion($this->ingresar(cookies: ['sesion' => $primera]))['valor'] ?? '';

        self::assertNotSame($primera, $segunda);
        self::assertSame([['huella' => hash('sha256', $segunda)]], $this->base->filas('SELECT huella FROM sesion'));
    }

    public function testSinElTokenCsrfNoEntra(): void
    {
        $respuesta = $this->pedir('POST', '/ingresar', ['_csrf' => str_repeat('d', 64), 'email' => 'mariana@ejemplo.com', 'password' => self::CONTRASENA]);

        self::assertSame(403, $respuesta->estado());
        self::assertSame(0, $this->sesiones());
    }

    public function testSalirBorraLaFilaYLaCookie(): void
    {
        $identificador = self::cookieDeSesion($this->ingresar())['valor'] ?? '';

        $respuesta = $this->pedir('POST', '/salir', ['_csrf' => self::CSRF], ['sesion' => $identificador]);

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/', $respuesta->cabecera('Location'));
        self::assertSame(0, $this->sesiones());
        $cookie = self::cookieDeSesion($respuesta);
        self::assertSame('', $cookie['valor'] ?? null);
        self::assertLessThan(time(), $cookie['opciones']['expires']);
        self::assertSame('/', $cookie['opciones']['path']);
    }

    public function testSalirSinSesionOSinTokenNoRompe(): void
    {
        $identificador = self::cookieDeSesion($this->ingresar())['valor'] ?? '';

        self::assertSame(303, $this->pedir('POST', '/salir', ['_csrf' => self::CSRF])->estado());
        // Sin el token CSRF, otro sitio no puede cerrarle la sesión.
        self::assertSame(403, $this->pedir('POST', '/salir', [], ['sesion' => $identificador])->estado());
        self::assertSame(1, $this->sesiones());
    }
}
