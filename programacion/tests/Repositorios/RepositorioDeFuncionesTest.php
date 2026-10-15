<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Repositorios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Pruebas\ConBaseDePruebas;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Las funciones con su sala y sus obras, guardadas y leídas contra un MySQL de verdad, a lo largo
 * del ciclo de vida: cada cambio de estado se guarda y se vuelve a leer.
 */
final class RepositorioDeFuncionesTest extends TestCase
{
    use ConBaseDePruebas;

    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const AHORA = '2026-10-11 18:00:00';
    private const FECHA = '2026-10-24 20:30:00';

    private BaseDeDatos $base;
    private RepositorioDeSalas $salas;
    private RepositorioDeFunciones $funciones;
    private Sala $sala;

    /** @var list<string> las dos obras del catálogo, ordenadas como las lee el repositorio */
    private array $obras = [];

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
        $this->salas = new RepositorioDeSalas($this->base);
        $this->funciones = new RepositorioDeFunciones($this->base);
        $this->sala = $this->salaHabilitada('Cine Club El Galpón');

        $generoId = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$generoId, 'Drama', 'Obras de drama.']);
        $obras = new RepositorioDeObras($this->base);
        foreach (['La ciénaga', 'Zama'] as $titulo) {
            $obra = Obra::nueva(self::ORGANIZADORA, $titulo, 'Sinopsis.', 2001, 'Lucrecia Martel', 103, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$generoId]);
            $obras->agregar($obra);
            $this->obras[] = $obra->id;
        }
        sort($this->obras);
    }

    /**
     * Una sala guardada y habilitada por el administrador, como la lee el repositorio.
     */
    private function salaHabilitada(string $nombre): Sala
    {
        $datos = new DatosDeSala($nombre, 'Un galpón con proyector.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15);
        $sala = Sala::nueva(self::ORGANIZADORA, $datos, 'sala.jpg');
        $this->salas->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        return $this->salas->buscar($sala->id) ?? self::fail('No se guardó la sala.');
    }

    private function funcionNueva(?Sala $sala = null): Funcion
    {
        $funcion = ($sala ?? $this->sala)->crearFuncion($this->obras, 40, 3500.0);
        $this->funciones->agregar($funcion);

        return $funcion;
    }

    private function leer(Funcion $funcion): Funcion
    {
        return $this->funciones->buscar($funcion->id) ?? self::fail('No se encontró la función.');
    }

    /**
     * @return list<string> las obras de la función marcadas como elegidas en la base
     */
    private function elegidasEnLaBase(Funcion $funcion): array
    {
        return array_column($this->base->filas(
            "SELECT obra_id FROM funcion_obra WHERE funcion_id = ? AND estado = 'elegida' ORDER BY obra_id",
            [$funcion->id],
        ), 'obra_id');
    }

    public function testGuardaYLeeUnaFuncionConSuSalaYSusObras(): void
    {
        $funcion = $this->funcionNueva();

        $leida = $this->leer($funcion);
        self::assertEquals($funcion, $leida);
        self::assertEquals($this->sala, $this->salas->buscar($leida->salaId));
        self::assertSame($this->obras, $leida->obrasIds);
        self::assertSame(EstadoFuncion::EnVotacionTitulos, $leida->estado);
        self::assertNull($leida->fechaHora);
        self::assertSame(2, $this->base->valor("SELECT COUNT(*) FROM funcion_obra WHERE funcion_id = ? AND estado = 'candidata'", [$funcion->id]));
    }

    public function testAlCerrarLaVotacionLeeSoloLasElegidasYLasCandidatasQuedan(): void
    {
        $funcion = $this->funcionNueva();
        $funcion->cerrarVotacionTitulos([$this->obras[1]]);
        $this->funciones->guardar($funcion);

        $leida = $this->leer($funcion);
        self::assertEquals($funcion, $leida);
        self::assertSame([$this->obras[1]], $leida->obrasIds);
        self::assertSame([$this->obras[1]], $this->elegidasEnLaBase($funcion));
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM funcion_obra WHERE funcion_id = ?', [$funcion->id]));
    }

    public function testRecorreElCicloDeVidaHastaFinalizar(): void
    {
        $funcion = $this->funcionNueva();
        $funcion->cerrarVotacionTitulos($this->obras);
        $this->funciones->guardar($funcion);
        $funcion = $this->leer($funcion);

        $funcion->fijarFechaHora(new DateTimeImmutable(self::FECHA), new DateTimeImmutable(self::AHORA));
        $this->funciones->guardar($funcion);
        $funcion = $this->leer($funcion);
        self::assertSame(EstadoFuncion::Programada, $funcion->estado);
        self::assertEquals(new DateTimeImmutable(self::FECHA), $funcion->fechaHora);

        $funcion->finalizar(new DateTimeImmutable(self::FECHA));
        $this->funciones->guardar($funcion);
        $leida = $this->leer($funcion);
        self::assertEquals($funcion, $leida);
        self::assertSame(EstadoFuncion::Finalizada, $leida->estado);
        self::assertSame($this->obras, $leida->obrasIds);
    }

    public function testCanceladaEnVotacionConservaSusCandidatas(): void
    {
        $funcion = $this->funcionNueva();
        $funcion->cancelar();
        $this->funciones->guardar($funcion);

        $leida = $this->leer($funcion);
        self::assertEquals($funcion, $leida);
        self::assertSame($this->obras, $leida->obrasIds);
        self::assertSame([], $this->elegidasEnLaBase($funcion));
    }

    public function testCanceladaDespuesDeLaVotacionConservaSusElegidas(): void
    {
        $funcion = $this->funcionNueva();
        $funcion->cerrarVotacionTitulos([$this->obras[0]]);
        $this->funciones->guardar($funcion);
        $funcion->cancelar();
        $this->funciones->guardar($funcion);

        $leida = $this->leer($funcion);
        self::assertSame(EstadoFuncion::Cancelada, $leida->estado);
        self::assertSame([$this->obras[0]], $leida->obrasIds);
    }

    public function testElPrecioSeGuardaExacto(): void
    {
        $funcion = $this->sala->crearFuncion($this->obras, 40, 1234.56);
        $this->funciones->agregar($funcion);

        self::assertSame('1234.56', $this->base->valor('SELECT precio_base FROM funcion WHERE id = ?', [$funcion->id]));
        self::assertSame(1234.56, $this->leer($funcion)->precioBase);
    }

    public function testUnaFuncionQueNoExisteEsNull(): void
    {
        self::assertNull($this->funciones->buscar(Uuid::nuevo()));
    }

    public function testLasFuncionesDeUnaSalaConSusObras(): void
    {
        $sinFecha = $this->funcionNueva();
        $programada = $this->funcionNueva();
        $programada->cerrarVotacionTitulos([$this->obras[0]]);
        $this->funciones->guardar($programada);
        $programada->fijarFechaHora(new DateTimeImmutable(self::FECHA), new DateTimeImmutable(self::AHORA));
        $this->funciones->guardar($programada);
        $this->funcionNueva($this->salaHabilitada('Otra sala'));

        self::assertEquals([$programada, $sinFecha], $this->funciones->deLaSala($this->sala->id));
    }

    public function testConUnaObraQueNoExisteNoGuardaNada(): void
    {
        $funcion = $this->sala->crearFuncion([$this->obras[0], Uuid::nuevo()], 40, 3500.0);

        try {
            $this->funciones->agregar($funcion);
            self::fail('Tenía que rechazar la obra.');
        } catch (PDOException) {
            self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM funcion'));
            self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM funcion_obra'));
        }
    }

    public function testActualizarPersisteCambiosDeSalaObrasFechaCupoYPrecio(): void
    {
        // Programada después de votar: la segunda obra quedó como candidata sin elegir.
        $funcion = $this->funcionNueva();
        $funcion->cerrarVotacionTitulos([$this->obras[0]]);
        $this->funciones->guardar($funcion);
        $funcion->fijarFechaHora(new DateTimeImmutable(self::FECHA), new DateTimeImmutable(self::AHORA));
        $this->funciones->guardar($funcion);

        $otraSala = $this->salaHabilitada('Segunda Sala');
        $nuevaFecha = new DateTimeImmutable('2026-11-15 21:00:00');

        $funcion->actualizar(
            salaId: $otraSala->id,
            obrasIds: [$this->obras[0]],
            cupo: 55,
            precioBase: 4800.0,
            fechaHora: $nuevaFecha,
            ahora: new DateTimeImmutable(self::AHORA),
        );

        $this->funciones->actualizar($funcion);

        $leida = $this->leer($funcion);
        self::assertSame($otraSala->id, $leida->salaId);
        self::assertSame([$this->obras[0]], $leida->obrasIds);
        self::assertSame(55, $leida->cupo);
        self::assertSame(4800.0, $leida->precioBase);
        self::assertEquals($nuevaFecha, $leida->fechaHora);
        self::assertSame(EstadoFuncion::Programada, $leida->estado);
        // La candidata que no salió elegida sigue ahí, para el historial de la votación.
        self::assertSame(1, $this->base->valor(
            "SELECT COUNT(*) FROM funcion_obra WHERE funcion_id = ? AND obra_id = ? AND estado = 'candidata'",
            [$funcion->id, $this->obras[1]],
        ));
    }

    public function testActualizarConUnaCandidataLaPasaAElegidaSinRepetirla(): void
    {
        $funcion = $this->funcionNueva();
        $funcion->cerrarVotacionTitulos([$this->obras[0]]);
        $this->funciones->guardar($funcion);
        $funcion->fijarFechaHora(new DateTimeImmutable(self::FECHA), new DateTimeImmutable(self::AHORA));
        $this->funciones->guardar($funcion);

        $funcion->actualizar($this->sala->id, [$this->obras[1]], 40, 3500.0, new DateTimeImmutable(self::FECHA), new DateTimeImmutable(self::AHORA));
        $this->funciones->actualizar($funcion);

        self::assertSame([$this->obras[1]], $this->elegidasEnLaBase($funcion));
        self::assertSame([$this->obras[1]], $this->leer($funcion)->obrasIds);
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM funcion_obra WHERE funcion_id = ?', [$funcion->id]));
    }

    public function testDelOrganizadorPorId(): void
    {
        $funcion = $this->funcionNueva();

        $delOrg = $this->funciones->delOrganizadorPorId(self::ORGANIZADORA, $funcion->id);
        self::assertNotNull($delOrg);
        self::assertSame($funcion->id, $delOrg->id);
        self::assertNull($this->funciones->delOrganizadorPorId(self::ORGANIZADORA, Uuid::nuevo()));
    }
}
