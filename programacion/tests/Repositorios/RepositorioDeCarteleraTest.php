<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\ObraEnCartelera;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\PaginaDeCartelera;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Pruebas\ConBaseDePruebas;
use IndieCinema\Programacion\Repositorios\RepositorioDeCartelera;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La cartelera contra un MySQL de verdad: qué funciones entran, cada filtro solo y combinados, el
 * paginado, y que lo que escribe el visitante no cambie la consulta.
 *
 * Entran cuatro funciones, que las pruebas nombran por su obra; las demás están para que alguna
 * regla las deje afuera.
 */
final class RepositorioDeCarteleraTest extends TestCase
{
    use ConBaseDePruebas;

    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    /** Cuando se programaron las funciones. */
    private const ANTES = '2026-10-01 12:00:00';
    /** Cuando se consulta la cartelera. */
    private const AHORA = '2026-10-11 18:00:00';

    private BaseDeDatos $base;
    private RepositorioDeSalas $salas;
    private RepositorioDeFunciones $funciones;
    private RepositorioDeCartelera $cartelera;

    /** @var array<string, string> el id de cada género, por nombre */
    private array $generos = [];

    /** @var array<string, string> el id de cada obra, por título */
    private array $obras = [];

    /** @var array<string, string> el id de cada función que entra en la cartelera, por su nombre en las pruebas */
    private array $enCartelera = [];

    /** @var array<string, string> el id de cada sala, por nombre */
    private array $salaIds = [];

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
        $this->salas = new RepositorioDeSalas($this->base);
        $this->funciones = new RepositorioDeFunciones($this->base);
        $this->cartelera = new RepositorioDeCartelera($this->base);

        foreach (['Drama', 'Comedia'] as $genero) {
            $this->generos[$genero] = Uuid::nuevo();
            $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$this->generos[$genero], $genero, "Obras de {$genero}."]);
        }
        $this->obra('La ciénaga', 'Lucrecia Martel', 'Drama', 'af1c0001000000000000000000000001.jpg');
        $this->obra('Zama', 'Lucrecia Martel', 'Drama');
        $this->obra('Historias extraordinarias', 'Mariano Llinás', 'Drama');
        $this->obra('Esperando la carroza', 'Alejandro Doria', 'Comedia');
        $this->obra('Relatos salvajes', 'Damián Szifron', 'Comedia');
        $this->obra('Cine al 100%', 'Ana Pérez', 'Comedia');

        $galpon = $this->salaHabilitada('Cine Club El Galpón', 'Luján');
        $italiano = $this->salaHabilitada('Cine Teatro Italiano', 'Mercedes');

        // Las que entran, ordenadas por fecha: la primera y la segunda son del 12, en los dos
        // bordes del día, y la tercera arranca justo el 13.
        $this->enCartelera['cienaga'] = $this->programada($galpon, ['La ciénaga'], '2026-10-12 00:00:00')->id;
        $this->enCartelera['carroza'] = $this->programada($italiano, ['Esperando la carroza', 'Relatos salvajes'], '2026-10-12 23:59:00', 2000.0)->id;
        // Historias extraordinarias fue candidata y perdió la votación: no se proyecta.
        $this->enCartelera['zama'] = $this->programada($galpon, ['Zama'], '2026-10-13 00:00:00', perdedoras: ['Historias extraordinarias'])->id;
        $this->enCartelera['cien'] = $this->programada($italiano, ['Cine al 100%'], '2026-10-20 21:00:00')->id;

        // Ya pasó.
        $this->programada($galpon, ['Zama'], '2026-10-11 17:00:00');
        // Todavía sin fecha.
        $this->funciones->agregar($galpon->crearFuncion([$this->obras['La ciénaga']], 40, 3500.0));
        $enAcuerdo = $galpon->crearFuncion([$this->obras['Zama']], 40, 3500.0);
        $this->funciones->agregar($enAcuerdo);
        $enAcuerdo->cerrarVotacionTitulos([$this->obras['Zama']]);
        $this->funciones->guardar($enAcuerdo);
        // Cancelada después de programarla.
        $cancelada = $this->programada($galpon, ['La ciénaga'], '2026-10-14 20:00:00');
        $cancelada->cancelar();
        $this->funciones->guardar($cancelada);
        // De una sala que el administrador deshabilitó y de una dada de baja.
        $deshabilitada = $this->salaHabilitada('Sala Deshabilitada', 'San Andrés de Giles');
        $this->programada($deshabilitada, ['La ciénaga'], '2026-10-15 20:00:00');
        $this->base->ejecutar('UPDATE sala SET habilitada = FALSE WHERE id = ?', [$deshabilitada->id]);
        $deBaja = $this->salaHabilitada('Sala de Baja', 'Chivilcoy');
        $this->programada($deBaja, ['La ciénaga'], '2026-10-15 20:00:00');
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$deBaja->id]);
    }

    private function obra(string $titulo, string $director, string $genero, ?string $afiche = null): void
    {
        $obra = Obra::nueva(self::ORGANIZADORA, $titulo, 'Sinopsis.', 2001, $director, 103, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$this->generos[$genero]], $afiche);
        (new RepositorioDeObras($this->base))->agregar($obra);
        $this->obras[$titulo] = $obra->id;
    }

    private function salaHabilitada(string $nombre, string $localidad): Sala
    {
        $sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala($nombre, 'Una sala.', 'Av. San Martín 450', $localidad, 60, 2, 120, 15), 'sala.jpg');
        $this->salas->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        $this->salaIds[$nombre] = $sala->id;

        return $this->salas->buscar($sala->id) ?? self::fail('No se guardó la sala.');
    }

    /**
     * Una función que pasó la votación (con las elegidas y, entre las candidatas, las perdedoras)
     * y el acuerdo de fecha.
     *
     * @param list<string> $elegidas   títulos
     * @param list<string> $perdedoras títulos
     */
    private function programada(Sala $sala, array $elegidas, string $fecha, float $precio = 3500.0, array $perdedoras = []): Funcion
    {
        $ids = fn (array $titulos): array => array_map(fn (string $titulo): string => $this->obras[$titulo], $titulos);
        $funcion = $sala->crearFuncion([...$ids($elegidas), ...$ids($perdedoras)], 40, $precio);
        $this->funciones->agregar($funcion);
        $funcion->cerrarVotacionTitulos($ids($elegidas));
        $this->funciones->guardar($funcion);
        $funcion->fijarFechaHora(new DateTimeImmutable($fecha), new DateTimeImmutable(self::ANTES));
        $this->funciones->guardar($funcion);

        return $funcion;
    }

    private function pagina(FiltrosDeCartelera $filtros = new FiltrosDeCartelera(), int $numero = 1, int $tamano = 10): PaginaDeCartelera
    {
        return $this->cartelera->pagina($filtros, $numero, $tamano, new DateTimeImmutable(self::AHORA));
    }

    /**
     * @return list<FuncionEnCartelera>
     */
    private function todas(FiltrosDeCartelera $filtros = new FiltrosDeCartelera()): array
    {
        return $this->cartelera->todas($filtros, new DateTimeImmutable(self::AHORA));
    }

    /**
     * @param PaginaDeCartelera|list<FuncionEnCartelera> $funciones
     *
     * @return list<string> los nombres de las funciones, en su orden
     */
    private function nombres(PaginaDeCartelera|array $funciones): array
    {
        $nombres = array_flip($this->enCartelera);
        $funciones = $funciones instanceof PaginaDeCartelera ? $funciones->funciones : $funciones;

        return array_map(static fn (FuncionEnCartelera $funcion): string => $nombres[$funcion->id] ?? $funcion->id, $funciones);
    }

    public function testListaLasProgramadasQueNoPasaronDeSalasPublicadasPorFecha(): void
    {
        $pagina = $this->pagina();

        self::assertSame(['cienaga', 'carroza', 'zama', 'cien'], $this->nombres($pagina));
        self::assertSame(4, $pagina->total);
    }

    public function testCadaFuncionTraeSusObrasLaSalaLaLocalidadLaFechaYElPrecio(): void
    {
        [$cienaga, $carroza] = $this->pagina()->funciones;

        self::assertEquals(new FuncionEnCartelera(
            $this->enCartelera['carroza'],
            new DateTimeImmutable('2026-10-12 23:59:00'),
            2000.0,
            $carroza->salaId,
            'Cine Teatro Italiano',
            'Mercedes',
            [
                new ObraEnCartelera($this->obras['Esperando la carroza'], 'Esperando la carroza', null),
                new ObraEnCartelera($this->obras['Relatos salvajes'], 'Relatos salvajes', null),
            ],
        ), $carroza);
        self::assertEquals([new ObraEnCartelera($this->obras['La ciénaga'], 'La ciénaga', 'af1c0001000000000000000000000001.jpg')], $cienaga->obras);
        self::assertSame('Cine Club El Galpón', $cienaga->sala);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function textos(): iterable
    {
        yield 'el título' => ['ciénaga', ['cienaga']];
        yield 'sin mayúsculas ni tildes' => ['CIENAGA', ['cienaga']];
        yield 'una parte del título de una de sus obras' => ['salvaj', ['carroza']];
        yield 'el director' => ['martel', ['cienaga', 'zama']];
        yield 'el nombre de la sala' => ['teatro italiano', ['carroza', 'cien']];
        yield 'con espacios alrededor' => ['  Zama ', ['zama']];
        yield 'una candidata que perdió la votación' => ['Historias extraordinarias', []];
        yield 'una obra que no está' => ['Nueve reinas', []];
    }

    /**
     * @param list<string> $esperadas
     */
    #[DataProvider('textos')]
    public function testBuscaPorTexto(string $texto, array $esperadas): void
    {
        self::assertSame($esperadas, $this->nombres($this->pagina(new FiltrosDeCartelera(texto: $texto))));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function localidades(): iterable
    {
        yield 'una localidad' => ['Mercedes', ['carroza', 'cien']];
        yield 'sin mayúsculas ni tildes' => ['lujan', ['cienaga', 'zama']];
        yield 'una parte no alcanza' => ['Lu', []];
        yield 'la de una sala deshabilitada' => ['San Andrés de Giles', []];
        yield 'la de una sala dada de baja' => ['Chivilcoy', []];
    }

    /**
     * @param list<string> $esperadas
     */
    #[DataProvider('localidades')]
    public function testFiltraPorLocalidad(string $localidad, array $esperadas): void
    {
        self::assertSame($esperadas, $this->nombres($this->pagina(new FiltrosDeCartelera(localidad: $localidad))));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function fechas(): iterable
    {
        yield 'el día entero, de las 00:00 a las 23:59' => ['2026-10-12', ['cienaga', 'carroza']];
        yield 'la hora no cuenta' => ['2026-10-12 15:00:00', ['cienaga', 'carroza']];
        yield 'la que arranca a las 00:00 es del día siguiente' => ['2026-10-13', ['zama']];
        yield 'hoy, con la función que ya pasó' => ['2026-10-11', []];
        yield 'un día sin funciones' => ['2026-10-30', []];
    }

    /**
     * @param list<string> $esperadas
     */
    #[DataProvider('fechas')]
    public function testFiltraPorFecha(string $fecha, array $esperadas): void
    {
        self::assertSame($esperadas, $this->nombres($this->pagina(new FiltrosDeCartelera(fecha: new DateTimeImmutable($fecha)))));
    }

    public function testFiltraPorGenero(): void
    {
        self::assertSame(['carroza', 'cien'], $this->nombres($this->pagina(new FiltrosDeCartelera(generoId: $this->generos['Comedia']))));
        self::assertSame(['cienaga', 'zama'], $this->nombres($this->pagina(new FiltrosDeCartelera(generoId: $this->generos['Drama']))));
        self::assertSame([], $this->nombres($this->pagina(new FiltrosDeCartelera(generoId: Uuid::nuevo()))));
    }

    public function testUnGeneroDeUnaCandidataQuePerdioNoCuenta(): void
    {
        $terror = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$terror, 'Terror', 'Obras de terror.']);
        $this->base->ejecutar('INSERT INTO obra_genero (obra_id, genero_id) VALUES (?, ?)', [$this->obras['Historias extraordinarias'], $terror]);

        self::assertSame([], $this->nombres($this->pagina(new FiltrosDeCartelera(generoId: $terror))));
    }

    public function testFiltraPorSala(): void
    {
        self::assertSame(['cienaga', 'zama'], $this->nombres($this->todas(new FiltrosDeCartelera(salaId: $this->salaIds['Cine Club El Galpón']))));
        self::assertSame(['carroza', 'cien'], $this->nombres($this->todas(new FiltrosDeCartelera(salaId: $this->salaIds['Cine Teatro Italiano']))));
        // Las que no están en la cartelera tampoco están en la ficha de la sala.
        self::assertSame([], $this->todas(new FiltrosDeCartelera(salaId: $this->salaIds['Sala Deshabilitada'])));
        self::assertSame([], $this->todas(new FiltrosDeCartelera(salaId: $this->salaIds['Sala de Baja'])));
        self::assertSame([], $this->todas(new FiltrosDeCartelera(salaId: Uuid::nuevo())));
        self::assertSame(['zama'], $this->nombres($this->todas(new FiltrosDeCartelera(fecha: new DateTimeImmutable('2026-10-13'), salaId: $this->salaIds['Cine Club El Galpón']))));
    }

    public function testLosFiltrosSeCumplenTodosALaVez(): void
    {
        $comedia = $this->generos['Comedia'];

        self::assertSame(['cien'], $this->nombres($this->pagina(new FiltrosDeCartelera('100', 'Mercedes', new DateTimeImmutable('2026-10-20'), $comedia))));
        self::assertSame(['carroza'], $this->nombres($this->pagina(new FiltrosDeCartelera(localidad: 'Mercedes', fecha: new DateTimeImmutable('2026-10-12')))));
        self::assertSame([], $this->nombres($this->pagina(new FiltrosDeCartelera(texto: 'martel', generoId: $comedia))));
        self::assertSame([], $this->nombres($this->pagina(new FiltrosDeCartelera(texto: 'zama', localidad: 'Mercedes'))));
    }

    public function testPaginaConElTamanoPedido(): void
    {
        $primera = $this->pagina(tamano: 3);
        $segunda = $this->pagina(numero: 2, tamano: 3);

        self::assertSame(['cienaga', 'carroza', 'zama'], $this->nombres($primera));
        self::assertSame(['cien'], $this->nombres($segunda));
        self::assertSame([4, 2, true], [$segunda->total, $segunda->totalDePaginas(), $segunda->existe()]);
        self::assertSame(['carroza'], $this->nombres($this->pagina(numero: 2, tamano: 1)));
    }

    public function testPaginaLoFiltrado(): void
    {
        $pagina = $this->pagina(new FiltrosDeCartelera(localidad: 'Mercedes'), 2, 1);

        self::assertSame(['cien'], $this->nombres($pagina));
        self::assertSame([2, 2], [$pagina->total, $pagina->totalDePaginas()]);
    }

    public function testUnaPaginaQueNoExisteVieneVacia(): void
    {
        foreach ([3, PHP_INT_MAX] as $numero) {
            $pagina = $this->pagina(numero: $numero, tamano: 2);

            self::assertFalse($pagina->existe());
            self::assertSame([], $pagina->funciones);
            self::assertSame(4, $pagina->total);
        }
    }

    public function testSinResultadosLaPrimeraPaginaExisteYLasDemasNo(): void
    {
        $filtros = new FiltrosDeCartelera(texto: 'Nueve reinas');

        $primera = $this->pagina($filtros);
        self::assertTrue($primera->existe());
        self::assertSame([[], 0, 1], [$primera->funciones, $primera->total, $primera->totalDePaginas()]);
        self::assertFalse($this->pagina($filtros, 2)->existe());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function paginasInvalidas(): iterable
    {
        yield 'la página 0' => [0, 10];
        yield 'una página negativa' => [-1, 10];
        yield 'un tamaño de 0' => [1, 0];
    }

    #[DataProvider('paginasInvalidas')]
    public function testUnNumeroOUnTamanoQueNoEsPositivoSeRechaza(int $numero, int $tamano): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->pagina(numero: $numero, tamano: $tamano);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function comodines(): iterable
    {
        yield 'el % del título' => ['100%', ['cien']];
        yield 'un % solo no es «cualquier cosa»' => ['%', ['cien']];
        yield 'un _ no es «cualquier letra»' => ['100_', []];
        yield 'un _ solo' => ['_', []];
        yield 'el carácter de escape' => ['!', []];
        yield 'el escape delante de un comodín' => ['!%', []];
        yield 'la barra' => ['\\', []];
    }

    /**
     * @param list<string> $esperadas
     */
    #[DataProvider('comodines')]
    public function testLosComodinesDeLikeSeBuscanTalCual(string $texto, array $esperadas): void
    {
        self::assertSame($esperadas, $this->nombres($this->pagina(new FiltrosDeCartelera(texto: $texto))));
    }

    /**
     * Lo que intentaría alguien que prueba la URL de la cartelera, por cada filtro de texto.
     *
     * @return iterable<string, array{FiltrosDeCartelera}>
     */
    public static function inyecciones(): iterable
    {
        yield 'texto que cierra la comilla' => [new FiltrosDeCartelera(texto: "' OR '1'='1")];
        yield 'texto que comenta el resto' => [new FiltrosDeCartelera(texto: "zama%' OR 1=1 -- ")];
        yield 'texto con UNION' => [new FiltrosDeCartelera(texto: "' UNION SELECT id, nombre, descripcion, id, id, id FROM genero -- ")];
        yield 'texto con otra sentencia' => [new FiltrosDeCartelera(texto: "'; DROP TABLE funcion; -- ")];
        yield 'texto con comillas dobles' => [new FiltrosDeCartelera(texto: '" OR ""="')];
        yield 'localidad que cierra la comilla' => [new FiltrosDeCartelera(localidad: "Luján' OR '1'='1")];
        yield 'localidad con otra sentencia' => [new FiltrosDeCartelera(localidad: "Mercedes'; DELETE FROM sala; #")];
        yield 'género que cierra la comilla' => [new FiltrosDeCartelera(generoId: "x' OR 1=1 -- ")];
        yield 'género con una subconsulta' => [new FiltrosDeCartelera(generoId: "' OR genero_id IN (SELECT id FROM genero) -- ")];
        yield 'sala que cierra la comilla' => [new FiltrosDeCartelera(salaId: "x' OR '1'='1")];
    }

    #[DataProvider('inyecciones')]
    public function testUnaInyeccionSqlSeBuscaComoTextoYNoCambiaLaConsulta(FiltrosDeCartelera $filtros): void
    {
        $pagina = $this->pagina($filtros);

        self::assertSame([], $pagina->funciones);
        self::assertSame(0, $pagina->total);
        // Las tablas siguen como estaban, y la cartelera sin filtros también.
        self::assertSame(10, $this->base->valor('SELECT COUNT(*) FROM funcion'));
        self::assertSame(4, $this->base->valor('SELECT COUNT(*) FROM sala'));
        self::assertSame(['cienaga', 'carroza', 'zama', 'cien'], $this->nombres($this->pagina()));
    }

    public function testTodasTraeLasMismasQueLasPaginasJuntas(): void
    {
        $todas = $this->todas();

        self::assertSame(['cienaga', 'carroza', 'zama', 'cien'], $this->nombres($todas));
        self::assertEquals([...$this->pagina(tamano: 2)->funciones, ...$this->pagina(numero: 2, tamano: 2)->funciones], $todas);
    }

    public function testTodasRespetaLosFiltros(): void
    {
        $comedia = $this->generos['Comedia'];

        self::assertSame(['carroza', 'cien'], $this->nombres($this->todas(new FiltrosDeCartelera(localidad: 'Mercedes'))));
        self::assertSame(['cienaga', 'zama'], $this->nombres($this->todas(new FiltrosDeCartelera(texto: 'martel'))));
        self::assertSame(['cienaga', 'carroza'], $this->nombres($this->todas(new FiltrosDeCartelera(fecha: new DateTimeImmutable('2026-10-12')))));
        self::assertSame(['carroza', 'cien'], $this->nombres($this->todas(new FiltrosDeCartelera(generoId: $comedia))));
        self::assertSame(['cien'], $this->nombres($this->todas(new FiltrosDeCartelera('100', 'Mercedes', new DateTimeImmutable('2026-10-20'), $comedia))));
    }

    public function testTodasSinResultadosEsUnaListaVacia(): void
    {
        self::assertSame([], $this->todas(new FiltrosDeCartelera(texto: 'Nueve reinas')));
        self::assertSame([], $this->todas(new FiltrosDeCartelera(texto: "' OR '1'='1")));
    }

    public function testLasLocalidadesDelFiltroSonLasQueTienenFuncionesEnCartelera(): void
    {
        self::assertSame(['Luján', 'Mercedes'], $this->cartelera->localidades(new DateTimeImmutable(self::AHORA)));
        // Después de la última función no queda ninguna.
        self::assertSame([], $this->cartelera->localidades(new DateTimeImmutable('2026-10-20 21:00:00')));
    }
}
