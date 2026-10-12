<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Controladores;

use DateTimeImmutable;
use IndieCinema\Programacion\Controladores\ParametrosDeCartelera;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Los parámetros de la URL de la cartelera, que cualquiera puede escribir a mano: un filtro
 * inválido se deja afuera con un aviso, y una página inválida queda en null para el 404.
 */
final class ParametrosDeCarteleraTest extends TestCase
{
    private const GENERO = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    public function testSinParametrosEsLaPrimeraPaginaSinFiltros(): void
    {
        $parametros = ParametrosDeCartelera::desdeConsulta([]);

        self::assertEquals(new FiltrosDeCartelera(), $parametros->filtros);
        self::assertSame([[], [], 1], [$parametros->valores, $parametros->avisos, $parametros->pagina]);
    }

    public function testLosParametrosValidosSonLosFiltros(): void
    {
        $parametros = ParametrosDeCartelera::desdeConsulta([
            'q' => '  la ciénaga ',
            'localidad' => 'Luján',
            'fecha' => '2026-10-24',
            'genero' => self::GENERO,
            'pagina' => '3',
        ]);

        self::assertEquals(new FiltrosDeCartelera('la ciénaga', 'Luján', new DateTimeImmutable('2026-10-24'), self::GENERO), $parametros->filtros);
        self::assertSame(['q' => 'la ciénaga', 'localidad' => 'Luján', 'fecha' => '2026-10-24', 'genero' => self::GENERO], $parametros->valores);
        self::assertSame([], $parametros->avisos);
        self::assertSame(3, $parametros->pagina);
    }

    public function testLosVaciosNoFiltranNiAvisan(): void
    {
        $parametros = ParametrosDeCartelera::desdeConsulta(['q' => '', 'localidad' => '   ', 'fecha' => '', 'genero' => '', 'pagina' => '']);

        self::assertEquals(new FiltrosDeCartelera(), $parametros->filtros);
        self::assertSame([[], [], 1], [$parametros->valores, $parametros->avisos, $parametros->pagina]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function filtrosInvalidos(): iterable
    {
        yield 'una búsqueda como arreglo (q[]=…)' => [['q' => ['zama']], 'La búsqueda no es un texto válido'];
        yield 'una búsqueda que no es UTF-8' => [['q' => "\xC3\x28"], 'La búsqueda no es un texto válido'];
        yield 'una búsqueda muy larga' => [['q' => str_repeat('a', 151)], 'La búsqueda puede tener hasta 150 caracteres'];
        yield 'una localidad como arreglo' => [['localidad' => ['Luján']], 'La localidad no es un texto válido'];
        yield 'una localidad muy larga' => [['localidad' => str_repeat('L', 101)], 'La localidad puede tener hasta 100 caracteres'];
        yield 'una fecha que no es fecha' => [['fecha' => 'mañana'], 'La fecha no es válida'];
        yield 'una fecha en otro formato' => [['fecha' => '24/10/2026'], 'La fecha no es válida'];
        yield 'un día que no existe' => [['fecha' => '2026-02-31'], 'La fecha no es válida'];
        yield 'una fecha con la hora' => [['fecha' => '2026-10-24 20:00'], 'La fecha no es válida'];
        yield 'una fecha como arreglo' => [['fecha' => ['2026-10-24']], 'La fecha no es válida'];
        yield 'un género que no es un id' => [['genero' => 'drama'], 'El género no es válido'];
        yield 'un género con una inyección' => [['genero' => "' OR 1=1 -- "], 'El género no es válido'];
        yield 'un género como arreglo' => [['genero' => [self::GENERO]], 'El género no es válido'];
    }

    /**
     * @param array<string, mixed> $consulta
     */
    #[DataProvider('filtrosInvalidos')]
    public function testUnFiltroInvalidoSeDejaAfueraConUnAviso(array $consulta, string $aviso): void
    {
        $parametros = ParametrosDeCartelera::desdeConsulta($consulta + ['localidad' => 'Mercedes']);

        self::assertCount(1, $parametros->avisos);
        self::assertStringStartsWith($aviso, $parametros->avisos[0]);
        // Los demás filtros siguen, y la página también.
        if (!isset($consulta['localidad'])) {
            self::assertSame(['localidad' => 'Mercedes'], $parametros->valores);
        }
        self::assertSame(1, $parametros->pagina);
    }

    public function testCadaFiltroInvalidoTieneSuAviso(): void
    {
        $parametros = ParametrosDeCartelera::desdeConsulta(['q' => ['x'], 'fecha' => 'x', 'genero' => 'x']);

        self::assertCount(3, $parametros->avisos);
        self::assertEquals(new FiltrosDeCartelera(), $parametros->filtros);
    }

    public function testLaBusquedaDeHasta150CaracteresSeAplica(): void
    {
        $texto = str_repeat('ñ', 150);

        self::assertSame($texto, ParametrosDeCartelera::desdeConsulta(['q' => $texto])->filtros->texto);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function paginasInvalidas(): iterable
    {
        yield 'texto' => ['abc'];
        yield 'cero' => ['0'];
        yield 'negativa' => ['-1'];
        yield 'con decimales' => ['1.5'];
        yield 'con un cero adelante' => ['01'];
        yield 'con espacios' => [' 2'];
        yield 'enorme' => ['99999999999999999999'];
        yield 'arreglo' => [['2']];
    }

    #[DataProvider('paginasInvalidas')]
    public function testUnaPaginaInvalidaEsNull(mixed $pagina): void
    {
        $parametros = ParametrosDeCartelera::desdeConsulta(['pagina' => $pagina]);

        self::assertNull($parametros->pagina);
        self::assertSame([], $parametros->avisos);
    }

    public function testLasUrlDePaginaConservanLosFiltros(): void
    {
        $parametros = ParametrosDeCartelera::desdeConsulta(['q' => 'el pibe & cía', 'localidad' => 'San Antonio de Areco', 'fecha' => 'x', 'pagina' => '2']);

        self::assertSame('/cartelera?q=el%20pibe%20%26%20c%C3%ADa&localidad=San%20Antonio%20de%20Areco&pagina=3', $parametros->urlDePagina(3));
        // La primera, sin el número; y el filtro inválido no viaja.
        self::assertSame('/cartelera?q=el%20pibe%20%26%20c%C3%ADa&localidad=San%20Antonio%20de%20Areco', $parametros->urlDePagina(1));
        self::assertSame('/cartelera?pagina=2', ParametrosDeCartelera::desdeConsulta([])->urlDePagina(2));
        self::assertSame('/cartelera', ParametrosDeCartelera::desdeConsulta([])->urlDePagina(1));
    }
}
