<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Controladores;

use IndieCinema\Programacion\Controladores\FormularioDeSala;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormularioDeSalaTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    public static function camposValidos(): array
    {
        return [
            'nombre' => 'Cine Club El Galpón',
            'descripcion' => "Un galpón con proyector.\nSesenta butacas.",
            'direccion' => 'Av. San Martín 450',
            'localidad' => 'Luján',
            'capacidad' => '60',
            'peliculas_por_funcion' => '2',
            'duracion_funcion' => '120',
            'tiempo_entre_funciones' => '0',
        ];
    }

    public function testConTodoBienDaLosDatos(): void
    {
        $formulario = FormularioDeSala::desdeCampos(self::camposValidos());
        $datos = $formulario->datos();

        self::assertSame([], $formulario->errores);
        self::assertNotNull($datos);
        self::assertSame('Cine Club El Galpón', $datos->nombre);
        self::assertSame("Un galpón con proyector.\nSesenta butacas.", $datos->descripcion);
        self::assertSame(60, $datos->capacidad);
        self::assertSame(2, $datos->peliculasPorFuncion);
        self::assertSame(120, $datos->duracionFuncion);
        self::assertSame(0, $datos->tiempoEntreFunciones);
    }

    public function testSinNadaMarcaTodosLosCampos(): void
    {
        $formulario = FormularioDeSala::desdeCampos([]);

        self::assertNull($formulario->datos());
        self::assertSame(array_keys(self::camposValidos()), array_keys($formulario->errores));
        self::assertSame('Falta el nombre.', $formulario->errores['nombre']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function numerosInvalidos(): iterable
    {
        yield 'capacidad cero' => ['capacidad', '0'];
        yield 'capacidad negativa' => ['capacidad', '-5'];
        yield 'capacidad con coma' => ['capacidad', '60,5'];
        yield 'capacidad enorme' => ['capacidad', '99999999999999999999'];
        yield 'capacidad con texto' => ['capacidad', '60 butacas'];
        yield 'sin películas' => ['peliculas_por_funcion', '0'];
        yield 'duración cero' => ['duracion_funcion', '0'];
        yield 'intervalo negativo' => ['tiempo_entre_funciones', '-1'];
    }

    #[DataProvider('numerosInvalidos')]
    public function testRechazaNumerosFueraDeRango(string $campo, string $valor): void
    {
        $formulario = FormularioDeSala::desdeCampos([$campo => $valor] + self::camposValidos());

        self::assertNull($formulario->datos());
        self::assertSame([$campo], array_keys($formulario->errores));
        // Se vuelve a mostrar lo que escribió, para que lo corrija.
        self::assertSame($valor, $formulario->valores[$campo]);
    }

    public function testRechazaTextosMasLargosQueLaColumna(): void
    {
        $formulario = FormularioDeSala::desdeCampos(['nombre' => str_repeat('ñ', 101)] + self::camposValidos());

        self::assertSame(['nombre' => 'El nombre puede tener hasta 100 caracteres.'], $formulario->errores);
        self::assertNotNull(FormularioDeSala::desdeCampos(['nombre' => str_repeat('ñ', 100)] + self::camposValidos())->datos());
    }

    public function testLimpiaEspaciosYCaracteresDeControl(): void
    {
        $datos = FormularioDeSala::desdeCampos([
            'nombre' => "  El \t Galpón\x00\x07 ",
            'descripcion' => "Primera línea\r\nSegunda\x1b línea  ",
        ] + self::camposValidos())->datos();

        self::assertNotNull($datos);
        self::assertSame('El Galpón', $datos->nombre);
        self::assertSame("Primera línea\nSegunda línea", $datos->descripcion);
    }

    public function testUnCampoQueLlegaComoArregloEsComoSiFaltara(): void
    {
        $formulario = FormularioDeSala::desdeCampos(['nombre' => ['a', 'b'], 'capacidad' => ['60']] + self::camposValidos());

        self::assertSame(['nombre', 'capacidad'], array_keys($formulario->errores));
    }

    public function testRechazaTextoQueNoEsUtf8(): void
    {
        $formulario = FormularioDeSala::desdeCampos(['localidad' => "Luj\xe1n"] + self::camposValidos());

        self::assertSame(['localidad'], array_keys($formulario->errores));
    }
}
