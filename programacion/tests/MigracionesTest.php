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

    /**
     * @return string el id de la sala
     */
    private function insertarSala(): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO sala (id, organizador_id, nombre, descripcion, direccion, localidad, capacidad,'
            . ' peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, habilitada)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Cine Club El Galpón', 'Un galpón con proyector.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15, 1],
        );

        return $id;
    }

    /**
     * @return string el id de la obra
     */
    private function insertarOtraObra(): string
    {
        $datos = $this->obraValida();
        $this->insertarObra($datos);

        return $datos['id'];
    }

    /**
     * Una función recién creada: en votación de títulos y sin fecha.
     *
     * @return array<string, mixed>
     */
    private function funcionValida(string $salaId): array
    {
        return [
            'id' => Uuid::nuevo(),
            'sala_id' => $salaId,
            'estado' => 'en_votacion_titulos',
            'fecha_hora' => null,
            'precio_base' => '3500.00',
            'cupo' => 40,
        ];
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function insertarFuncion(array $datos): void
    {
        $this->base->ejecutar(
            'INSERT INTO funcion (id, sala_id, estado, fecha_hora, precio_base, cupo) VALUES (?, ?, ?, ?, ?, ?)',
            [$datos['id'], $datos['sala_id'], $datos['estado'], $datos['fecha_hora'], $datos['precio_base'], $datos['cupo']],
        );
    }

    private function agregarObra(string $funcionId, string $obraId, ?string $estado = null): void
    {
        if ($estado === null) {
            $this->base->ejecutar('INSERT INTO funcion_obra (funcion_id, obra_id) VALUES (?, ?)', [$funcionId, $obraId]);

            return;
        }
        $this->base->ejecutar('INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, ?)', [$funcionId, $obraId, $estado]);
    }

    public function testLasMigracionesSeAplicanDesdeCeroYCreanLasTablas(): void
    {
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sala'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM genero'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM obra_genero'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM funcion'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM funcion_obra'));
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

    public function testLaFuncionQuedaGuardadaEnVotacionYSinFecha(): void
    {
        $datos = $this->funcionValida($this->insertarSala());
        $this->base->ejecutar(
            'INSERT INTO funcion (id, sala_id, precio_base, cupo) VALUES (?, ?, ?, ?)',
            [$datos['id'], $datos['sala_id'], $datos['precio_base'], $datos['cupo']],
        );

        $guardada = $this->base->fila('SELECT * FROM funcion WHERE id = ?', [$datos['id']]);
        self::assertNotNull($guardada);
        self::assertSame($datos['sala_id'], $guardada['sala_id']);
        self::assertSame('en_votacion_titulos', $guardada['estado']);
        self::assertNull($guardada['fecha_hora']);
        self::assertSame('3500.00', $guardada['precio_base']);
        self::assertSame(40, $guardada['cupo']);
    }

    public function testNoSeGuardaUnaFuncionSinSala(): void
    {
        $deSalaInexistente = $this->funcionValida(Uuid::nuevo());
        $this->assertRechaza(self::REFERENCIA_INEXISTENTE, fn () => $this->insertarFuncion($deSalaInexistente));

        $sinSala = $this->funcionValida(Uuid::nuevo());
        $sinSala['sala_id'] = null;
        $this->assertRechaza([self::CAMPO_OBLIGATORIO, self::COLUMNA_NO_NULA], fn () => $this->insertarFuncion($sinSala));

        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM funcion'));
    }

    public function testLaFechaPuedeQuedarVaciaHastaQueLaFuncionSePrograma(): void
    {
        $salaId = $this->insertarSala();
        foreach (['en_votacion_titulos', 'en_acuerdo_fecha', 'cancelada'] as $estado) {
            $datos = $this->funcionValida($salaId);
            $datos['estado'] = $estado;
            $this->insertarFuncion($datos);
        }

        self::assertSame(3, $this->base->valor('SELECT COUNT(*) FROM funcion WHERE fecha_hora IS NULL'));
    }

    public function testUnaFuncionProgramadaOFinalizadaSiempreTieneFecha(): void
    {
        $salaId = $this->insertarSala();
        foreach (['programada', 'finalizada'] as $estado) {
            $sinFecha = $this->funcionValida($salaId);
            $sinFecha['estado'] = $estado;
            $this->assertRechaza(self::RESTRICCION_CHECK, fn () => $this->insertarFuncion($sinFecha));

            $conFecha = $this->funcionValida($salaId);
            $conFecha['estado'] = $estado;
            $conFecha['fecha_hora'] = '2026-10-24 20:30:00';
            $this->insertarFuncion($conFecha);
        }

        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM funcion'));
    }

    public function testNoSePasaAProgramadaSinFijarLaFecha(): void
    {
        $datos = $this->funcionValida($this->insertarSala());
        $datos['estado'] = 'en_acuerdo_fecha';
        $this->insertarFuncion($datos);

        $this->assertRechaza(
            self::RESTRICCION_CHECK,
            fn () => $this->base->ejecutar('UPDATE funcion SET estado = ? WHERE id = ?', ['programada', $datos['id']]),
        );
        self::assertSame('en_acuerdo_fecha', $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$datos['id']]));

        $this->base->ejecutar(
            'UPDATE funcion SET estado = ?, fecha_hora = ? WHERE id = ?',
            ['programada', '2026-10-24 20:30:00', $datos['id']],
        );
        self::assertSame('programada', $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$datos['id']]));
    }

    public function testLaBaseRechazaUnEstadoDeFuncionFueraDelEnum(): void
    {
        $datos = $this->funcionValida($this->insertarSala());
        $datos['estado'] = 'suspendida';

        $this->assertRechaza(self::VALOR_INVALIDO, fn () => $this->insertarFuncion($datos));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM funcion'));
    }

    public function testLaBaseRechazaUnCupoCeroOUnPrecioNegativo(): void
    {
        $salaId = $this->insertarSala();

        $cupoCero = $this->funcionValida($salaId);
        $cupoCero['cupo'] = 0;
        $this->assertRechaza(self::RESTRICCION_CHECK, fn () => $this->insertarFuncion($cupoCero));

        $precioNegativo = $this->funcionValida($salaId);
        $precioNegativo['precio_base'] = '-0.01';
        $this->assertRechaza(self::RESTRICCION_CHECK, fn () => $this->insertarFuncion($precioNegativo));

        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM funcion'));
    }

    public function testLaFuncionPuedeSerALaGorra(): void
    {
        $datos = $this->funcionValida($this->insertarSala());
        $datos['precio_base'] = '0';
        $this->insertarFuncion($datos);

        self::assertSame('0.00', $this->base->valor('SELECT precio_base FROM funcion WHERE id = ?', [$datos['id']]));
    }

    public function testCadaObraSeMarcaComoCandidataOElegida(): void
    {
        $funcion = $this->funcionValida($this->insertarSala());
        $this->insertarFuncion($funcion);
        $obraId = $this->insertarOtraObra();

        $this->agregarObra($funcion['id'], $obraId);
        $estado = 'SELECT estado FROM funcion_obra WHERE funcion_id = ? AND obra_id = ?';
        self::assertSame('candidata', $this->base->valor($estado, [$funcion['id'], $obraId]));

        $this->base->ejecutar(
            'UPDATE funcion_obra SET estado = ? WHERE funcion_id = ? AND obra_id = ?',
            ['elegida', $funcion['id'], $obraId],
        );
        self::assertSame('elegida', $this->base->valor($estado, [$funcion['id'], $obraId]));

        $this->assertRechaza(self::VALOR_INVALIDO, fn () => $this->agregarObra($funcion['id'], $this->insertarOtraObra(), 'descartada'));
    }

    public function testNoSeGuardaUnaFuncionConUnaObraInexistente(): void
    {
        $funcion = $this->funcionValida($this->insertarSala());
        $this->insertarFuncion($funcion);
        $obraId = $this->insertarOtraObra();

        $this->assertRechaza(self::REFERENCIA_INEXISTENTE, fn () => $this->agregarObra($funcion['id'], Uuid::nuevo()));
        $this->assertRechaza(self::REFERENCIA_INEXISTENTE, fn () => $this->agregarObra(Uuid::nuevo(), $obraId));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM funcion_obra'));
    }

    public function testUnaObraNoEstaDosVecesEnLaMismaFuncion(): void
    {
        $funcion = $this->funcionValida($this->insertarSala());
        $this->insertarFuncion($funcion);
        $obraId = $this->insertarOtraObra();
        $this->agregarObra($funcion['id'], $obraId);

        $this->assertRechaza(self::REPETIDO, fn () => $this->agregarObra($funcion['id'], $obraId, 'elegida'));
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM funcion_obra'));
    }

    public function testUnaSalaConFuncionesNoSePuedeBorrar(): void
    {
        $salaId = $this->insertarSala();
        $this->insertarFuncion($this->funcionValida($salaId));

        $this->assertRechaza(self::REFERENCIADO, fn () => $this->base->ejecutar('DELETE FROM sala WHERE id = ?', [$salaId]));
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM funcion'));
    }

    public function testUnaObraQueEstuvoEnUnaFuncionNoSePuedeBorrar(): void
    {
        $funcion = $this->funcionValida($this->insertarSala());
        $this->insertarFuncion($funcion);
        $obraId = $this->insertarOtraObra();
        $this->agregarObra($funcion['id'], $obraId);

        $this->assertRechaza(self::REFERENCIADO, fn () => $this->base->ejecutar('DELETE FROM obra WHERE id = ?', [$obraId]));
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM funcion_obra'));
    }

    public function testAlBorrarUnaFuncionSeVanSusObrasPeroNoLasObras(): void
    {
        $funcion = $this->funcionValida($this->insertarSala());
        $this->insertarFuncion($funcion);
        $obraId = $this->insertarOtraObra();
        $this->agregarObra($funcion['id'], $obraId);

        $this->base->ejecutar('DELETE FROM funcion WHERE id = ?', [$funcion['id']]);

        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM funcion_obra'));
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM obra WHERE id = ?', [$obraId]));
    }
}
