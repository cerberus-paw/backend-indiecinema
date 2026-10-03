<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Cuentas\Pruebas\Controladores\FormularioDeRegistroTest;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * El registro de punta a punta: la aplicación entera con las rutas de cuentas, la plantilla del
 * paquete front y un MySQL de verdad con las tablas de las migraciones. En desarrollo, para que
 * Twig falle si la plantilla usa una variable que el controlador no le pasa.
 */
final class RegistroTest extends TestCase
{
    use ConBaseDePruebas;

    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo, array $cuerpo = [], string $url = '/registro'): Respuesta
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('cuentas', fopen('php://memory', 'w+')));

        return $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, [], ['csrf' => self::CSRF]));
    }

    /**
     * @param array<string, string> $cambios
     */
    private function enviar(array $cambios = []): Respuesta
    {
        return $this->pedir('POST', $cambios + ['_csrf' => self::CSRF] + FormularioDeRegistroTest::camposValidos());
    }

    public function testMuestraElFormularioConElToken(): void
    {
        $respuesta = $this->pedir('GET');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('action="/cuenta/registro"', $respuesta->cuerpo());
        self::assertStringContainsString('name="_csrf" value="' . self::CSRF . '"', $respuesta->cuerpo());
        // Sin errores ni mensajes, y con los términos sin tildar.
        self::assertStringNotContainsString('No se pudo completar el registro', $respuesta->cuerpo());
        self::assertStringNotContainsString('¡Cuenta creada!', $respuesta->cuerpo());
        self::assertDoesNotMatchRegularExpression('/name="terminos"[^>]*checked/', $respuesta->cuerpo());
    }

    public function testSinElTokenCsrfSeRechaza(): void
    {
        self::assertSame(403, $this->enviar(['_csrf' => str_repeat('d', 64)])->estado());
        self::assertSame(403, $this->pedir('POST', FormularioDeRegistroTest::camposValidos())->estado());
    }

    public function testConErroresLosMuestraJuntoACadaCampoSinPerderLoCargado(): void
    {
        $respuesta = $this->enviar([
            'nombre' => '<b>Mariana</b>',
            'email' => 'mariana@',
            'password_confirmacion' => 'otra cosa 1',
        ]);
        $cuerpo = $respuesta->cuerpo();

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Revisá los campos marcados.', $cuerpo);
        self::assertMatchesRegularExpression('#id="error-email"[^>]*>.*Escribí un correo válido#s', $cuerpo);
        self::assertMatchesRegularExpression('#id="error-password_confirmacion"[^>]*>.*Las contraseñas no coinciden.#s', $cuerpo);
        // Lo que escribió vuelve escapado: Twig no deja pasar HTML.
        self::assertStringContainsString('value="&lt;b&gt;Mariana&lt;/b&gt;"', $cuerpo);
        self::assertStringContainsString('value="mariana@"', $cuerpo);
        self::assertMatchesRegularExpression('/name="terminos"[^>]*checked/', $cuerpo);
        // Las contraseñas no vuelven al navegador.
        self::assertStringNotContainsString('butaca 2026', $cuerpo);
    }

    public function testSinAceptarLosTerminosNoPasa(): void
    {
        $respuesta = $this->enviar(['terminos' => '']);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Para crear la cuenta tenés que aceptar los términos.', $respuesta->cuerpo());
        self::assertDoesNotMatchRegularExpression('/name="terminos"[^>]*checked/', $respuesta->cuerpo());
    }

    public function testConTodoBienGuardaElUsuarioConArgon2idYRolEspectador(): void
    {
        $respuesta = $this->enviar(['email' => 'Mariana@Ejemplo.com']);

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/cuenta/registro?creada=1', $respuesta->cabecera('Location'));

        $usuario = $this->base->fila('SELECT id, nombre, correo, contrasena_hash, estado, correo_verificado_en FROM usuario');
        self::assertNotNull($usuario);
        self::assertTrue(Uuid::esValido((string) $usuario['id']));
        self::assertSame('Mariana Rossi', $usuario['nombre']);
        self::assertSame('Mariana@Ejemplo.com', $usuario['correo']);
        self::assertSame('activa', $usuario['estado']);
        self::assertNull($usuario['correo_verificado_en']);
        // Sólo el hash, Argon2id, y no la contraseña.
        self::assertSame('argon2id', password_get_info((string) $usuario['contrasena_hash'])['algoName']);
        self::assertTrue(password_verify('butaca 2026', (string) $usuario['contrasena_hash']));
        self::assertSame([['tipo' => 'espectador']], $this->base->filas('SELECT tipo FROM rol WHERE usuario_id = ?', [$usuario['id']]));
    }

    public function testDespuesDelAltaAvisaQueYaPuedeEntrar(): void
    {
        $respuesta = $this->pedir('GET', url: '/registro?creada=1');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('¡Cuenta creada!', $respuesta->cuerpo());
        self::assertStringContainsString('Ya podés iniciar sesión con tu correo y tu contraseña.', $respuesta->cuerpo());
    }

    public function testUnCorreoRepetidoSeAvisaJuntoAlCampo(): void
    {
        $this->enviar();
        // Con otras mayúsculas también es el mismo correo.
        $respuesta = $this->enviar(['nombre' => 'Otra Mariana', 'email' => 'MARIANA@ejemplo.com']);

        self::assertSame(422, $respuesta->estado());
        self::assertMatchesRegularExpression('#id="error-email"[^>]*>.*Ya hay una cuenta con este correo.#s', $respuesta->cuerpo());
        self::assertStringContainsString('value="Otra Mariana"', $respuesta->cuerpo());
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM usuario'));
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM rol'));
    }

    public function testConErroresOSinTokenNoSeGuardaNada(): void
    {
        $this->enviar(['password_confirmacion' => 'otra cosa 1']);
        $this->enviar(['_csrf' => str_repeat('d', 64)]);

        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM usuario'));
    }
}
