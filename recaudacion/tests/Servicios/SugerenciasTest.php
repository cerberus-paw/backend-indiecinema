<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Recaudacion\Modelo\CalificacionDeFuncion;
use IndieCinema\Recaudacion\Modelo\DatosDelReporte;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\FuncionParaProgramar;
use IndieCinema\Recaudacion\Modelo\GeneroDeSala;
use IndieCinema\Recaudacion\Modelo\ObraVotada;
use IndieCinema\Recaudacion\Modelo\Periodo;
use IndieCinema\Recaudacion\Modelo\RecomendacionCombinada;
use IndieCinema\Recaudacion\Modelo\ResultadoDeSala;
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
        string $precio = '3500.00',
    ): void {
        $id = 'funcion-' . (count($this->funciones) + 1);
        $this->funciones[] = new FuncionFinalizada(
            $id,
            new DateTimeImmutable($fecha),
            new DateTimeImmutable($fecha),
            40,
            $precio,
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

    /**
     * @param list<string> $generos
     */
    private static function obra(string $titulo, bool $elegida = true, int $votos = 0, array $generos = []): ObraVotada
    {
        return new ObraVotada('obra-' . $titulo, $titulo, 'duena', $elegida, $votos, $generos);
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
        // Nadie votó nada que perdiera: no hay qué sugerir para ese día.
        self::assertNull($flojos[0]->paraDar);
    }

    public function testElDiaFlojoSugiereLoQueElPublicoDeEsaSalaVotoYNoVio(): void
    {
        $this->funcion('2026-11-04 21:00', 10);
        $this->funcion('2026-11-11 21:00', 10);
        $this->funcion('2026-11-07 21:00', 38, obras: [self::obra('Zama', true, 6), self::obra('La ciénaga', false, 4)]);
        // En el Norte perdió una con más votos, pero ése es otro público.
        $this->funcion('2026-11-07 21:00', 38, obras: [self::obra('Zama', true, 5), self::obra('Rojo', false, 5)], sala: self::NORTE);

        $flojos = self::sugerencias()->diasFlojos($this->datos());

        self::assertSame(['La ciénaga', 1, 4, 40], [$flojos[0]->paraDar?->titulo, $flojos[0]->paraDar?->votaciones, $flojos[0]->paraDar?->votos, $flojos[0]->paraDar?->porcentaje]);
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

    // Votaron y no reservaron: 5 personas o más, contadas en todo el período de la sala.

    public function testCuentaLasPersonasQueVotaronYNoReservaronNingunaFuncionDeLaSala(): void
    {
        $this->funcion('2026-11-04 21:00', 3, votantes: ['ana', 'beto', 'carla', 'dani', 'eva', 'fede', 'gala'], compradores: ['ana']);
        // Beto votó la otra y reservó ésta: ya vino.
        $this->funcion('2026-11-11 21:00', 3, compradores: ['beto']);
        // En el Norte sólo son cuatro: no llega.
        $this->funcion('2026-11-04 21:00', 3, sala: self::NORTE, votantes: ['ana', 'beto', 'carla', 'dani']);

        $salas = self::sugerencias()->votaronSinReservar($this->datos());

        self::assertCount(1, $salas);
        self::assertSame([self::GALPON, 7, 5], [$salas[0]->salaId, $salas[0]->votantes, $salas[0]->sinReserva]);
    }

    // A la gorra o con precio: 20 puntos de diferencia y 2 funciones de cada tipo.

    public function testSiALaGorraLlenaMuchoMasElPublicoEsSensibleAlPrecio(): void
    {
        $this->funcion('2026-11-04 21:00', 36, precio: '0.00');
        $this->funcion('2026-11-11 21:00', 36, precio: '0.00');
        $this->funcion('2026-11-07 21:00', 28);
        $this->funcion('2026-11-14 21:00', 28);

        $precios = self::sugerencias()->precioDeLaEntrada($this->datos());

        self::assertCount(1, $precios);
        self::assertSame([2, 90, 2, 70, true], [$precios[0]->funcionesGorra, $precios[0]->ocupacionGorra, $precios[0]->funcionesPagas, $precios[0]->ocupacionPagas, $precios[0]->sensible]);
    }

    public function testConMenosDe20PuntosDeDiferenciaNoSeSugiereNada(): void
    {
        $this->funcion('2026-11-04 21:00', 36, precio: '0.00');
        $this->funcion('2026-11-11 21:00', 36, precio: '0.00');
        $this->funcion('2026-11-07 21:00', 29);
        $this->funcion('2026-11-14 21:00', 29);

        self::assertSame([], self::sugerencias()->precioDeLaEntrada($this->datos()));
    }

    public function testSiConPrecioLlenaIgualSePuedeCobrar(): void
    {
        $this->funcion('2026-11-04 21:00', 20, precio: '0.00');
        $this->funcion('2026-11-11 21:00', 20, precio: '0.00');
        $this->funcion('2026-11-07 21:00', 20);
        $this->funcion('2026-11-14 21:00', 21);

        $precios = self::sugerencias()->precioDeLaEntrada($this->datos());

        self::assertSame([50, 51, false], [$precios[0]->ocupacionGorra, $precios[0]->ocupacionPagas, $precios[0]->sensible]);
    }

    public function testConUnaSolaFuncionALaGorraNoSeCompara(): void
    {
        $this->funcion('2026-11-04 21:00', 40, precio: '0.00');
        $this->funcion('2026-11-07 21:00', 10);
        $this->funcion('2026-11-14 21:00', 10);

        self::assertSame([], self::sugerencias()->precioDeLaEntrada($this->datos()));
    }

    // Sumá una función: una función con el 95 % o más vendido se agotó.

    public function testUnaObraQueAgotoUnaFuncionEnUnaSalaPideOtra(): void
    {
        $this->funcion('2026-11-07 21:00', 38, obras: [self::obra('Rojo')]);
        $this->funcion('2026-11-14 21:00', 30, obras: [self::obra('Rojo')]);
        // 37 de 40 es el 92,5 %: no se agotó.
        $this->funcion('2026-11-07 21:00', 37, obras: [self::obra('Zama')], sala: self::NORTE);

        $obras = self::sugerencias()->obrasAgotadas($this->datos());

        self::assertCount(1, $obras);
        self::assertSame(['Rojo', self::GALPON, 2, 1, 85], [$obras[0]->titulo, $obras[0]->salaId, $obras[0]->funciones, $obras[0]->agotadas, $obras[0]->ocupacion]);
    }

    public function testSinVentasNoHayPrecioNiVotantesNiAgotadas(): void
    {
        $this->funcion('2026-11-04 21:00', 40, votantes: ['ana', 'beto', 'carla', 'dani', 'eva'], precio: '0.00');
        $datos = $this->datos(conVentas: false);

        self::assertSame([], self::sugerencias()->votaronSinReservar($datos));
        self::assertSame([], self::sugerencias()->precioDeLaEntrada($datos));
        self::assertSame([], self::sugerencias()->obrasAgotadas($datos));
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

    // Combinadas (EF-22.3): todas parten de un día flojo y le suman otra variable de la misma sala.

    public function testElDiaFlojoConUnPedidoYUnPublicoSensibleAlPrecioSugiereDarloALaGorra(): void
    {
        // Los miércoles, 20 de 80; los sábados, con precio, 60 de 80, y la votación de La ciénaga.
        $this->funcion('2026-11-04 21:00', 10);
        $this->funcion('2026-11-11 21:00', 10);
        $this->funcion('2026-11-07 21:00', 30, obras: [self::obra('Zama', true, 6), self::obra('La ciénaga', false, 4)]);
        $this->funcion('2026-11-14 21:00', 30);
        // Los viernes, a la gorra, llenó el 95 %.
        $this->funcion('2026-11-06 21:00', 38, precio: '0.00');
        $this->funcion('2026-11-13 21:00', 38, precio: '0.00');

        $combinadas = self::sugerencias()->combinadas($this->datos());

        self::assertCount(1, $combinadas);
        self::assertSame(RecomendacionCombinada::PEDIDO_A_LA_GORRA, $combinadas[0]->regla);
        self::assertSame('Los miércoles, dar «La ciénaga» a la gorra.', $combinadas[0]->accion);
        // Y lo que se programa con el botón (EF-22.17): esa obra, ese día, sin precio.
        self::assertEquals(new FuncionParaProgramar(self::GALPON, ['obra-La ciénaga'], 3, precio: 0), $combinadas[0]->programar);
        self::assertSame([
            'Los miércoles se vendió el 25 % de los lugares, contra el 85 % del resto de la semana (2 funciones).',
            'Su público lo votó y no lo vio: 4 votos en 1 votación, el 40 %.',
            'A la gorra ya funcionó en esta sala: llenó el 95 % en 2 funciones, contra el 50 % con precio.',
        ], $combinadas[0]->porque);
    }

    public function testSinUnPublicoSensibleAlPrecioNoSugiereLaGorra(): void
    {
        $this->funcion('2026-11-04 21:00', 10);
        $this->funcion('2026-11-11 21:00', 10);
        $this->funcion('2026-11-07 21:00', 30, obras: [self::obra('Zama', true, 6), self::obra('La ciénaga', false, 4)]);
        $this->funcion('2026-11-14 21:00', 30);

        self::assertSame([], self::sugerencias()->combinadas($this->datos()));
    }

    public function testSiElDiaFlojoEstaEnLaFranjaQueMenosLlenaSugiereOtroHorario(): void
    {
        // Las de la tarde, los miércoles: 20 de 80. Las de la noche, los sábados: 72 de 80.
        $this->funcion('2026-11-04 18:00', 10);
        $this->funcion('2026-11-11 18:00', 10);
        $this->funcion('2026-11-07 21:00', 36);
        $this->funcion('2026-11-14 21:00', 36);

        $combinadas = self::sugerencias()->combinadas($this->datos());

        self::assertCount(1, $combinadas);
        self::assertSame(RecomendacionCombinada::OTRO_HORARIO, $combinadas[0]->regla);
        self::assertSame('Los miércoles, pasar las funciones a la noche, desde las 20 h.', $combinadas[0]->accion);
        self::assertEquals(new FuncionParaProgramar(self::GALPON, [], 3, 'noche'), $combinadas[0]->programar);
        self::assertSame('En esta sala, las de la noche llenan el 90 % (2 funciones) y las de la tarde, el 25 % (2).', $combinadas[0]->porque[1]);
        self::assertSame('De las 2 funciones de los miércoles, 2 fueron a la tarde.', $combinadas[0]->porque[2]);
    }

    public function testElCorteDeLaFranjaSaleDeLosUmbrales(): void
    {
        $this->funcion('2026-11-04 18:00', 10);
        $this->funcion('2026-11-11 18:00', 10);
        $this->funcion('2026-11-07 21:00', 36);
        $this->funcion('2026-11-14 21:00', 36);

        // Con el corte a las 17, todas son de la noche: no hay dos franjas que comparar.
        $combinadas = (new Sugerencias(new Umbrales(franjaCorteHora: 17)))->combinadas($this->datos());

        self::assertSame([], $combinadas);
    }

    public function testLoQueSeAgotaYGustaSeSugiereElDiaFlojo(): void
    {
        $this->funcion('2026-11-04 21:00', 10);
        $this->funcion('2026-11-11 21:00', 10);
        $this->funcion('2026-11-07 21:00', 39, obras: [self::obra('Rojo')], calificacion: new CalificacionDeFuncion(4.5, 10));
        $this->funcion('2026-11-14 21:00', 30, obras: [self::obra('Rojo')]);

        $combinadas = self::sugerencias()->combinadas($this->datos());

        self::assertCount(1, $combinadas);
        self::assertSame(RecomendacionCombinada::AGOTADA_EL_DIA_FLOJO, $combinadas[0]->regla);
        self::assertSame('Sumar una función de «Rojo» los miércoles.', $combinadas[0]->accion);
        self::assertEquals(new FuncionParaProgramar(self::GALPON, ['obra-Rojo'], 3), $combinadas[0]->programar);
        self::assertSame([
            'Se agotó en 1 de sus 2 funciones en esta sala (86 % de los lugares vendidos): hay gente que se quedó afuera.',
            'Gustó: 4,5 de promedio.',
            'Los miércoles se vendió el 25 % de los lugares, contra el 86 % del resto de la semana (2 funciones).',
        ], $combinadas[0]->porque);
    }

    public function testLoQueSeAgotaPeroNoGustaNoSeSugiere(): void
    {
        $this->funcion('2026-11-04 21:00', 10);
        $this->funcion('2026-11-11 21:00', 10);
        $this->funcion('2026-11-07 21:00', 39, obras: [self::obra('Rojo')], calificacion: new CalificacionDeFuncion(3.5, 10));
        $this->funcion('2026-11-14 21:00', 30, obras: [self::obra('Rojo')]);

        self::assertSame([], self::sugerencias()->combinadas($this->datos()));
    }

    public function testSinDiaFlojoNoHayCombinadas(): void
    {
        $this->funcion('2026-11-07 21:00', 39, obras: [self::obra('Rojo')], calificacion: new CalificacionDeFuncion(5.0, 10));
        $this->funcion('2026-11-14 21:00', 39, obras: [self::obra('Rojo')]);

        self::assertSame([], self::sugerencias()->combinadas($this->datos()));
    }

    // La sala que gana holgado (EF-22.14): con 40 % o más sobre su costo, sin promociones.

    /**
     * El Galpón con cuatro funciones de $ 100 de costo ($ 400) y lo que se llevó la sala, en pesos.
     */
    private static function galponQueSeLlevo(int $pesos): ResultadoDeSala
    {
        return new ResultadoDeSala(self::GALPON, 'El Galpón', 4, 0, 0, $pesos * 100, 0, 0, 100_00, true);
    }

    /**
     * Los miércoles flojos, un pedido que perdió, los viernes a la gorra llenos y gente que votó y
     * no reservó: todo lo que sugiere una promoción en el Galpón.
     */
    private function galponConTodasLasPromociones(): void
    {
        $votantes = array_map(static fn (int $n): string => "votante-{$n}", range(1, 6));
        $this->funcion('2026-11-04 21:00', 10, votantes: $votantes);
        $this->funcion('2026-11-11 21:00', 10);
        $this->funcion('2026-11-07 21:00', 30, obras: [self::obra('Zama', true, 6), self::obra('La ciénaga', false, 4)]);
        $this->funcion('2026-11-14 21:00', 30);
        $this->funcion('2026-11-06 21:00', 38, precio: '0.00');
        $this->funcion('2026-11-13 21:00', 38, precio: '0.00');
    }

    public function testALaSalaQueGanaHolgadoNoSeLeSugiereNingunaPromocion(): void
    {
        $this->galponConTodasLasPromociones();
        // Se llevó $ 560 y le costó $ 400: ganó $ 160, justo el 40 % de su costo.
        $sugerencias = new Sugerencias(new Umbrales(), [self::galponQueSeLlevo(560)]);
        $datos = $this->datos();

        self::assertSame([], $sugerencias->diasFlojos($datos));
        self::assertSame([], $sugerencias->precioDeLaEntrada($datos));
        self::assertSame([], $sugerencias->votaronSinReservar($datos));
        self::assertSame([], $sugerencias->combinadas($datos));
        $sinPromocion = $sugerencias->sinPromocion();
        self::assertCount(1, $sinPromocion);
        self::assertSame([self::GALPON, 160_00, 400_00, 40], [$sinPromocion[0]->salaId, $sinPromocion[0]->resultado, $sinPromocion[0]->costo, $sinPromocion[0]->sobreCosto]);
    }

    public function testUnPesoDebajoDelUmbralLaSalaSigueConSusPromociones(): void
    {
        $this->galponConTodasLasPromociones();
        $sugerencias = new Sugerencias(new Umbrales(), [self::galponQueSeLlevo(559)]);
        $datos = $this->datos();

        self::assertCount(1, $sugerencias->diasFlojos($datos));
        self::assertTrue($sugerencias->precioDeLaEntrada($datos)[0]->sensible);
        self::assertCount(1, $sugerencias->votaronSinReservar($datos));
        self::assertSame(RecomendacionCombinada::PEDIDO_A_LA_GORRA, $sugerencias->combinadas($datos)[0]->regla);
        self::assertSame([], $sugerencias->sinPromocion());
    }

    public function testALaSalaQueGanaLeSigueSugiriendoLoQueLeSuma(): void
    {
        // Lo que se agota el día flojo no es una promoción: es otra función que vende.
        $this->funcion('2026-11-04 21:00', 10);
        $this->funcion('2026-11-11 21:00', 10);
        $this->funcion('2026-11-07 21:00', 39, obras: [self::obra('Rojo')], calificacion: new CalificacionDeFuncion(4.5, 10));
        $this->funcion('2026-11-14 21:00', 30, obras: [self::obra('Rojo')]);
        // Y con precio llena más que a la gorra: cobrar tampoco es una promoción.
        $this->funcion('2026-11-06 21:00', 5, precio: '0.00');
        $this->funcion('2026-11-13 21:00', 5, precio: '0.00');
        $sugerencias = new Sugerencias(new Umbrales(), [self::galponQueSeLlevo(1000)]);
        $datos = $this->datos();

        self::assertSame([], $sugerencias->diasFlojos($datos));
        self::assertSame([RecomendacionCombinada::AGOTADA_EL_DIA_FLOJO], array_values(array_unique(array_map(static fn (RecomendacionCombinada $r): string => $r->regla, $sugerencias->combinadas($datos)))));
        self::assertFalse($sugerencias->precioDeLaEntrada($datos)[0]->sensible);
    }

    public function testElUmbralDeLaGananciaSaleDeLaConfiguracionYUnaSalaSinCostoNoSeFiltra(): void
    {
        $this->galponConTodasLasPromociones();
        $sinCosto = new ResultadoDeSala(self::GALPON, 'El Galpón', 4, 0, 0, 900_00, 0, 0, 0, true);

        self::assertCount(1, (new Sugerencias(new Umbrales(promocionGananciaMaxima: 50), [self::galponQueSeLlevo(560)]))->diasFlojos($this->datos()));
        self::assertCount(1, (new Sugerencias(new Umbrales(), [$sinCosto]))->diasFlojos($this->datos()));
    }

    // El género (EF-22.18). Los miércoles se da comedia y se vende poco; los viernes, drama, y se
    // vende menos todavía; los sábados, terror, y se llena. La sala llena el 42 % (100 de 240).

    private function salaConGeneros(): void
    {
        $this->funcion('2026-11-04 21:00', 8, obras: [self::obra('Risas', generos: ['Comedia'])]);
        $this->funcion('2026-11-11 21:00', 8, obras: [self::obra('Risas', generos: ['Comedia'])]);
        $this->funcion('2026-11-07 21:00', 36, obras: [self::obra('Nosferatu', generos: ['Terror'])]);
        $this->funcion('2026-11-14 21:00', 36, obras: [self::obra('Nosferatu', generos: ['Terror'])]);
        $this->funcion('2026-11-06 21:00', 6, obras: [self::obra('Zama', generos: ['Drama'])]);
        $this->funcion('2026-11-13 21:00', 6, obras: [self::obra('Zama', generos: ['Drama'])]);
    }

    public function testCadaSalaDiceQueGenerosLlenan(): void
    {
        $this->salaConGeneros();
        // Una función sola de documental: no alcanza para decir nada del género.
        $this->funcion('2026-11-05 21:00', 40, obras: [self::obra('Tierra', generos: ['Documental'])]);

        $generos = self::sugerencias()->generosPorSala($this->datos());

        self::assertSame([['Terror', 2, 90], ['Comedia', 2, 20], ['Drama', 2, 15]], array_map(
            static fn (GeneroDeSala $g): array => [$g->genero, $g->funciones, $g->ocupacion],
            $generos,
        ));
        // Nadie votó en la sala.
        self::assertNull($generos[0]->votos);
    }

    public function testElDiaFlojoSugiereLaObraDelGeneroQueMasLlena(): void
    {
        $this->salaConGeneros();

        $combinadas = array_values(array_filter(
            self::sugerencias()->combinadas($this->datos()),
            static fn (RecomendacionCombinada $r): bool => $r->regla === RecomendacionCombinada::GENERO_EL_DIA_FLOJO,
        ));

        // Los dos días flojos, del que menos vende al que más: a los dos les va el terror.
        self::assertSame([
            'Los viernes, dar «Nosferatu» (Terror): es el género que más llena en esta sala.',
            'Los miércoles, dar «Nosferatu» (Terror): es el género que más llena en esta sala.',
        ], array_map(static fn (RecomendacionCombinada $r): string => $r->accion, $combinadas));
        $combinadas = [$combinadas[1]];
        self::assertSame([
            'Los miércoles se vendió el 20 % de los lugares, contra el 53 % del resto de la semana (2 funciones).',
            'En esta sala, las funciones de Terror llenan el 90 % (2 funciones), contra el 42 % de todas.',
            'Los miércoles se dio sobre todo Comedia, que en esta sala llena el 20 %.',
            '«Nosferatu» es de Terror y en esta sala llenó el 90 % de los lugares.',
        ], $combinadas[0]->porque);
        self::assertEquals(new FuncionParaProgramar(self::GALPON, ['obra-Nosferatu'], 3), $combinadas[0]->programar);
    }

    public function testSiSuPublicoVotoUnaDeEseGeneroYNoLaVioVaEsa(): void
    {
        $this->salaConGeneros();
        $this->funcion('2026-11-18 21:00', 8, obras: [self::obra('Risas', generos: ['Comedia']), self::obra('Drácula', false, 5, ['Terror'])]);

        $combinadas = array_values(array_filter(
            self::sugerencias()->combinadas($this->datos()),
            static fn (RecomendacionCombinada $r): bool => $r->regla === RecomendacionCombinada::GENERO_EL_DIA_FLOJO,
        ));

        self::assertSame('Los viernes, dar «Drácula» (Terror): es el género que más llena en esta sala.', $combinadas[0]->accion);
        self::assertSame('«Drácula» es de Terror y su público la votó sin verla: 5 votos.', $combinadas[0]->porque[3]);
        // Los 5 votos de la votación fueron para «Drácula»: el terror se llevó todos.
        self::assertStringEndsWith('Su público le dio el 100 % de los votos.', $combinadas[0]->porque[1]);
    }

    public function testSinUnGeneroQueLleneBastanteMasNoSeSugiere(): void
    {
        // Comedia los miércoles y terror los sábados, pero el terror llena apenas un poco más.
        $this->funcion('2026-11-04 21:00', 14, obras: [self::obra('Risas', generos: ['Comedia'])]);
        $this->funcion('2026-11-11 21:00', 14, obras: [self::obra('Risas', generos: ['Comedia'])]);
        $this->funcion('2026-11-07 21:00', 24, obras: [self::obra('Nosferatu', generos: ['Terror'])]);
        $this->funcion('2026-11-14 21:00', 24, obras: [self::obra('Nosferatu', generos: ['Terror'])]);

        self::assertSame([], array_filter(
            self::sugerencias()->combinadas($this->datos()),
            static fn (RecomendacionCombinada $r): bool => $r->regla === RecomendacionCombinada::GENERO_EL_DIA_FLOJO,
        ));
    }
}
