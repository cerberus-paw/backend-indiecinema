<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas\Controladores;

use IndieCinema\Cuentas\Controladores\FormularioDeRegistro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormularioDeRegistroTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    public static function camposValidos(): array
    {
        return [
            'nombre' => 'Mariana Rossi',
            'email' => 'mariana@ejemplo.com',
            'password' => 'butaca 2026',
            'password_confirmacion' => 'butaca 2026',
            'terminos' => '1',
        ];
    }

    public function testConTodoBienDaLosDatos(): void
    {
        $formulario = FormularioDeRegistro::desdeCampos(['nombre' => "  Mariana \t Rossi ", 'email' => ' mariana@ejemplo.com '] + self::camposValidos());
        $datos = $formulario->datos();

        self::assertSame([], $formulario->errores);
        self::assertNotNull($datos);
        self::assertSame('Mariana Rossi', $datos->nombre);
        self::assertSame('mariana@ejemplo.com', $datos->correo);
        // La contraseña no se recorta: los espacios son parte de ella.
        self::assertSame('butaca 2026', $datos->contrasena);
    }

    public function testSinNadaMarcaTodosLosCampos(): void
    {
        $formulario = FormularioDeRegistro::desdeCampos([]);

        self::assertNull($formulario->datos());
        self::assertSame(array_keys(self::camposValidos()), array_keys($formulario->errores));
        self::assertSame('Falta el correo.', $formulario->errores['email']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function camposInvalidos(): iterable
    {
        yield 'nombre largo' => ['nombre', str_repeat('a', 101), 'El nombre puede tener hasta 100 caracteres.'];
        yield 'correo sin arroba' => ['email', 'mariana.ejemplo.com', 'Escribí un correo válido, como nombre@ejemplo.com.'];
        yield 'correo sin dominio' => ['email', 'mariana@', 'Escribí un correo válido, como nombre@ejemplo.com.'];
        yield 'correo con acentos' => ['email', 'maría@ejemplo.com', 'Escribí un correo válido, como nombre@ejemplo.com.'];
        yield 'correo largo' => ['email', str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 63) . '.com', 'Escribí un correo válido, como nombre@ejemplo.com.'];
        yield 'términos sin tildar' => ['terminos', '', 'Para crear la cuenta tenés que aceptar los términos.'];
        yield 'términos con otro valor' => ['terminos', 'si', 'Para crear la cuenta tenés que aceptar los términos.'];
    }

    #[DataProvider('camposInvalidos')]
    public function testRechazaCadaCampoInvalido(string $campo, string $valor, string $mensaje): void
    {
        $formulario = FormularioDeRegistro::desdeCampos([$campo => $valor] + self::camposValidos());

        self::assertNull($formulario->datos());
        self::assertSame([$campo => $mensaje], $formulario->errores);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function contrasenasInvalidas(): iterable
    {
        yield 'corta' => ['butaca7', 'La contraseña tiene que tener al menos 8 caracteres.'];
        yield 'sin números' => ['butacaroja', 'La contraseña tiene que tener letras y números.'];
        yield 'sin letras' => ['12345678', 'La contraseña tiene que tener letras y números.'];
        yield 'larga' => [str_repeat('a1', 65), 'La contraseña puede tener hasta 128 caracteres.'];
    }

    #[DataProvider('contrasenasInvalidas')]
    public function testRechazaContrasenasDebiles(string $contrasena, string $mensaje): void
    {
        $formulario = FormularioDeRegistro::desdeCampos(['password' => $contrasena, 'password_confirmacion' => $contrasena] + self::camposValidos());

        self::assertNull($formulario->datos());
        self::assertSame(['password' => $mensaje], $formulario->errores);
    }

    public function testLasContrasenasTienenQueCoincidir(): void
    {
        $formulario = FormularioDeRegistro::desdeCampos(['password_confirmacion' => 'butaca 2027'] + self::camposValidos());

        self::assertSame(['password_confirmacion' => 'Las contraseñas no coinciden.'], $formulario->errores);
    }

    public function testDevuelveLoCargadoMenosLasContrasenas(): void
    {
        $formulario = FormularioDeRegistro::desdeCampos(['email' => 'mal'] + self::camposValidos());

        self::assertSame(['nombre' => 'Mariana Rossi', 'email' => 'mal', 'terminos' => true], $formulario->valores);
    }

    public function testUnCampoQueLlegaComoArregloCuentaComoVacio(): void
    {
        $formulario = FormularioDeRegistro::desdeCampos(['nombre' => ['Mariana'], 'password' => ['x']] + self::camposValidos());

        self::assertSame('Falta el nombre.', $formulario->errores['nombre']);
        self::assertSame('Falta la contraseña.', $formulario->errores['password']);
    }
}
