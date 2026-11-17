<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Programacion\Modelo\ObraVotada;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;
use IndieCinema\Programacion\Servicios\DatosDePrueba;
use IndieCinema\Programacion\Servicios\HistorialDePrueba;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * El historial de funciones finalizadas para la demo de los reportes (EF-09.4): el plan tiene lo
 * que hace falta para cada sugerencia, y la carga lo deja como lo lee la API interna de
 * recaudación, sin duplicar nada si se corre de nuevo.
 */
final class HistorialDePruebaTest extends TestCase
{
    use ConBaseDePruebas;

    private const CALIGARI = '00000002-0000-4000-8000-000000000004';
    private const HOY = '2026-11-13';

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private string $carpeta;

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $this->configuracion = new Configuracion([
            'app' => ['entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    protected function tearDown(): void
    {
        foreach (['salas', 'afiches'] as $subcarpeta) {
            array_map('unlink', glob("{$this->carpeta}/{$subcarpeta}/*") ?: []);
            @rmdir("{$this->carpeta}/{$subcarpeta}");
        }
        @rmdir($this->carpeta);
    }

    private function historial(?Configuracion $configuracion = null, bool $confirmado = false): HistorialDePrueba
    {
        return HistorialDePrueba::para(
            $configuracion ?? $this->configuracion,
            new RepositorioDeSalas($this->base),
            new RepositorioDeFunciones($this->base),
            new RepositorioDeVotos($this->base),
            $confirmado,
        );
    }

    private function cargarLosDatosDePrueba(): void
    {
        DatosDePrueba::para(
            $this->configuracion,
            new RepositorioDeGeneros($this->base),
            new RepositorioDeSalas($this->base),
            new RepositorioDeObras($this->base),
            new RepositorioDeFunciones($this->base),
        )->cargar(new DateTimeImmutable(self::HOY));
    }

    public function testElPlanSonOchoSemanasQueYaPasaronEnLasSalasDePrueba(): void
    {
        $plan = HistorialDePrueba::plan(new DateTimeImmutable(self::HOY . ' 18:00'));
        $capacidades = array_column(DatosDePrueba::SALAS, 'capacidad', 'id');
        $peliculas = array_column(DatosDePrueba::SALAS, 'peliculas_por_funcion', 'id');

        self::assertCount(28, $plan);
        self::assertSame(HistorialDePrueba::idDeFuncion(1), $plan[0]['id']);
        // La primera, un viernes en La Trocha; la última, el miércoles antes de hoy.
        self::assertSame('2026-09-18', $plan[0]['fecha']->format('Y-m-d'));
        self::assertSame('2026-11-11', $plan[27]['fecha']->format('Y-m-d'));
        foreach ($plan as $funcion) {
            self::assertLessThan(new DateTimeImmutable(self::HOY), $funcion['fecha']);
            self::assertLessThanOrEqual($capacidades[$funcion['sala_id']], $funcion['cupo']);
            self::assertCount($peliculas[$funcion['sala_id']], $funcion['elegidas']);
            if ($funcion['votos'] !== []) {
                // Gana la más votada, y las elegidas estaban entre las votadas.
                self::assertSame([array_search(max($funcion['votos']), $funcion['votos'], true)], $funcion['elegidas']);
            }
        }
    }

    public function testCaligariPierdeConMasDeUnTercioYNuncaSeProyecta(): void
    {
        $votaciones = 0;
        foreach (HistorialDePrueba::plan(new DateTimeImmutable(self::HOY)) as $funcion) {
            self::assertNotContains(self::CALIGARI, $funcion['elegidas']);
            if (isset($funcion['votos'][self::CALIGARI])) {
                ++$votaciones;
                self::assertGreaterThanOrEqual(30, intdiv(100 * $funcion['votos'][self::CALIGARI], array_sum($funcion['votos'])));
            }
        }
        self::assertSame(12, $votaciones);
    }

    public function testLaCargaDejaLasFuncionesFinalizadasConSusVotosSinDuplicar(): void
    {
        $this->cargarLosDatosDePrueba();

        self::assertSame(['agregadas' => 28, 'yaEstaban' => 0], $this->historial()->cargar(new DateTimeImmutable(self::HOY)));
        // Otro día: las que ya estaban conservan su fecha y no se agrega ninguna.
        self::assertSame(['agregadas' => 0, 'yaEstaban' => 28], $this->historial()->cargar(new DateTimeImmutable('2026-12-01')));

        $finalizadas = (new RepositorioDeFunciones($this->base))->finalizadasEntre(new DateTimeImmutable('2026-09-01'), new DateTimeImmutable(self::HOY), null, null, 100);
        self::assertCount(28, $finalizadas);
        self::assertSame(HistorialDePrueba::idDeFuncion(1), $finalizadas[0]->id);
        self::assertSame(DatosDePrueba::ORGANIZADOR_DEFECTO_ID, $finalizadas[0]->organizadorId);
        // La primera votación: una sola elegida, y un votante por voto.
        $votada = array_values(array_filter($finalizadas, static fn ($funcion): bool => $funcion->votantesIds !== []))[0];
        $elegidas = array_values(array_filter($votada->obras, static fn (ObraVotada $obra): bool => $obra->elegida));
        self::assertCount(1, $elegidas);
        self::assertCount(array_sum(array_map(static fn (ObraVotada $obra): int => $obra->votos, $votada->obras)), $votada->votantesIds);
        self::assertSame(HistorialDePrueba::idDeEspectador(1), $votada->votantesIds[0]);
    }

    public function testSinLasSalasDePruebaNoCargaNada(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('primero herramientas/datos-de-prueba.php');

        $this->historial()->cargar(new DateTimeImmutable(self::HOY));
    }

    public function testEnProduccionPideLaConfirmacion(): void
    {
        $produccion = new Configuracion(['app' => ['entorno' => 'produccion'], 'base' => self::baseDePruebas()]);

        $this->historial($produccion, true);
        $this->expectException(RuntimeException::class);
        $this->historial($produccion);
    }
}
