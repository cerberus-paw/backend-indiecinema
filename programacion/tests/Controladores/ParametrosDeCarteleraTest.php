<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Controladores;

use DateTimeImmutable;
use IndieCinema\Programacion\Controladores\ParametrosDeCartelera;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\Genero;
use IndieCinema\Programacion\Modelo\OrdenDeCartelera;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Los parámetros de la URL de la cartelera, que cualquiera puede escribir a mano: un filtro u orden
 * inválido se deja afuera con un aviso, y una página inválida queda en null para el 404.
 */
final class ParametrosDeCarteleraTest extends TestCase
{
    private const GENERO = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    /**
     * Con un único género, Drama. La base lo busca sin distinguir mayúsculas ni tildes (lo prueba
     * RepositorioDeGenerosTest); acá alcanza con las mayúsculas.
     *
     * @param array<string, mixed> $consulta
     */
    private static function parametros(array $consulta): ParametrosDeCartelera
    {
        return ParametrosDeCartelera::desdeConsulta(
            $consulta,
            static fn (string $nombre): ?Genero => mb_strtolower($nombre) === 'drama' ? new Genero(self::GENERO, 'Drama', 'Obras de drama.') : null,
        );
    }

    public function testSinParametrosEsLaPrimeraPaginaSinFiltros(): void
    {
        $parametros = self::parametros([]);

        self::assertEquals(new FiltrosDeCartelera(), $parametros->filtros);
        self::assertSame([[], [], 1], [$parametros->valores, $parametros->avisos, $parametros->pagina]);
    }

    public function testLosParametrosValidosSonLosFiltros(): void
    {
        $parametros = self::parametros([
            'q' => '  la ciénaga ',
            'localidad' => 'Luján',
            'fecha' => '2026-10-24',
            'genero' => ' drama ',
            'precio_min' => '0',
            'precio_max' => ' 2500 ',
            'orden' => 'precio_asc',
            'pagina' => '3',
        ]);

        // El género filtra por su id, y vuelve con el nombre como está guardado.
        self::assertEquals(
            new FiltrosDeCartelera(
                'la ciénaga',
                'Luján',
                new DateTimeImmutable('2026-10-24'),
                self::GENERO,
                orden: OrdenDeCartelera::PrecioAscendente,
                precioMinimo: 0.0,
                precioMaximo: 2500.0,
            ),
            $parametros->filtros,
        );
        self::assertSame(
            [
                'q' => 'la ciénaga',
                'localidad' => 'Luján',
                'fecha' => '2026-10-24',
                'genero' => 'Drama',
                'precio_min' => '0',
                'precio_max' => '2500',
                'orden' => 'precio_asc',
            ],
            $parametros->valores,
        );
        self::assertSame([], $parametros->avisos);
        self::assertSame(3, $parametros->pagina);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ?float, ?float, array<string, string>}>
     */
    public static function rangosDePrecioValidos(): iterable
    {
        yield 'sólo mínimo en 0 (incluye gratuitas)' => [['precio_min' => '0'], 0.0, null, ['precio_min' => '0']];
        yield 'sólo máximo en 0 (sólo gratuitas)' => [['precio_max' => '0'], null, 0.0, ['precio_max' => '0']];
        yield 'ambos en 0 (sólo gratuitas)' => [['precio_min' => '0', 'precio_max' => '0'], 0.0, 0.0, ['precio_min' => '0', 'precio_max' => '0']];
        yield 'rango entero' => [['precio_min' => '1500', 'precio_max' => '3500'], 1500.0, 3500.0, ['precio_min' => '1500', 'precio_max' => '3500']];
        yield 'con decimales y coma' => [['precio_min' => '1500,50', 'precio_max' => '2500.25'], 1500.5, 2500.25, ['precio_min' => '1500.5', 'precio_max' => '2500.25']];
        yield 'decimales en cero se normalizan a entero' => [['precio_min' => '0,00', 'precio_max' => '2000.00'], 0.0, 2000.0, ['precio_min' => '0', 'precio_max' => '2000']];
    }

    /**
     * @param array<string, mixed>  $consulta
     * @param array<string, string> $valoresEsperados
     */
    #[DataProvider('rangosDePrecioValidos')]
    public function testAceptaCadaRangoDePrecioValido(
        array $consulta,
        ?float $minimoEsperado,
        ?float $maximoEsperado,
        array $valoresEsperados,
    ): void {
        $parametros = self::parametros($consulta);

        self::assertSame($minimoEsperado, $parametros->filtros->precioMinimo);
        self::assertSame($maximoEsperado, $parametros->filtros->precioMaximo);
        self::assertSame($valoresEsperados, $parametros->valores);
        self::assertSame([], $parametros->avisos);
    }

    /**
     * @return iterable<string, array{string, OrdenDeCartelera, array<string, string>}>
     */
    public static function ordenesValidos(): iterable
    {
        yield 'fecha ascendente (por defecto, no viaja en valores)' => ['fecha_asc', OrdenDeCartelera::FechaAscendente, []];
        yield 'fecha descendente' => ['fecha_desc', OrdenDeCartelera::FechaDescendente, ['orden' => 'fecha_desc']];
        yield 'precio ascendente' => ['precio_asc', OrdenDeCartelera::PrecioAscendente, ['orden' => 'precio_asc']];
        yield 'precio descendente' => ['precio_desc', OrdenDeCartelera::PrecioDescendente, ['orden' => 'precio_desc']];
        yield 'título ascendente' => ['titulo_asc', OrdenDeCartelera::TituloAscendente, ['orden' => 'titulo_asc']];
        yield 'título descendente' => ['titulo_desc', OrdenDeCartelera::TituloDescendente, ['orden' => 'titulo_desc']];
        yield 'con guion y mayúsculas' => [' PRECIO-ASC ', OrdenDeCartelera::PrecioAscendente, ['orden' => 'precio_asc']];
    }

    /**
     * @param array<string, string> $valoresEsperados
     */
    #[DataProvider('ordenesValidos')]
    public function testAceptaCadaOrdenValido(string $valor, OrdenDeCartelera $esperado, array $valoresEsperados): void
    {
        $parametros = self::parametros(['orden' => $valor]);

        self::assertSame($esperado, $parametros->filtros->orden);
        self::assertSame($valoresEsperados, $parametros->valores);
        self::assertSame([], $parametros->avisos);
    }

    public function testLosVaciosNoFiltranNiAvisan(): void
    {
        $parametros = self::parametros([
            'q' => '',
            'localidad' => '   ',
            'fecha' => '',
            'genero' => '',
            'precio_min' => '',
            'precio_max' => '  ',
            'orden' => '  ',
            'pagina' => '',
        ]);

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
        yield 'un género que no existe' => [['genero' => 'Western'], 'El género no es válido'];
        yield 'el id de un género, como en las URL de antes' => [['genero' => self::GENERO], 'El género no es válido'];
        yield 'un género con una inyección' => [['genero' => "' OR 1=1 -- "], 'El género no es válido'];
        yield 'un género como arreglo' => [['genero' => ['Drama']], 'El género no es válido'];
        yield 'un género que no es UTF-8' => [['genero' => "\xC3\x28"], 'El género no es válido'];
        yield 'un género muy largo' => [['genero' => str_repeat('d', 51)], 'El género no es válido'];
        yield 'un precio mínimo negativo' => [['precio_min' => '-100'], 'El precio mínimo no es válido'];
        yield 'un precio mínimo con texto' => [['precio_min' => 'gratis'], 'El precio mínimo no es válido'];
        yield 'un precio mínimo con más de dos decimales' => [['precio_min' => '100.123'], 'El precio mínimo no es válido'];
        yield 'un precio mínimo enorme' => [['precio_min' => '100000000'], 'El precio mínimo no es válido'];
        yield 'un precio mínimo como arreglo' => [['precio_min' => ['0']], 'El precio mínimo no es válido'];
        yield 'un precio mínimo que no es UTF-8' => [['precio_min' => "\xC3\x28"], 'El precio mínimo no es válido'];
        yield 'un precio máximo negativo' => [['precio_max' => '-1'], 'El precio máximo no es válido'];
        yield 'un precio máximo con notación científica' => [['precio_max' => '1e5'], 'El precio máximo no es válido'];
        yield 'un precio máximo como arreglo' => [['precio_max' => ['2000']], 'El precio máximo no es válido'];
        yield 'un precio mínimo mayor que el máximo' => [['precio_min' => '3000', 'precio_max' => '2000'], 'El precio mínimo no puede ser mayor que el máximo'];
        yield 'un orden que no existe' => [['orden' => 'popularidad'], 'El orden no es válido'];
        yield 'un orden sin sentido' => [['orden' => 'precio'], 'El orden no es válido'];
        yield 'un orden con una inyección' => [['orden' => "precio_asc' OR 1=1 -- "], 'El orden no es válido'];
        yield 'un orden como arreglo' => [['orden' => ['precio_asc']], 'El orden no es válido'];
        yield 'un orden que no es UTF-8' => [['orden' => "\xC3\x28"], 'El orden no es válido'];
    }

    /**
     * @param array<string, mixed> $consulta
     */
    #[DataProvider('filtrosInvalidos')]
    public function testUnFiltroInvalidoSeDejaAfueraConUnAviso(array $consulta, string $aviso): void
    {
        $parametros = self::parametros($consulta + ['localidad' => 'Mercedes']);

        self::assertCount(1, $parametros->avisos);
        self::assertStringStartsWith($aviso, $parametros->avisos[0]);
        // Los demás filtros siguen, y la página también.
        if (!isset($consulta['localidad'])) {
            self::assertSame(['localidad' => 'Mercedes'], $parametros->valores);
        }
        self::assertSame(1, $parametros->pagina);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function generosQueNoLleganALaBase(): iterable
    {
        yield 'en blanco' => ['   '];
        yield 'como arreglo' => [['Drama']];
        yield 'que no es UTF-8' => ["\xC3\x28"];
        yield 'muy largo' => [str_repeat('d', 51)];
    }

    /**
     * Un género en blanco no filtra, y uno que no es un texto válido se descarta sin buscarlo.
     */
    #[DataProvider('generosQueNoLleganALaBase')]
    public function testUnGeneroQueNoSirveNoSeBuscaEnLaBase(mixed $genero): void
    {
        $parametros = ParametrosDeCartelera::desdeConsulta(['genero' => $genero], static function (): never {
            self::fail('Se buscó en la base un género que no sirve.');
        });

        self::assertNull($parametros->filtros->generoId);
        self::assertSame([], $parametros->valores);
    }

    public function testCadaFiltroInvalidoTieneSuAviso(): void
    {
        $parametros = self::parametros([
            'q' => ['x'],
            'fecha' => 'x',
            'genero' => 'x',
            'precio_min' => 'x',
            'precio_max' => 'x',
            'orden' => 'x',
        ]);

        self::assertCount(6, $parametros->avisos);
        self::assertEquals(new FiltrosDeCartelera(), $parametros->filtros);
    }

    public function testLaBusquedaDeHasta150CaracteresSeAplica(): void
    {
        $texto = str_repeat('ñ', 150);

        self::assertSame($texto, self::parametros(['q' => $texto])->filtros->texto);
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
        $parametros = self::parametros(['pagina' => $pagina]);

        self::assertNull($parametros->pagina);
        self::assertSame([], $parametros->avisos);
    }

    public function testLasUrlDePaginaConservanLosFiltrosYElOrden(): void
    {
        $parametros = self::parametros([
            'q' => 'el pibe & cía',
            'localidad' => 'San Antonio de Areco',
            'fecha' => 'x',
            'precio_min' => '0',
            'precio_max' => '2500',
            'orden' => 'titulo_desc',
            'pagina' => '2',
        ]);

        self::assertSame(
            '/cartelera?q=el%20pibe%20%26%20c%C3%ADa&localidad=San%20Antonio%20de%20Areco&precio_min=0&precio_max=2500&orden=titulo_desc&pagina=3',
            $parametros->urlDePagina(3),
        );
        // La primera, sin el número; y el filtro inválido no viaja.
        self::assertSame(
            '/cartelera?q=el%20pibe%20%26%20c%C3%ADa&localidad=San%20Antonio%20de%20Areco&precio_min=0&precio_max=2500&orden=titulo_desc',
            $parametros->urlDePagina(1),
        );
        self::assertSame('/cartelera?orden=precio_asc&pagina=2', self::parametros(['orden' => 'precio_asc'])->urlDePagina(2));
        self::assertSame('/cartelera?pagina=2', self::parametros(['orden' => 'fecha_asc'])->urlDePagina(2));
        self::assertSame('/cartelera?pagina=2', self::parametros([])->urlDePagina(2));
        self::assertSame('/cartelera', self::parametros([])->urlDePagina(1));
    }

    public function testLaUrlDelCsvConservaLosFiltrosYElOrdenYNoLlevaLaPagina(): void
    {
        $parametros = self::parametros([
            'q' => 'el pibe & cía',
            'localidad' => 'San Antonio de Areco',
            'fecha' => 'x',
            'precio_min' => '0',
            'precio_max' => '3000',
            'orden' => 'precio_desc',
            'pagina' => '2',
        ]);

        self::assertSame(
            '/cartelera.csv?q=el%20pibe%20%26%20c%C3%ADa&localidad=San%20Antonio%20de%20Areco&precio_min=0&precio_max=3000&orden=precio_desc',
            $parametros->urlDeCsv(),
        );
        self::assertSame('/cartelera.csv', self::parametros(['orden' => 'fecha_asc', 'pagina' => '3'])->urlDeCsv());
    }
}
