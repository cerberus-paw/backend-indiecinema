<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Servicios\ArchivosDeSalas;
use IndieCinema\Programacion\Servicios\DatosDePrueba;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * La carga de los datos de demostración contra un MySQL de verdad y una carpeta temporal en lugar
 * del volumen: que deje el sitio con salas habilitadas, obras con afiche y funciones futuras, que
 * correrla de nuevo no duplique nada y que en producción no corra sin la confirmación explícita.
 */
final class CargaDeDatosDePruebaTest extends TestCase
{
    use ConBaseDePruebas;

    private const TABLAS = ['genero', 'sala', 'obra', 'obra_genero', 'funcion', 'funcion_obra'];

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private string $carpeta;

    protected function setUp(): void
    {
        // Antes de saltearla: tearDown() corre igual y la usa.
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

    private function datos(?Configuracion $configuracion = null, bool $confirmadoEnProduccion = false): DatosDePrueba
    {
        return DatosDePrueba::para(
            $configuracion ?? $this->configuracion,
            new RepositorioDeGeneros($this->base),
            new RepositorioDeSalas($this->base),
            new RepositorioDeObras($this->base),
            new RepositorioDeFunciones($this->base),
            $confirmadoEnProduccion,
        );
    }

    private function produccion(): Configuracion
    {
        return new Configuracion([
            'app' => ['entorno' => 'produccion'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
        ]);
    }

    /**
     * @return array<string, int> cuántas filas tiene cada tabla
     */
    private function filas(): array
    {
        $filas = [];
        foreach (self::TABLAS as $tabla) {
            $filas[$tabla] = $this->base->valor("SELECT COUNT(*) FROM {$tabla}");
        }

        return $filas;
    }

    public function testCargaGenerosSalasObrasYFunciones(): void
    {
        self::assertSame([
            'generos' => ['agregadas' => 8, 'yaEstaban' => 0],
            'salas' => ['agregadas' => 4, 'yaEstaban' => 0],
            'obras' => ['agregadas' => 12, 'yaEstaban' => 0],
            'funciones' => ['agregadas' => 15, 'yaEstaban' => 0],
            'fotos' => ['agregadas' => 4, 'yaEstaban' => 0],
            'afiches' => ['agregadas' => 12, 'yaEstaban' => 0],
        ], $this->datos()->cargar());

        $generosPorObra = array_sum(array_map(static fn (array $obra): int => count($obra['generos']), DatosDePrueba::OBRAS));
        $obrasPorFuncion = array_sum(array_map(static fn (array $funcion): int => count($funcion['obras']), DatosDePrueba::FUNCIONES));
        self::assertSame([
            'genero' => 8,
            'sala' => 4,
            'obra' => 12,
            'obra_genero' => $generosPorObra,
            'funcion' => 15,
            'funcion_obra' => $obrasPorFuncion,
        ], $this->filas());
    }

    public function testCorrerloDosVecesDejaLaMismaCantidadDeFilas(): void
    {
        $this->datos()->cargar();
        $antes = $this->filas();

        $resultado = $this->datos()->cargar(new DateTimeImmutable('tomorrow'));

        self::assertSame($antes, $this->filas());
        self::assertSame([0, 0, 0, 0, 0, 0], array_column($resultado, 'agregadas'));
        self::assertSame([8, 4, 12, 15, 4, 12], array_column($resultado, 'yaEstaban'));
    }

    public function testCompletaLoQueFaltaSinTocarLoQueYaEsta(): void
    {
        $this->datos()->cargar();
        $sala = DatosDePrueba::SALAS[0]['id'];
        $funcion = DatosDePrueba::FUNCIONES[0]['id'];
        $this->base->ejecutar("UPDATE sala SET nombre = 'Cambiada a mano' WHERE id = ?", [$sala]);
        $this->base->ejecutar('DELETE FROM funcion WHERE id = ?', [$funcion]);
        $antes = $this->filas();

        $resultado = $this->datos()->cargar();

        self::assertSame(['agregadas' => 1, 'yaEstaban' => 14], $resultado['funciones']);
        self::assertSame($antes['funcion'] + 1, $this->filas()['funcion']);
        self::assertSame('Cambiada a mano', $this->base->valor('SELECT nombre FROM sala WHERE id = ?', [$sala]));
    }

    public function testDejaElSitioConSalasHabilitadasObrasConAficheYFuncionesFuturas(): void
    {
        $this->datos()->cargar();
        $salas = new RepositorioDeSalas($this->base);
        $obras = new RepositorioDeObras($this->base);
        $funciones = new RepositorioDeFunciones($this->base);
        $ahora = new DateTimeImmutable();

        self::assertCount(4, $salas->publicadas());
        self::assertCount(4, $salas->delOrganizador(DatosDePrueba::ORGANIZADOR_DEFECTO_ID));
        foreach ($obras->delDueno(DatosDePrueba::DUENO_DEFECTO_ID) as $obra) {
            self::assertNotNull($obra->afiche, "{$obra->titulo} no tiene afiche.");
            self::assertNotEmpty($obra->generosIds);
        }
        self::assertCount(12, $obras->delDueno(DatosDePrueba::DUENO_DEFECTO_ID));

        $cantidad = 0;
        foreach (DatosDePrueba::salas() as $sala) {
            foreach ($funciones->deLaSala($sala->id) as $funcion) {
                self::assertSame(EstadoFuncion::Programada, $funcion->estado);
                self::assertGreaterThan($ahora, $funcion->fechaHora);
                self::assertNotEmpty($funcion->obrasIds);
                $cantidad++;
            }
        }
        self::assertSame(15, $cantidad);
    }

    public function testLasFuncionesQuedanComoEnLosDatos(): void
    {
        $hoy = new DateTimeImmutable('today');
        $this->datos()->cargar($hoy);
        $funciones = new RepositorioDeFunciones($this->base);

        foreach (DatosDePrueba::funciones($hoy) as $funcion) {
            $guardada = $funciones->buscar($funcion->id) ?? self::fail("No se guardó la función {$funcion->id}.");
            self::assertSame($funcion->salaId, $guardada->salaId);
            self::assertSame($funcion->estado, $guardada->estado);
            self::assertEquals($funcion->fechaHora, $guardada->fechaHora);
            self::assertSame($funcion->cupo, $guardada->cupo);
            self::assertSame($funcion->precioBase, $guardada->precioBase);
            // El repositorio las lee ordenadas por id, no en el orden de los datos.
            self::assertEqualsCanonicalizing($funcion->obrasIds, $guardada->obrasIds);
        }
    }

    public function testCopiaLasFotosDeLasSalasYLosAfichesAlVolumen(): void
    {
        $this->datos()->cargar();
        $fotos = new ArchivosDeSalas($this->configuracion);

        foreach (DatosDePrueba::SALAS as $sala) {
            // Como la entrega el controlador de imágenes de sala.
            self::assertSame(file_get_contents(DatosDePrueba::rutaSala($sala['imagen'])), $fotos->contenido($sala['imagen']));
        }
        foreach (DatosDePrueba::OBRAS as $obra) {
            self::assertFileEquals(DatosDePrueba::rutaAfiche($obra['afiche']), "{$this->carpeta}/afiches/{$obra['afiche']}");
        }
    }

    public function testNoPisaUnaImagenQueYaEsta(): void
    {
        $afiche = DatosDePrueba::OBRAS[0]['afiche'];
        mkdir("{$this->carpeta}/afiches", 0750, true);
        file_put_contents("{$this->carpeta}/afiches/{$afiche}", 'cambiada a mano');

        $resultado = $this->datos()->cargar();

        self::assertSame(['agregadas' => 11, 'yaEstaban' => 1], $resultado['afiches']);
        self::assertStringEqualsFile("{$this->carpeta}/afiches/{$afiche}", 'cambiada a mano');
    }

    public function testEnProduccionNoCorreSinLaConfirmacion(): void
    {
        try {
            $this->datos($this->produccion())->cargar();
            self::fail('Se cargaron datos de prueba en producción sin la confirmación.');
        } catch (RuntimeException $error) {
            // Dice cómo confirmar, para que no haya que ir a buscarlo.
            self::assertStringContainsString('--confirmar-produccion', $error->getMessage());
        }
        self::assertSame(array_fill_keys(self::TABLAS, 0), $this->filas());
        self::assertDirectoryDoesNotExist($this->carpeta);
    }

    public function testEnProduccionCorreConLaConfirmacion(): void
    {
        $resultado = $this->datos($this->produccion(), confirmadoEnProduccion: true)->cargar();

        self::assertSame([8, 4, 12, 15, 4, 12], array_column($resultado, 'agregadas'));
        self::assertSame(4, $this->base->valor('SELECT COUNT(*) FROM sala WHERE habilitada = TRUE'));
    }

    public function testEnDesarrolloNoHaceFaltaLaConfirmacion(): void
    {
        self::assertSame(15, $this->datos(confirmadoEnProduccion: false)->cargar()['funciones']['agregadas']);
    }
}
