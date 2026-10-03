<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Controladores;

use IndieCinema\Programacion\Controladores\FormularioDeSala;
use IndieCinema\Programacion\Pruebas\Dobles\Imagenes;
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function imagenesValidas(): iterable
    {
        yield 'JPG' => ['sala.jpg', 'jpg'];
        yield 'PNG' => ['sala.png', 'png'];
        yield 'WebP' => ['sala.webp', 'webp'];
    }

    #[DataProvider('imagenesValidas')]
    public function testAceptaJpgPngYWebpYLaExtensionSaleDelContenido(string $archivo, string $extension): void
    {
        // El navegador dice «foto.jpg» e «image/jpeg» para las tres.
        $formulario = FormularioDeSala::desdeCampos(self::camposValidos(), Imagenes::subida($archivo), imagenObligatoria: true);

        self::assertSame([], $formulario->errores);
        self::assertSame($extension, $formulario->imagen?->extension);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function imagenesInvalidas(): iterable
    {
        yield 'un PDF que se llama .jpg' => ['documento.jpg', 'La foto tiene que ser JPG, PNG o WebP.'];
        yield 'un PNG que no se puede leer' => ['roto.png', 'La foto tiene que ser JPG, PNG o WebP.'];
        yield 'una de 9000 × 9000' => ['enorme.png', 'La foto puede medir hasta 8000 píxeles de lado.'];
    }

    #[DataProvider('imagenesInvalidas')]
    public function testRechazaLoQueNoEsUnaImagenAceptable(string $archivo, string $mensaje): void
    {
        $formulario = FormularioDeSala::desdeCampos(self::camposValidos(), Imagenes::subida($archivo), imagenObligatoria: true);

        self::assertSame(['imagen' => $mensaje], $formulario->errores);
        self::assertNull($formulario->datos());
        self::assertNull($formulario->imagen);
    }

    public function testRechazaUnaImagenDeMasDe5Mb(): void
    {
        // Lo que PHP marca cuando pasa upload_max_filesize…
        $grande = ['error' => UPLOAD_ERR_INI_SIZE] + Imagenes::subida('sala.png');
        self::assertSame(['imagen' => 'La foto puede pesar hasta 5 MB.'], FormularioDeSala::desdeCampos(self::camposValidos(), $grande)->errores);

        // …y si igual llegara, se mide el archivo.
        $subida = Imagenes::subida('sala.png');
        file_put_contents($subida['tmp_name'], str_repeat("\0", 5 * 1024 * 1024), FILE_APPEND);
        self::assertSame(['imagen' => 'La foto puede pesar hasta 5 MB.'], FormularioDeSala::desdeCampos(self::camposValidos(), $subida)->errores);
    }

    public function testEnElAltaLaImagenEsObligatoriaYAlEditarNo(): void
    {
        $sinArchivo = ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];

        self::assertSame(['imagen' => 'Falta la foto de la sala.'], FormularioDeSala::desdeCampos(self::camposValidos(), $sinArchivo, imagenObligatoria: true)->errores);
        self::assertSame(['imagen' => 'Falta la foto de la sala.'], FormularioDeSala::desdeCampos(self::camposValidos(), null, imagenObligatoria: true)->errores);

        $edicion = FormularioDeSala::desdeCampos(self::camposValidos(), $sinArchivo);
        self::assertNotNull($edicion->datos());
        self::assertNull($edicion->imagen);
    }

    public function testSiOtroCampoFallaLaImagenNoSeUsa(): void
    {
        $formulario = FormularioDeSala::desdeCampos(['capacidad' => '0'] + self::camposValidos(), Imagenes::subida('sala.png'));

        self::assertSame(['capacidad'], array_keys($formulario->errores));
        self::assertNull($formulario->imagen);
    }
}
