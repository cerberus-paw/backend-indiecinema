<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Controladores;

use IndieCinema\Programacion\Controladores\FormularioDeObra;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Pruebas\Dobles\Imagenes;
use PHPUnit\Framework\TestCase;

final class FormularioDeObraTest extends TestCase
{
    /**
     * @return list<Genero>
     */
    public static function generosDePrueba(): array
    {
        return [
            new Genero('00000000-0000-4000-8000-000000000001', 'Drama', ''),
            new Genero('00000000-0000-4000-8000-000000000002', 'Comedia', ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function camposValidos(): array
    {
        return [
            'titulo' => 'Historias extraordinarias',
            'director' => 'Mariano Llinás',
            'sinopsis' => "Un hombre que se llama X se involucra accidentalmente en un crimen.\nTres historias paralelas.",
            'anio' => '2008',
            'duracion' => '245',
            'tipo' => 'pelicula',
            'origen_copia' => 'obra_propia',
            'generos' => ['00000000-0000-4000-8000-000000000001'],
        ];
    }

    public function testFormularioValidoConAfiche(): void
    {
        $formulario = FormularioDeObra::desdeCampos(
            self::camposValidos(),
            Imagenes::subida('sala.jpg'),
            self::generosDePrueba(),
            aficheObligatorio: true,
        );

        self::assertSame([], $formulario->errores);
        self::assertNotNull($formulario->datos());
        self::assertSame('Historias extraordinarias', $formulario->datos()->titulo);
        self::assertSame(2008, $formulario->datos()->anio);
        self::assertSame(TipoObra::Pelicula, $formulario->datos()->tipo);
        self::assertSame(OrigenCopia::ObraPropia, $formulario->datos()->origenCopia);
        self::assertNotNull($formulario->imagen);
    }

    public function testLasReglasLlevanLosMismosMensajesQueDevuelveLaValidacion(): void
    {
        $vacio = FormularioDeObra::desdeCampos([], aficheObligatorio: true);
        $reglas = FormularioDeObra::reglas(aficheObligatorio: true);

        foreach (['titulo', 'director', 'sinopsis', 'anio', 'duracion', 'tipo', 'origen_copia', 'generos', 'afiche'] as $campo) {
            self::assertSame($vacio->errores[$campo], $reglas[$campo]['mensajes']['requerido'], "mensaje de {$campo} vacío");
        }

        $largo = FormularioDeObra::desdeCampos([...self::camposValidos(), 'titulo' => str_repeat('a', 151)], generosDisponibles: self::generosDePrueba());
        self::assertSame($largo->errores['titulo'], $reglas['titulo']['mensajes']['maximo']);

        $fueraAnio = FormularioDeObra::desdeCampos([...self::camposValidos(), 'anio' => '1800'], generosDisponibles: self::generosDePrueba());
        self::assertSame($fueraAnio->errores['anio'], $reglas['anio']['mensajes']['valor_minimo']);
    }

    public function testRechazaValoresFueraDeRangoOInvalidos(): void
    {
        $anioFuturo = FormularioDeObra::desdeCampos([...self::camposValidos(), 'anio' => (string) ((int) date('Y') + 1)], generosDisponibles: self::generosDePrueba());
        self::assertArrayHasKey('anio', $anioFuturo->errores);

        $duracionCero = FormularioDeObra::desdeCampos([...self::camposValidos(), 'duracion' => '0'], generosDisponibles: self::generosDePrueba());
        self::assertArrayHasKey('duracion', $duracionCero->errores);

        $tipoInvalido = FormularioDeObra::desdeCampos([...self::camposValidos(), 'tipo' => 'desconocido'], generosDisponibles: self::generosDePrueba());
        self::assertArrayHasKey('tipo', $tipoInvalido->errores);

        $origenInvalido = FormularioDeObra::desdeCampos([...self::camposValidos(), 'origen_copia' => 'desconocido'], generosDisponibles: self::generosDePrueba());
        self::assertArrayHasKey('origen_copia', $origenInvalido->errores);

        $generoInvalido = FormularioDeObra::desdeCampos([...self::camposValidos(), 'generos' => ['inexistente']], generosDisponibles: self::generosDePrueba());
        self::assertArrayHasKey('generos', $generoInvalido->errores);
    }

    public function testVerificaAfichePorContenidoReal(): void
    {
        $falsoJpg = FormularioDeObra::desdeCampos(
            self::camposValidos(),
            Imagenes::subida('documento.jpg'),
            self::generosDePrueba(),
            aficheObligatorio: true,
        );
        self::assertSame('El afiche tiene que ser JPG, PNG o WebP.', $falsoJpg->errores['afiche'] ?? null);

        $roto = FormularioDeObra::desdeCampos(
            self::camposValidos(),
            Imagenes::subida('roto.png'),
            self::generosDePrueba(),
            aficheObligatorio: true,
        );
        self::assertSame('El afiche tiene que ser JPG, PNG o WebP.', $roto->errores['afiche'] ?? null);

        $enorme = FormularioDeObra::desdeCampos(
            self::camposValidos(),
            Imagenes::subida('enorme.png'),
            self::generosDePrueba(),
            aficheObligatorio: true,
        );
        self::assertSame('El afiche puede medir hasta 8000 píxeles de lado.', $enorme->errores['afiche'] ?? null);
    }

    public function testEnEdicionElAficheEsOpcionalYConservaLosDatos(): void
    {
        $sinAfiche = FormularioDeObra::desdeCampos(
            self::camposValidos(),
            null,
            self::generosDePrueba(),
            aficheObligatorio: false,
        );
        self::assertSame([], $sinAfiche->errores);
        self::assertNotNull($sinAfiche->datos());
        self::assertNull($sinAfiche->imagen);

        $datos = $sinAfiche->datos();
        $desdeDatos = FormularioDeObra::desdeDatos($datos);
        self::assertSame($datos->titulo, $desdeDatos->valores['titulo']);
        self::assertSame((string) $datos->anio, $desdeDatos->valores['anio']);
        self::assertSame($datos->tipo->value, $desdeDatos->valores['tipo']);
    }
}
