<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas\Servicios;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Recaudacion\Modelo\ParteDelReparto;
use IndieCinema\Recaudacion\Pruebas\ConBaseDePruebas;
use IndieCinema\Recaudacion\Pruebas\ConDoblesDeSubsistemas;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeParametrosDeReparto;
use IndieCinema\Recaudacion\Repositorios\RepositorioDeRepartos;
use IndieCinema\Recaudacion\Servicios\CobrosDeFunciones;
use IndieCinema\Recaudacion\Servicios\FuncionesFinalizadas;
use IndieCinema\Recaudacion\Servicios\RepartoDeFunciones;
use IndieCinema\Recaudacion\Servicios\ServicioDeParametrosDeReparto;
use PHPUnit\Framework\TestCase;

/**
 * La tarea del reparto de punta a punta (EF-08.2): un MySQL de verdad con las migraciones de
 * recaudación, y un programación y un funciones de mentira con `php -S`, como en el compose. Las
 * funciones de los dobles están armadas para lo que pide la tarea: el redondeo, varias obras, una
 * a la gorra y una vieja que se finalizó tarde (Dobles/programacion.php).
 */
final class RepartoDeFuncionesTest extends TestCase
{
    use ConBaseDePruebas;
    use ConDoblesDeSubsistemas;

    private const SIMPLE = '50000000-0000-4000-8000-000000000001';
    private const TRES_OBRAS = '50000000-0000-4000-8000-000000000002';
    private const A_LA_GORRA = '50000000-0000-4000-8000-000000000003';
    private const VIEJA = '50000000-0000-4000-8000-000000000004';
    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const DUENA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    private static string $programacion;

    private static string $funciones;

    private BaseDeDatos $base;

    private RepositorioDeRepartos $repartos;

    public static function setUpBeforeClass(): void
    {
        self::$programacion = self::levantar('programacion.php');
        self::$funciones = self::levantar('funciones.php');
    }

    public static function tearDownAfterClass(): void
    {
        self::apagarDobles();
    }

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
        $this->repartos = new RepositorioDeRepartos($this->base);
    }

    /**
     * @param array<string, string> $subsistemas los que cambian: para tirar uno abajo
     */
    private function tarea(array $subsistemas = []): RepartoDeFunciones
    {
        $log = new Log('recaudacion', fopen('php://memory', 'w'));
        $cliente = new ClienteInterno(
            'recaudacion',
            'token-de-recaudacion',
            [...['programacion' => self::$programacion, 'funciones' => self::$funciones], ...$subsistemas],
            $log,
        );

        return new RepartoDeFunciones(
            new FuncionesFinalizadas($cliente),
            new CobrosDeFunciones($cliente),
            new ServicioDeParametrosDeReparto(new RepositorioDeParametrosDeReparto($this->base)),
            $this->repartos,
        );
    }

    /**
     * @return array<string, string> el monto de cada parte, por rol y beneficiario
     */
    private function partes(string $funcionId): array
    {
        $partes = [];
        foreach ($this->repartos->partesDe($funcionId) as $parte) {
            $partes[$parte->rol->value . ':' . ($parte->beneficiarioId ?? 'sitio')] = $parte->monto();
        }

        return $partes;
    }

    private function cobradoDe(string $funcionId): ?string
    {
        $cobrado = $this->base->valor('SELECT cobrado FROM reparto WHERE funcion_id = ?', [$funcionId]);

        return $cobrado === null ? null : (string) $cobrado;
    }

    /** Un parámetro nuevo, que rige desde una fecha: como lo carga el administrador (EF-08.1). */
    private function parametroDesde(string $vigenteDesde, string $sala, string $dueno, string $plataforma): void
    {
        $this->base->ejecutar(
            'INSERT INTO parametro_reparto (id, sala, dueno, plataforma, vigente_desde, cargado_por)
             VALUES (?, ?, ?, ?, ?, ?)',
            [Uuid::nuevo(), $sala, $dueno, $plataforma, $vigenteDesde, self::ORGANIZADORA],
        );
    }

    public function testReparteLoCobradoDeCadaFuncionPagaQueTermino(): void
    {
        $hecho = $this->tarea()->repartir();

        self::assertSame(['repartidas' => 3, 'sinPlata' => 1, 'quedanMas' => false], $hecho);
        // $ 10.000 cobrados y 70/15/15: 7.000 para la sala, 1.500 para la dueña de la obra y 1.500
        // para la plataforma. Lo reembolsado no está en lo cobrado, así que no se repartió.
        self::assertSame('10000.00', $this->cobradoDe(self::SIMPLE));
        self::assertSame([
            'sala:' . self::ORGANIZADORA => '7000.00',
            'dueno:' . self::ORGANIZADORA => '1500.00',
            'plataforma:sitio' => '1500.00',
        ], $this->partes(self::SIMPLE));
    }

    public function testConVariasObrasLasPartesSumanLoCobrado(): void
    {
        $this->tarea()->repartir();

        // $ 100 y tres obras, dos de la organizadora y una de la otra dueña: 15 % son 15, a 5 por
        // obra, y las partes suman 100,00 justos.
        self::assertSame([
            'sala:' . self::ORGANIZADORA => '70.00',
            'dueno:' . self::ORGANIZADORA => '10.00',
            'dueno:' . self::DUENA => '5.00',
            'plataforma:sitio' => '15.00',
        ], $this->partes(self::TRES_OBRAS));
        self::assertSame('100.00', $this->cobradoDe(self::TRES_OBRAS));
    }

    public function testLasFuncionesALaGorraQuedanRegistradasYNoReparten(): void
    {
        $this->tarea()->repartir();

        self::assertSame('0.00', $this->cobradoDe(self::A_LA_GORRA));
        self::assertSame([], $this->partes(self::A_LA_GORRA));
        self::assertNull($this->base->valor('SELECT parametro_id FROM reparto WHERE funcion_id = ?', [self::A_LA_GORRA]));
    }

    /**
     * El cursor va por la fecha de finalización: la de octubre se finalizó la última y entra igual,
     * que es lo que se perdería si la tarea mirara la fecha de la función.
     */
    public function testUnaFuncionViejaQueSeFinalizoTardeTambienSeReparte(): void
    {
        $this->tarea()->repartir();

        self::assertSame('3500.00', $this->cobradoDe(self::VIEJA));
        self::assertSame('2450.00', $this->partes(self::VIEJA)['sala:' . self::ORGANIZADORA]);
    }

    public function testCorrerLaTareaDeNuevoNoRepiteNingunReparto(): void
    {
        $this->tarea()->repartir();
        $partes = $this->base->valor('SELECT COUNT(*) FROM reparto_parte');

        $otraVez = $this->tarea()->repartir();

        self::assertSame(['repartidas' => 0, 'sinPlata' => 0, 'quedanMas' => false], $otraVez);
        self::assertSame(4, $this->base->valor('SELECT COUNT(*) FROM reparto'));
        self::assertSame($partes, $this->base->valor('SELECT COUNT(*) FROM reparto_parte'));
    }

    /**
     * Lo que pide EF-08.1: cambiar los porcentajes no mueve lo que ya se dio. Acá el cambio rige
     * desde el 21, así que la función del 20 se reparte con los viejos y la del 21 con los nuevos.
     */
    public function testUnCambioDePorcentajesNoMueveLasFuncionesAnteriores(): void
    {
        $this->parametroDesde('2026-11-21 00:00:00', '60.00', '20.00', '20.00');

        $this->tarea()->repartir();

        self::assertSame('7000.00', $this->partes(self::SIMPLE)['sala:' . self::ORGANIZADORA]);
        // 60/20/20 sobre $ 100: 60 para la sala, 20 entre las tres obras (6,66 y 6,66) y lo que
        // queda del redondeo, para la plataforma.
        self::assertSame([
            'sala:' . self::ORGANIZADORA => '60.00',
            'dueno:' . self::ORGANIZADORA => '13.32',
            'dueno:' . self::DUENA => '6.66',
            'plataforma:sitio' => '20.02',
        ], $this->partes(self::TRES_OBRAS));
    }

    public function testElCursorQuedaEnLaUltimaFinalizacionRepartida(): void
    {
        self::assertNull($this->repartos->ultimaFinalizacion());

        $this->tarea()->repartir();

        $ultima = $this->repartos->ultimaFinalizacion();
        self::assertNotNull($ultima);
        self::assertSame('2026-11-23 10:00:00', $ultima->format('Y-m-d H:i:s'));
    }

    public function testSiProgramacionNoRespondeNoSeReparteNada(): void
    {
        $caido = 'http://127.0.0.1:' . self::puertoLibre();

        try {
            $this->tarea(['programacion' => $caido])->repartir();
            self::fail('Repartió sin que programación respondiera.');
        } catch (ExcepcionHttp $error) {
            self::assertSame(503, $error->estado);
        }
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM reparto'));
    }

    public function testSiFuncionesNoRespondeNoSeReparteNada(): void
    {
        $caido = 'http://127.0.0.1:' . self::puertoLibre();

        try {
            $this->tarea(['funciones' => $caido])->repartir();
            self::fail('Repartió sin que funciones respondiera lo cobrado.');
        } catch (ExcepcionHttp $error) {
            self::assertSame(503, $error->estado);
        }
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM reparto'));
    }

    public function testLasPartesGuardadasSonLasQueCalculoElModelo(): void
    {
        $this->tarea()->repartir();

        $partes = $this->repartos->partesDe(self::SIMPLE);
        self::assertCount(3, $partes);
        self::assertSame(
            1_000_000,
            array_sum(array_map(static fn (ParteDelReparto $parte): int => $parte->centavos, $partes)),
        );
    }

    public function testSinParametroVigenteNoSeRepartYSeAvisa(): void
    {
        // Sin el de la migración no hay con qué repartir: la tarea corta y no guarda nada.
        $this->base->ejecutar('DELETE FROM parametro_reparto');

        $this->expectExceptionMessage('No hay parámetro de reparto vigente');

        $this->tarea()->repartir();
    }

    public function testLaPrimeraPasadaArrancaDesdeAntesDeCualquierFuncion(): void
    {
        // Sin repartos, el cursor arranca en 2000: la de octubre, la más vieja, también entra.
        $this->tarea()->repartir();

        self::assertNotNull($this->cobradoDe(self::VIEJA));
        self::assertSame('2026-11-20 23:20:00', (string) $this->base->valor('SELECT MIN(finalizada_en) FROM reparto'));
    }
}
