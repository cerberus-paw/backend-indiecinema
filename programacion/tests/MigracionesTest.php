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
    private const REPETIDO = 1062;
    private const REFERENCIADO = 1451;
    private const REFERENCIA_INEXISTENTE = 1452;

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
            'dueno_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'titulo' => 'La ciénaga',
            'tipo' => 'pelicula',
            'sinopsis' => 'Una familia burguesa en decadencia pasa sus días de verano en su finca de Salta.',
            'anio' => 2001,
            'director' => 'Lucrecia Martel',
            'duracion' => 103,
            'origen_copia' => 'licencia_adquirida',
            'afiche' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4.jpg',
        ];
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function insertarObra(array $datos): void
    {
        $this->base->ejecutar(
            'INSERT INTO obra (id, dueno_id, titulo, tipo, sinopsis, anio, director, duracion, origen_copia, afiche)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $datos['id'],
                $datos['dueno_id'],
                $datos['titulo'],
                $datos['tipo'],
                $datos['sinopsis'],
                $datos['anio'],
                $datos['director'],
                $datos['duracion'],
                $datos['origen_copia'],
                $datos['afiche'],
            ],
        );
    }

    /**
     * @return string el id del género
     */
    private function insertarGenero(string $nombre): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$id, $nombre, "Obras de {$nombre}."]);

        return $id;
    }

    private function asignarGenero(string $obraId, string $generoId): void
    {
        $this->base->ejecutar('INSERT INTO obra_genero (obra_id, genero_id) VALUES (?, ?)', [$obraId, $generoId]);
    }

    public function testLasMigracionesSeAplicanDesdeCeroYCreanLasTablas(): void
    {
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sala'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM genero'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra_genero'));
    }

    public function testLaObraQuedaGuardadaConSusValoresYTimestamps(): void
    {
        $datos = $this->obraValida();
        $this->insertarObra($datos);

        $guardada = $this->base->fila('SELECT * FROM obra WHERE id = ?', [$datos['id']]);
        self::assertNotNull($guardada);
        self::assertSame($datos['dueno_id'], $guardada['dueno_id']);
        self::assertSame($datos['titulo'], $guardada['titulo']);
        self::assertSame($datos['tipo'], $guardada['tipo']);
        self::assertSame($datos['sinopsis'], $guardada['sinopsis']);
        self::assertSame(2001, $guardada['anio']);
        self::assertSame($datos['director'], $guardada['director']);
        self::assertSame(103, $guardada['duracion']);
        self::assertSame($datos['origen_copia'], $guardada['origen_copia']);
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

    public function testLaBaseRechazaUnOrigenDeCopiaFueraDelEnum(): void
    {
        $datos = $this->obraValida();
        $datos['origen_copia'] = 'pirata';

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

    public function testAceptaObrasDesde1888(): void
    {
        $datos = $this->obraValida();
        $datos['anio'] = 1888;
        $this->insertarObra($datos);

        self::assertSame(1888, $this->base->valor('SELECT anio FROM obra WHERE id = ?', [$datos['id']]));
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

    public function testLaBaseRechazaLaFaltaDelDueno(): void
    {
        $datos = $this->obraValida();
        $datos['dueno_id'] = null;

        $this->assertRechaza([self::CAMPO_OBLIGATORIO, self::COLUMNA_NO_NULA], fn () => $this->insertarObra($datos));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra'));
    }

    public function testUnaObraPuedeTenerVariosGeneros(): void
    {
        $datos = $this->obraValida();
        $this->insertarObra($datos);
        $drama = $this->insertarGenero('Drama');
        $comedia = $this->insertarGenero('Comedia');
        $this->asignarGenero($datos['id'], $drama);
        $this->asignarGenero($datos['id'], $comedia);

        $generos = $this->base->filas(
            'SELECT g.nombre FROM obra_genero og JOIN genero g ON g.id = og.genero_id WHERE og.obra_id = ? ORDER BY g.nombre',
            [$datos['id']],
        );
        self::assertSame(['Comedia', 'Drama'], array_column($generos, 'nombre'));
    }

    public function testElNombreDelGeneroNoSeRepiteNiConOtrasMayusculasOTildes(): void
    {
        $this->insertarGenero('Acción');

        $this->assertRechaza(self::REPETIDO, fn () => $this->insertarGenero('Acción'));
        $this->assertRechaza(self::REPETIDO, fn () => $this->insertarGenero('accion'));
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM genero'));
    }

    public function testElMismoGeneroNoSeAsignaDosVecesALaMismaObra(): void
    {
        $datos = $this->obraValida();
        $this->insertarObra($datos);
        $drama = $this->insertarGenero('Drama');
        $this->asignarGenero($datos['id'], $drama);

        $this->assertRechaza(self::REPETIDO, fn () => $this->asignarGenero($datos['id'], $drama));
    }

    public function testSoloSeAsignanObrasYGenerosQueExisten(): void
    {
        $datos = $this->obraValida();
        $this->insertarObra($datos);
        $drama = $this->insertarGenero('Drama');

        $this->assertRechaza(self::REFERENCIA_INEXISTENTE, fn () => $this->asignarGenero($datos['id'], Uuid::nuevo()));
        $this->assertRechaza(self::REFERENCIA_INEXISTENTE, fn () => $this->asignarGenero(Uuid::nuevo(), $drama));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra_genero'));
    }

    public function testUnGeneroEnUsoNoSePuedeBorrar(): void
    {
        $datos = $this->obraValida();
        $this->insertarObra($datos);
        $drama = $this->insertarGenero('Drama');
        $this->asignarGenero($datos['id'], $drama);

        $this->assertRechaza(self::REFERENCIADO, fn () => $this->base->ejecutar('DELETE FROM genero WHERE id = ?', [$drama]));
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM genero'));
    }
}
