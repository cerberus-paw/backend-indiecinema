<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas\Controladores;

use IndieCinema\Cuentas\Controladores\FormularioDeSolicitud;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormularioDeSolicitudTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    public static function camposValidos(): array
    {
        return [
            'sala' => 'El Galpón de la Estación',
            'localidad' => 'Luján',
            'correo' => 'galpon@ejemplo.com',
            'telefono' => '+54 2323 44-1234',
            'presentacion' => 'Galpón ferroviario reciclado como espacio cultural, a dos cuadras de la estación.',
            'habilitacion' => '1',
            'derechos' => '1',
        ];
    }

    public function testConTodoBienDaLosDatos(): void
    {
        $formulario = FormularioDeSolicitud::desdeCampos([
            'sala' => "  El Galpón \t de la Estación ",
            'presentacion' => "  Galpón ferroviario reciclado como espacio cultural.\r\n\r\nA dos cuadras de la estación.\u{0007} ",
        ] + self::camposValidos());
        $datos = $formulario->datos();

        self::assertSame([], $formulario->errores);
        self::assertNotNull($datos);
        self::assertSame('El Galpón de la Estación', $datos->sala);
        self::assertSame('Luján', $datos->localidad);
        self::assertSame('galpon@ejemplo.com', $datos->correo);
        self::assertSame('+54 2323 44-1234', $datos->telefono);
        // La presentación conserva sus renglones, con un solo carácter por salto.
        self::assertSame("Galpón ferroviario reciclado como espacio cultural.\n\nA dos cuadras de la estación.", $datos->presentacion);
    }

    public function testElTelefonoEsOptativo(): void
    {
        $datos = FormularioDeSolicitud::desdeCampos(['telefono' => '  '] + self::camposValidos())->datos();

        self::assertNotNull($datos);
        self::assertNull($datos->telefono);
    }

    public function testSinNadaMarcaTodoMenosElTelefono(): void
    {
        $formulario = FormularioDeSolicitud::desdeCampos([]);

        self::assertNull($formulario->datos());
        self::assertSame(['sala', 'localidad', 'correo', 'presentacion', 'habilitacion', 'derechos'], array_keys($formulario->errores));
        self::assertSame('Falta el nombre de la sala.', $formulario->errores['sala']);
        self::assertSame('Falta el correo de contacto.', $formulario->errores['correo']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function camposInvalidos(): iterable
    {
        yield 'sala larga' => ['sala', str_repeat('a', 101), 'El nombre de la sala puede tener hasta 100 caracteres.'];
        yield 'localidad larga' => ['localidad', str_repeat('á', 101), 'La localidad puede tener hasta 100 caracteres.'];
        yield 'correo sin arroba' => ['correo', 'galpon.ejemplo.com', 'Escribí un correo válido, como nombre@ejemplo.com.'];
        yield 'correo con espacios' => ['correo', 'el galpon@ejemplo.com', 'Escribí un correo válido, como nombre@ejemplo.com.'];
        yield 'correo con ñ' => ['correo', 'muñoz@ejemplo.com', 'Escribí un correo válido, como nombre@ejemplo.com.'];
        yield 'correo largo' => ['correo', str_repeat('a', 243) . '@ejemplo.com', 'El correo de contacto puede tener hasta 254 caracteres.'];
        yield 'teléfono corto' => ['telefono', '1234', 'El teléfono tiene que tener al menos 6 caracteres.'];
        yield 'teléfono largo' => ['telefono', str_repeat('1', 21), 'El teléfono puede tener hasta 20 caracteres.'];
        yield 'teléfono con letras' => ['telefono', '2323-galpón', 'El teléfono va con números, espacios o guiones, y puede empezar con +.'];
        yield 'teléfono con + en el medio' => ['telefono', '2323+441234', 'El teléfono va con números, espacios o guiones, y puede empezar con +.'];
        yield 'presentación corta' => ['presentacion', 'Un galpón.', 'La presentación tiene que tener al menos 50 caracteres.'];
        yield 'presentación larga' => ['presentacion', str_repeat('a', 2001), 'La presentación puede tener hasta 2000 caracteres.'];
        yield 'sin habilitación' => ['habilitacion', '', 'Tenés que declarar que el espacio está habilitado.'];
        yield 'sin derechos' => ['derechos', 'on', 'Tenés que comprometerte a tener los derechos de exhibición.'];
    }

    #[DataProvider('camposInvalidos')]
    public function testRechazaCadaCampoInvalido(string $campo, string $valor, string $mensaje): void
    {
        $formulario = FormularioDeSolicitud::desdeCampos([$campo => $valor] + self::camposValidos());

        self::assertNull($formulario->datos());
        self::assertSame([$campo => $mensaje], $formulario->errores);
    }

    /**
     * Los mismos que acepta el navegador en un <input type="email">, aunque filter_var() no.
     */
    public function testAceptaLosCorreosQueAceptaElNavegador(): void
    {
        foreach (['ana@sala', 'ana.de.la.sala@cine-club.com.ar', "o'neil+cine@ejemplo.com"] as $correo) {
            self::assertSame([], FormularioDeSolicitud::desdeCampos(['correo' => $correo] + self::camposValidos())->errores, $correo);
        }
    }

    public function testLosLargosSeCuentanEnCaracteresYNoEnBytes(): void
    {
        $formulario = FormularioDeSolicitud::desdeCampos([
            'sala' => str_repeat('ñ', 100),
            // 2000 caracteres contando cada salto como uno, como el navegador.
            'presentacion' => str_repeat("aaa\r\n", 499) . 'aaaa',
        ] + self::camposValidos());

        self::assertSame([], $formulario->errores);
    }

    public function testDevuelveLoCargadoParaVolverAMostrarlo(): void
    {
        $formulario = FormularioDeSolicitud::desdeCampos(['correo' => 'galpon@', 'derechos' => ''] + self::camposValidos());

        self::assertSame('galpon@', $formulario->valores['correo']);
        self::assertSame('El Galpón de la Estación', $formulario->valores['sala']);
        self::assertTrue($formulario->valores['habilitacion']);
        self::assertFalse($formulario->valores['derechos']);
    }

    public function testUnCampoQueLlegaComoArregloCuentaComoVacio(): void
    {
        $formulario = FormularioDeSolicitud::desdeCampos(['sala' => ['El Galpón'], 'habilitacion' => ['1']] + self::camposValidos());

        self::assertSame('Falta el nombre de la sala.', $formulario->errores['sala']);
        self::assertArrayHasKey('habilitacion', $formulario->errores);
    }
}
