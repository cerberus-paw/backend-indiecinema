<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Cuentas\Pruebas\Controladores\FormularioDeRegistroTest;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use PHPUnit\Framework\TestCase;

/**
 * El formulario de registro de punta a punta: la aplicación entera con las rutas de cuentas y la
 * plantilla del paquete front. En desarrollo, para que Twig falle si la plantilla usa una
 * variable que el controlador no le pasa.
 */
final class RegistroTest extends TestCase
{
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo, array $cuerpo = []): Respuesta
    {
        $configuracion = new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'nginx' => ['secreto' => 'secreto-de-nginx'],
        ]);
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion, new Log('cuentas', fopen('php://memory', 'w+')));

        return $aplicacion->atender(new Peticion($metodo, '/registro', [], $cuerpo, [], ['csrf' => self::CSRF]));
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

    public function testConTodoBienNoHayErrores(): void
    {
        $respuesta = $this->enviar();

        self::assertSame(200, $respuesta->estado());
        self::assertStringNotContainsString('campo-error', $respuesta->cuerpo());
    }
}
