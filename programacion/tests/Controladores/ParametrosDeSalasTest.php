<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Controladores;

use IndieCinema\Programacion\Controladores\ParametrosDeSalas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La búsqueda del listado de salas, que llega en la URL: una que no sirve se deja afuera con un
 * aviso.
 */
final class ParametrosDeSalasTest extends TestCase
{
    public function testUnaBusquedaValidaSinLosEspaciosDeAlrededor(): void
    {
        $parametros = ParametrosDeSalas::desdeConsulta(['q' => '  el galpón ']);

        self::assertSame('el galpón', $parametros->texto);
        self::assertSame([], $parametros->avisos);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function sinBusqueda(): iterable
    {
        yield 'sin el parámetro' => [null];
        yield 'vacía' => [''];
        yield 'sólo espacios' => ['   '];
    }

    #[DataProvider('sinBusqueda')]
    public function testSinBusquedaNoFiltraNiAvisa(mixed $valor): void
    {
        $parametros = ParametrosDeSalas::desdeConsulta(['q' => $valor]);

        self::assertNull($parametros->texto);
        self::assertSame([], $parametros->avisos);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidas(): iterable
    {
        yield 'un arreglo' => [['galpón'], 'La búsqueda no es un texto válido: se muestran todas las salas.'];
        yield 'un texto que no es UTF-8' => ["\xC3\x28", 'La búsqueda no es un texto válido: se muestran todas las salas.'];
        yield 'más larga que un nombre' => [str_repeat('a', 101), 'La búsqueda puede tener hasta 100 caracteres: se muestran todas las salas.'];
    }

    #[DataProvider('invalidas')]
    public function testUnaBusquedaInvalidaSeDejaAfueraConUnAviso(mixed $valor, string $aviso): void
    {
        $parametros = ParametrosDeSalas::desdeConsulta(['q' => $valor]);

        self::assertNull($parametros->texto);
        self::assertSame([$aviso], $parametros->avisos);
    }

    public function testUnaDeHasta100CaracteresSeAplica(): void
    {
        $texto = str_repeat('ñ', 100);

        self::assertSame($texto, ParametrosDeSalas::desdeConsulta(['q' => " {$texto} "])->texto);
    }
}
