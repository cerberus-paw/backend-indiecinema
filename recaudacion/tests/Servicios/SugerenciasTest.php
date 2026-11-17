<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Recaudacion\Modelo\CalificacionDeFuncion;
use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\ObraVotada;
use IndieCinema\Recaudacion\Modelo\Periodo;
use IndieCinema\Recaudacion\Modelo\Umbrales;
use IndieCinema\Recaudacion\Modelo\VentasDeFuncion;
use IndieCinema\Recaudacion\Servicios\Sugerencias;
use PHPUnit\Framework\TestCase;

/**
 * Las sugerencias del cruce (EF-09.1), sin red: cada umbral de [reportes] con su borde, con los
 * valores del ejemplo (Umbrales sin argumentos).
 */
final class SugerenciasTest extends TestCase
{
    private const GALPON = 'sala-galpon';
    private const NORTE = 'sala-norte';

    /** @var list<FuncionFinalizada> */
    private array $funciones = [];

    /** @var array<string, VentasDeFuncion> */
    private array $ventas = [];

    /** @var array<string, CalificacionDeFuncion> */
    private array $calificaciones = [];

    /**
     * Una función de 40 lugares, con lo que vendió y cuántos fueron.
     *
     * @param list<ObraVotada> $obras
     * @param list<string>     $votantes
     * @param list<string>     $compradores
     */
    private function funcion(
        string $fecha,
        int $vendidas,
        int $asistentes = 0,
        array $obras = [],
        string $sala = self::GALPON,
        ?CalificacionDeFuncion $calificacion = null,
        array $votantes = [],
        array $compradores = [],
    ): void {
        $id = 'funcion-' . (count($this->funciones) + 1);
        $this->funciones[] = new FuncionFinalizada(
            $id,
            new DateTimeImmutable($fecha),
            40,
            $sala,
            $sala === self::GALPON ? 'El Galpón' : 'Cineclub Norte',
            'organizadora',
            $obras === [] ? [self::obra('Zama')] : $obras,
            $votantes,
        );
        $this->ventas[$id] = new VentasDeFuncion($vendidas, $asistentes, sprintf('%d.00', 3500 * $vendidas), $compradores);
        if ($calificacion !== null) {
            $this->calificaciones[$id] = $calificacion;
        }
    }

    private static function obra(string $titulo, bool $elegida = true, int $votos = 0): ObraVotada
    {
        return new ObraVotada('obra-' . $titulo, $titulo, 'duena', $elegida, $votos);
    }

    private function datos(bool $conVentas = true): DatosDelReporte
    {
        return new DatosDelReporte(
            Periodo::entre('2026-11-01', '2026-11-30'),
            $this->funciones,
            $conVentas ? $this->ventas : null,
            $conVentas ? $this->calificaciones : null,
        );
    }

    private static function sugerencias(): Sugerencias
    {
        return new Sugerencias(new Umbrales());
    }

    // Días flojos: 2026-11-04 y 2026-11-11 son miércoles; 2026-11-07 y 2026-11-14, sábados.

    public function testUnDiaQueVendeLaMitadOMenosYMenosQueElRestoEsFlojo(): void
    {
        $this->funcion('2026-11-04 21:00', 20);
        $this->funcion('2026-11-11 21:00', 20);
        $this->funcion('2026-11-07 21:00', 38);
        $this->funcion('2026-11-14 21:00', 36);

        $flojos = self::sugerencias()->diasFlojos($this->datos());

        self::assertCount(1, $flojos);
        self::assertSame([self::GALPON, 3, 2, 50, 93], [$flojos[0]->salaId, $flojos[0]->dia, $flojos[0]->funciones, $flojos[0]->ocupacion, $flojos[0]->ocupacionResto]);
    }

    public function testUnLugarMasQueLaMitadYaNoEsFlojo(): void
    {
        $this->funcion('2026-11-04 21:00', 21);
        $this->funcion('2026-11-11 21:00', 20);
        $this->funcion('2026-11-07 21:00', 38);

        self::assertSame([], self::sugerencias()->diasFlojos($this->datos()));
    }

    public function testConUnaSolaFuncionEseDiaPuedeSerCasualidad(): void
    {
        $this->funcion('2026-11-04 21:00', 2);
        $this->funcion('2026-11-07 21:00', 38);

        self::assertSame([], self::sugerencias()->diasFlojos($this->datos()));
    }

    public function testSiElRestoDeLaSemanaVendeIgualNoHayDiaFlojo(): void
    {
        $this->funcion('2026-11-04 21:00', 10);
        $this->funcion('2026-11-11 21:00', 10);
        $this->funcion('2026-11-07 21:00', 10);

        self::assertSame([], self::sugerencias()->diasFlojos($this->datos()));
    }

    public function testUnaSalaQueSoloDaFuncionesEseDiaTambienPuedeSerFloja(): void
    {
        $this->funcion('2026-11-04 21:00', 5, sala: self::NORTE);
        $this->funcion('2026-11-11 21:00', 5, sala: self::NORTE);

        $flojos = self::sugerencias()->diasFlojos($this->datos());

        self::assertSame([13, null], [$flojos[0]->ocupacion, $flojos[0]->ocupacionResto]);
    }

    public function testCadaSalaSeComparaConsigoMisma(): void
    {
        $this->funcion('2026-11-04 21:00', 10);
        $this->funcion('2026-11-11 21:00', 10);
        $this->funcion('2026-11-07 21:00', 38);
        // El Norte vende poco todos los días: no tiene un día peor que los demás.
        $this->funcion('2026-11-04 21:00', 30, sala: self::NORTE);
        $this->funcion('2026-11-11 21:00', 30, sala: self::NORTE);

        self::assertSame([self::GALPON], array_map(static fn ($dia): string => $dia->salaId, self::sugerencias()->diasFlojos($this->datos())));
    }

    // Títulos para repetir: 90 % de ocupación, 80 % de asistencia y 4 de promedio.

    public function testUnTituloQueLlegaJustoALosTresBordesSeRepite(): void
    {
        $this->funcion('2026-11-07 21:00', 36, 29, [self::obra('Rojo')], calificacion: new CalificacionDeFuncion(4.0, 5));

        $titulos = self::sugerencias()->titulosParaRepetir($this->datos());

        self::assertCount(1, $titulos);
        self::assertSame(['Rojo', 1, 90, 81, 4.0], [$titulos[0]->titulo, $titulos[0]->funciones, $titulos[0]->ocupacion, $titulos[0]->asistencia, $titulos[0]->promedio]);
    }

    public function testUnLugarMenosQueEl90PorCientoNoSeRepite(): void
    {
        $this->funcion('2026-11-07 21:00', 35, 35, [self::obra('Rojo')], calificacion: new CalificacionDeFuncion(5.0, 5));

        self::assertSame([], self::sugerencias()->titulosParaRepetir($this->datos()));
    }

    public function testSiFueMenosDel80PorCientoDeLosQueReservaronNoSeRepite(): void
    {
        $this->funcion('2026-11-07 21:00', 40, 32, [self::obra('Rojo')], calificacion: new CalificacionDeFuncion(5.0, 5));
        $this->funcion('2026-11-14 21:00', 40, 31, [self::obra('Zama')], calificacion: new CalificacionDeFuncion(5.0, 5));

        self::assertSame(['Rojo'], array_map(static fn ($titulo): string => $titulo->titulo, self::sugerencias()->titulosParaRepetir($this->datos())));
    }

    public function testConPromedioDebajoDe4OSinCalificacionesNoSeRepite(): void
    {
        $this->funcion('2026-11-07 21:00', 40, 40, [self::obra('Rojo')], calificacion: new CalificacionDeFuncion(3.9, 5));
        $this->funcion('2026-11-14 21:00', 40, 40, [self::obra('Zama')]);

        self::assertSame([], self::sugerencias()->titulosParaRepetir($this->datos()));
    }

    public function testUnTituloSeSumaEnTodasSusFuncionesConElPromedioPonderado(): void
    {
        $this->funcion('2026-11-07 21:00', 40, 40, [self::obra('Rojo')], calificacion: new CalificacionDeFuncion(5.0, 9));
        // Sola, no llegaría al 90 % ni al promedio; sumada, sí: 74 de 80 y (45 + 3) / 10.
        $this->funcion('2026-11-14 21:00', 34, 34, [self::obra('Rojo')], calificacion: new CalificacionDeFuncion(3.0, 1));

        $titulos = self::sugerencias()->titulosParaRepetir($this->datos());

        self::assertSame([2, 93, 100, 4.8], [$titulos[0]->funciones, $titulos[0]->ocupacion, $titulos[0]->asistencia, $titulos[0]->promedio]);
    }

    // Lo que el público pidió y no vio: perdió con el 30 % de los votos o más.

    public function testUnaObraQuePerdioConEl30PorCientoEsUnPedido(): void
    {
        $this->funcion('2026-11-04 21:00', 10, obras: [self::obra('Zama', true, 7), self::obra('La ciénaga', false, 3)]);
        $this->funcion('2026-11-11 21:00', 10, obras: [self::obra('Zama', true, 8), self::obra('Rojo', false, 2)]);

        $pedidos = self::sugerencias()->pedidosDelPublico($this->datos());

        self::assertCount(1, $pedidos);
        self::assertSame(['La ciénaga', 1, 3, 30], [$pedidos[0]->titulo, $pedidos[0]->votaciones, $pedidos[0]->votos, $pedidos[0]->porcentaje]);
    }

    public function testLosVotosDeUnaObraSeSumanEnTodasLasVotacionesQuePerdio(): void
    {
        $this->funcion('2026-11-04 21:00', 10, obras: [self::obra('Zama', true, 6), self::obra('La ciénaga', false, 4)]);
        $this->funcion('2026-11-11 21:00', 10, obras: [self::obra('Rojo', true, 5), self::obra('La ciénaga', false, 5)]);

        $pedidos = self::sugerencias()->pedidosDelPublico($this->datos());

        self::assertSame(['La ciénaga', 2, 9, 45], [$pedidos[0]->titulo, $pedidos[0]->votaciones, $pedidos[0]->votos, $pedidos[0]->porcentaje]);
    }

    public function testUnaObraQueSeProyectoEnOtraFuncionNoEsUnPedido(): void
    {
        $this->funcion('2026-11-04 21:00', 10, obras: [self::obra('Zama', true, 6), self::obra('La ciénaga', false, 4)]);
        $this->funcion('2026-11-11 21:00', 10, obras: [self::obra('La ciénaga')]);

        self::assertSame([], self::sugerencias()->pedidosDelPublico($this->datos()));
    }

    public function testLosPedidosSalenAunqueFuncionesNoResponda(): void
    {
        $this->funcion('2026-11-04 21:00', 10, obras: [self::obra('Zama', true, 6), self::obra('La ciénaga', false, 4)]);
        $datos = $this->datos(conVentas: false);

        self::assertCount(1, self::sugerencias()->pedidosDelPublico($datos));
        self::assertSame([], self::sugerencias()->diasFlojos($datos));
        self::assertSame([], self::sugerencias()->titulosParaRepetir($datos));
        self::assertSame([], self::sugerencias()->salasQueRinden($datos));
        self::assertNull(self::sugerencias()->total($datos));
    }

    // Salas que más rinden.

    public function testLasSalasSeOrdenanPorOcupacionYDespuesPorAsistencia(): void
    {
        $this->funcion('2026-11-04 21:00', 20, 10, calificacion: new CalificacionDeFuncion(4.0, 2));
        $this->funcion('2026-11-04 21:00', 20, 20, sala: self::NORTE);

        $salas = self::sugerencias()->salasQueRinden($this->datos());

        self::assertSame([self::NORTE, self::GALPON], [$salas[0]->salaId, $salas[1]->salaId]);
        self::assertSame([1, 50, 100, null, null], [$salas[0]->funciones, $salas[0]->ocupacion, $salas[0]->asistencia, $salas[0]->promedio, $salas[0]->conversion]);
        self::assertSame(4.0, $salas[1]->promedio);
    }

    public function testCuentaCuantosDeLosQueVotaronReservaron(): void
    {
        $this->funcion('2026-11-04 21:00', 3, votantes: ['ana', 'beto', 'carla', 'dani'], compradores: ['ana', 'eva', 'beto']);

        self::assertSame(50, self::sugerencias()->salasQueRinden($this->datos())[0]->conversion);
    }

    public function testElTotalSumaTodasLasFunciones(): void
    {
        $this->funcion('2026-11-04 21:00', 10, 10);
        $this->funcion('2026-11-04 21:00', 30, 15, sala: self::NORTE);

        $total = self::sugerencias()->total($this->datos());

        self::assertNotNull($total);
        self::assertSame([2, 50, 63], [$total->funciones, $total->ocupacion, $total->asistencia]);
    }
}
