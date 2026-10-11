<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Las tablas del subsistema programación tal como las dejan las migraciones, contra un MySQL de
 * verdad: lo que se prueba son las restricciones de la base, no las de la aplicación.
 */
final class MigracionesTest extends TestCase
{
    use ConBaseDePruebas;

    private const VALOR_INVALIDO = 1265;
    private const FUERA_DE_RANGO = 1264;
    private const RESTRICCION_CHECK = 3819;
    private const CAMPO_OBLIGATORIO = 1364;
    private const COLUMNA_NO_NULA = 1048;

    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
    }

    /**
     * @param int|int[] $codigo
     */
    private function assertRechaza(int|array $codigo, callable $escritura): void
    {
        try {
            $escritura();
            self::fail('La base aceptó una escritura que tenía que rechazar.');
        } catch (PDOException $e) {
            $codigos = is_array($codigo) ? $codigo : [$codigo];
            self::assertContains($e->errorInfo[1] ?? null, $codigos, $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function obraValida(): array
    {
        return [
            'id' => Uuid::nuevo(),
            'titulo' => 'La ciénaga',
            'tipo' => 'pelicula',
            'genero' => 'Drama',
            'duracion' => 103,
            'anio' => 2001,
            'sinopsis' => 'Una familia burguesa en decadencia pasa sus días de verano en su finca de Salta.',
            'afiche' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4.jpg',
        ];
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function insertarObra(array $datos): void
    {
        $this->base->ejecutar(
            'INSERT INTO obra (id, titulo, tipo, genero, duracion, anio, sinopsis, afiche) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $datos['id'],
                $datos['titulo'],
                $datos['tipo'],
                $datos['genero'],
                $datos['duracion'],
                $datos['anio'],
                $datos['sinopsis'],
                $datos['afiche'],
            ],
        );
    }

    public function testLasMigracionesSeAplicanDesdeCeroYCreanLasTablas(): void
    {
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sala'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra'));
    }

    public function testLaObraQuedaGuardadaConSusValoresYTimestamps(): void
    {
        $datos = $this->obraValida();
        $this->insertarObra($datos);

        $guardada = $this->base->fila('SELECT * FROM obra WHERE id = ?', [$datos['id']]);
        self::assertNotNull($guardada);
        self::assertSame($datos['titulo'], $guardada['titulo']);
        self::assertSame($datos['tipo'], $guardada['tipo']);
        self::assertSame($datos['genero'], $guardada['genero']);
        self::assertSame(103, $guardada['duracion']);
        self::assertSame(2001, $guardada['anio']);
        self::assertSame($datos['sinopsis'], $guardada['sinopsis']);
        self::assertSame($datos['afiche'], $guardada['afiche']);
        self::assertNotNull($guardada['creada_en']);
        self::assertNotNull($guardada['actualizada_en']);
        self::assertNull($guardada['dada_de_baja_en']);
    }

    public function testSePuedeGuardarUnaObraSinAfiche(): void
    {
        $datos = $this->obraValida();
        $datos['afiche'] = null;
        $this->insertarObra($datos);

        $guardada = $this->base->fila('SELECT afiche FROM obra WHERE id = ?', [$datos['id']]);
        self::assertNotNull($guardada);
        self::assertNull($guardada['afiche']);
    }

    public function testSePuedeGuardarUnCortometraje(): void
    {
        $datos = $this->obraValida();
        $datos['tipo'] = 'cortometraje';
        $datos['duracion'] = 15;
        $this->insertarObra($datos);

        $guardada = $this->base->fila('SELECT tipo, duracion FROM obra WHERE id = ?', [$datos['id']]);
        self::assertNotNull($guardada);
        self::assertSame('cortometraje', $guardada['tipo']);
        self::assertSame(15, $guardada['duracion']);
    }

    public function testLaBaseRechazaUnTipoFueraDelEnum(): void
    {
        $datos = $this->obraValida();
        $datos['tipo'] = 'serie';

        $this->assertRechaza(self::VALOR_INVALIDO, fn () => $this->insertarObra($datos));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra'));
    }

    public function testLaBaseRechazaUnaDuracionCeroONegativa(): void
    {
        $datosCero = $this->obraValida();
        $datosCero['duracion'] = 0;
        $this->assertRechaza(self::RESTRICCION_CHECK, fn () => $this->insertarObra($datosCero));

        $datosNegativa = $this->obraValida();
        $datosNegativa['duracion'] = -10;
        $this->assertRechaza([self::RESTRICCION_CHECK, self::FUERA_DE_RANGO], fn () => $this->insertarObra($datosNegativa));

        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra'));
    }

    public function testLaBaseRechazaUnAnioPrevioALosComienzosDelCine(): void
    {
        $datos = $this->obraValida();
        $datos['anio'] = 1800;

        $this->assertRechaza(self::RESTRICCION_CHECK, fn () => $this->insertarObra($datos));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra'));
    }

    public function testLaBaseRechazaLaFaltaDeCamposObligatorios(): void
    {
        $datos = $this->obraValida();
        $datos['titulo'] = null;

        $this->assertRechaza([self::CAMPO_OBLIGATORIO, self::COLUMNA_NO_NULA], fn () => $this->insertarObra($datos));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra'));
    }
}
