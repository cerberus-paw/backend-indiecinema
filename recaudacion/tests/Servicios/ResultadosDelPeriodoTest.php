<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\ObraVotada;
use IndieCinema\Recaudacion\Modelo\ParametroDeReparto;
use IndieCinema\Recaudacion\Modelo\Periodo;
use IndieCinema\Recaudacion\Modelo\ReglaIncumplida;
use IndieCinema\Recaudacion\Modelo\Reparto;
use IndieCinema\Recaudacion\Modelo\ResultadoDeSala;
use IndieCinema\Recaudacion\Modelo\VentasDeFuncion;
use IndieCinema\Recaudacion\Pruebas\ConBaseDePruebas;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeCostosDeSalas;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeParametrosDeReparto;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeRepartos;
use IndieCinema\Recaudacion\Servicios\ResultadosDelPeriodo;
use IndieCinema\Recaudacion\Servicios\ServicioDeParametrosDeReparto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Si cada sala y la plataforma ganan o pierden plata (EF-22.1), contra un MySQL de verdad: el
 * reparto ya guardado, el que todavía no se hizo, el costo cargado y el de referencia.
 */
final class ResultadosDelPeriodoTest extends TestCase
{
    use ConBaseDePruebas;

    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const DUENA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const NORTE = '10000000-0000-4000-8000-000000000001';
    private const SUR = '10000000-0000-4000-8000-000000000002';

    private BaseDeDatos $base;

    private RepositorioDeRepartos $repartos;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
        $this->repartos = new RepositorioDeRepartos($this->base);
    }

    /**
     * Con la comisión al 10 %, $ 20.000 por función de referencia, y la plataforma a $ 3.000 por
     * mes de servidores y $ 27.000 de desarrollo: $ 1.000 por día.
     */
    private function resultados(): ResultadosDelPeriodo
    {
        return new ResultadosDelPeriodo(
            $this->repartos,
            new RepositorioDeCostosDeSalas($this->base),
            new ServicioDeParametrosDeReparto(new RepositorioDeParametrosDeReparto($this->base)),
            new Configuracion([
                'reparto' => ['comision_mercadopago' => 10],
                'reportes' => ['costo_funcion' => 20000, 'plataforma_servidores_mes' => 3000, 'plataforma_desarrollo_mes' => 27000],
            ]),
        );
    }

    private static function funcion(string $id, string $salaId, string $salaNombre, string $fecha, string $precio = '2500.00'): FuncionFinalizada
    {
        return new FuncionFinalizada(
            $id,
            new DateTimeImmutable($fecha),
            new DateTimeImmutable($fecha . ' +3 hours'),
            50,
            $precio,
            $salaId,
            $salaNombre,
            self::ORGANIZADORA,
            [new ObraVotada('20000000-0000-4000-8000-000000000001', 'Zama', self::DUENA, true, 0)],
            [],
        );
    }

    /**
     * @param list<FuncionFinalizada>      $funciones
     * @param array<string, string>        $cobrado   por función, lo que dice funciones
     */
    private static function datos(array $funciones, array $cobrado, string $desde = '2026-11-01', string $hasta = '2026-11-30'): DatosDelReporte
    {
        return new DatosDelReporte(
            Periodo::entre($desde, $hasta),
            $funciones,
            array_map(static fn (string $monto): VentasDeFuncion => new VentasDeFuncion(10, 8, $monto, []), $cobrado),
            [],
        );
    }

    private function repartir(FuncionFinalizada $funcion, int $centavos, int $comision): void
    {
        $parametro = new ParametroDeReparto('3b7c9a10-5f41-4a8e-9c2d-6e0f1a2b3c4d', 7000, 1500, 1500, new DateTimeImmutable('2000-01-01'), null, new DateTimeImmutable('2000-01-01'));
        $this->repartos->guardar(Reparto::calcular($funcion, $centavos, $parametro, comisionCentesimos: $comision));
    }

    public function testLaSalaGanaSiSuParteSuperaLoQueLeCuestaAbrir(): void
    {
        $norte = self::funcion('50000000-0000-4000-8000-000000000001', self::NORTE, 'Norte', '2026-11-05 21:00');
        // $ 100.000 con el 10 % de comisión: quedan $ 90.000, y a la sala, el 70 %: $ 63.000.
        $this->repartir($norte, 10_000_000, 1000);

        [$sala] = $this->resultados()->salas(self::datos([$norte], [$norte->id => '100000.00']));

        self::assertSame(10_000_000, $sala->cobrado);
        self::assertSame(1_000_000, $sala->comision);
        self::assertSame(6_300_000, $sala->sala);
        self::assertSame(1_350_000, $sala->duenos);
        self::assertSame(1_350_000, $sala->plataforma);
        self::assertSame(2_000_000, $sala->costo());
        self::assertSame(4_300_000, $sala->resultado());
        self::assertFalse($sala->costoPropio);
    }

    public function testConElCostoQueCargoLaSalaPierde(): void
    {
        $norte = self::funcion('50000000-0000-4000-8000-000000000001', self::NORTE, 'Norte', '2026-11-05 21:00');
        $this->repartir($norte, 10_000_000, 1000);
        $resultados = $this->resultados();

        $resultados->cargarCosto(self::NORTE, '70.000', self::ORGANIZADORA);
        [$sala] = $resultados->salas(self::datos([$norte], [$norte->id => '100000.00']));

        self::assertTrue($sala->costoPropio);
        self::assertSame(7_000_000, $sala->costoFuncion);
        self::assertSame(-700_000, $sala->resultado());
    }

    public function testUnaFuncionSinRepartirSeCalculaConLoCobradoYLaComision(): void
    {
        $norte = self::funcion('50000000-0000-4000-8000-000000000001', self::NORTE, 'Norte', '2026-11-05 21:00');

        [$sala] = $this->resultados()->salas(self::datos([$norte], [$norte->id => '100000.00']));

        self::assertSame(1, $sala->estimadas);
        self::assertSame(1_000_000, $sala->comision);
        self::assertSame(6_300_000, $sala->sala);
    }

    public function testALaGorraCuentaElCostoSinPlataYLaSalaQueMasPierdeVaPrimero(): void
    {
        $norte = self::funcion('50000000-0000-4000-8000-000000000001', self::NORTE, 'Norte', '2026-11-05 21:00');
        $gorra = self::funcion('50000000-0000-4000-8000-000000000002', self::SUR, 'Sur', '2026-11-06 21:00', '0.00');
        $this->repartir($norte, 10_000_000, 1000);
        $this->repartos->guardar(Reparto::sinPlata($gorra));

        $salas = $this->resultados()->salas(self::datos([$norte, $gorra], [$norte->id => '100000.00', $gorra->id => '0.00']));

        self::assertSame(['Sur', 'Norte'], array_map(static fn (ResultadoDeSala $sala): string => $sala->salaNombre, $salas));
        self::assertSame(-2_000_000, $salas[0]->resultado());
    }

    public function testSinLoQueDijoFuncionesLaFuncionNoSumaNiCuesta(): void
    {
        $norte = self::funcion('50000000-0000-4000-8000-000000000001', self::NORTE, 'Norte', '2026-11-05 21:00');

        [$sala] = $this->resultados()->salas(self::datos([$norte], []));

        self::assertSame(1, $sala->sinDatos);
        self::assertSame(0, $sala->costo());
        self::assertSame(0, $sala->resultado());
    }

    public function testLaPlataformaComparaSuParteConLosDiasDelPeriodo(): void
    {
        $norte = self::funcion('50000000-0000-4000-8000-000000000001', self::NORTE, 'Norte', '2026-11-05 21:00');
        $this->repartir($norte, 10_000_000, 1000);
        $resultados = $this->resultados();
        $datos = self::datos([$norte], [$norte->id => '100000.00']);

        $plataforma = $resultados->plataforma($datos, $resultados->salas($datos));

        // Noviembre entero: 30 días a $ 1.000, contra su parte de $ 13.500.
        self::assertNotNull($plataforma);
        self::assertSame(30, $plataforma->dias);
        self::assertSame(3_000_000, $plataforma->costo());
        self::assertSame(1_350_000 - 3_000_000, $plataforma->resultado());
    }

    public function testSinPrimerDiaLaPlataformaCuentaDesdeLaPrimeraFuncion(): void
    {
        $norte = self::funcion('50000000-0000-4000-8000-000000000001', self::NORTE, 'Norte', '2026-11-21 21:00');
        $resultados = $this->resultados();
        $datos = self::datos([$norte], [$norte->id => '100000.00'], '', '2026-11-30');

        self::assertSame(10, $resultados->plataforma($datos, $resultados->salas($datos))?->dias);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function costosInvalidos(): iterable
    {
        yield 'vacío' => [''];
        yield 'con letras' => ['cuarenta mil'];
        yield 'negativo' => ['-5000'];
        yield 'demasiado' => ['10000001'];
    }

    #[DataProvider('costosInvalidos')]
    public function testUnCostoQueNoEsUnMontoNoSeGuarda(string $costo): void
    {
        $this->expectException(ReglaIncumplida::class);

        $this->resultados()->cargarCosto(self::NORTE, $costo, self::ORGANIZADORA);
    }
}
