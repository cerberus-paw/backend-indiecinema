<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Las tablas de moderación como las dejan sus migraciones, contra un MySQL de verdad: lo que cuida
 * la base aunque alguien escriba por otro lado.
 */
final class MigracionesTest extends TestCase
{
    use ConBaseDePruebas;

    /** El error de MySQL de una restricción CHECK. */
    private const RESTRICCION_CHECK = 3819;

    /** El error de MySQL de una clave única repetida. */
    private const CLAVE_REPETIDA = 1062;

    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
    }

    private function assertRechaza(int $codigo, callable $escritura): void
    {
        try {
            $escritura();
            self::fail('La base aceptó una escritura que tenía que rechazar.');
        } catch (PDOException $error) {
            self::assertSame($codigo, (int) ($error->errorInfo[1] ?? 0), $error->getMessage());
        }
    }

    /**
     * Una solicitud pendiente de esa sala, con datos válidos.
     */
    private function solicitar(string $salaId): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO solicitud_sala (id, sala_id, usuario_id, nombre, descripcion, direccion, localidad, capacidad,
                peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, enviada_en)
             VALUES (?, ?, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'El Galpón', 'Un galpón.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15, NOW())",
            [$id, $salaId],
        );

        return $id;
    }

    public function testUnaSalaTieneUnaSolaPendienteYLasResueltasNoCuentan(): void
    {
        $sala = Uuid::nuevo();
        $primera = $this->solicitar($sala);

        $this->assertRechaza(self::CLAVE_REPETIDA, fn () => $this->solicitar($sala));

        $this->base->ejecutar("UPDATE solicitud_sala SET estado = 'rechazada', motivo = 'Falta la póliza.', resuelta_en = NOW() WHERE id = ?", [$primera]);
        $this->solicitar($sala);
        $this->solicitar(Uuid::nuevo());
        self::assertSame(3, $this->base->valor('SELECT COUNT(*) FROM solicitud_sala'));
    }

    public function testUnaSolicitudResueltaTieneFechaYMotivoYUnaPendienteNo(): void
    {
        $solicitud = $this->solicitar(Uuid::nuevo());

        $this->assertRechaza(self::RESTRICCION_CHECK, fn () => $this->base->ejecutar("UPDATE solicitud_sala SET estado = 'aprobada', motivo = 'Bien.' WHERE id = ?", [$solicitud]));
        $this->assertRechaza(self::RESTRICCION_CHECK, fn () => $this->base->ejecutar("UPDATE solicitud_sala SET estado = 'aprobada', resuelta_en = NOW() WHERE id = ?", [$solicitud]));
        $this->assertRechaza(self::RESTRICCION_CHECK, fn () => $this->base->ejecutar('UPDATE solicitud_sala SET resuelta_en = NOW() WHERE id = ?', [$solicitud]));
    }

    public function testLaSalaPropuestaTieneCapacidadPeliculasYDuracion(): void
    {
        $solicitud = $this->solicitar(Uuid::nuevo());

        foreach (['capacidad', 'peliculas_por_funcion', 'duracion_funcion'] as $columna) {
            $this->assertRechaza(self::RESTRICCION_CHECK, fn () => $this->base->ejecutar("UPDATE solicitud_sala SET {$columna} = 0 WHERE id = ?", [$solicitud]));
        }
    }

    /**
     * EF-07.2: las solicitudes que había en programación se mudan con los datos de su sala, sin
     * foto propia, y programación se queda sin la tabla. Las de salas dadas de baja no, como no se
     * veían en la bandeja.
     */
    public function testLasSolicitudesDeProgramacionSeMudanConSuSalaYAllaSeBorraLaTabla(): void
    {
        $this->base->ejecutar('DELETE FROM solicitud_sala');
        self::programacionConSolicitudes($this->base);
        $sala = Uuid::nuevo();
        $deBaja = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO programacion.sala (id, nombre, descripcion, direccion, localidad, capacidad, peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, dada_de_baja_en)
             VALUES (?, 'El Galpón', 'Un galpón.', 'Calle 1', 'Luján', 40, 2, 120, 15, NULL), (?, 'La Vieja', 'Cerró.', 'Calle 2', 'Luján', 30, 1, 90, 10, NOW())",
            [$sala, $deBaja],
        );
        $rechazada = Uuid::nuevo();
        $pendiente = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO programacion.solicitud_sala (id, sala_id, usuario_id, solicitante_nombre, estado, enviada_en, resuelta_por, resuelta_en, motivo)
             VALUES (?, ?, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Eva Prueba', 'rechazada', '2026-09-01 10:00:00', 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', '2026-09-02 10:00:00', 'Falta la póliza.'),
                    (?, ?, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', NULL, 'pendiente', '2026-09-03 10:00:00', NULL, NULL, NULL),
                    (?, ?, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', NULL, 'pendiente', '2026-09-03 10:00:00', NULL, NULL, NULL)",
            [$rechazada, $sala, $pendiente, $sala, Uuid::nuevo(), $deBaja],
        );

        foreach (['002_solicitudes_de_programacion.sql', '003_sin_solicitudes_en_programacion.sql'] as $migracion) {
            $this->base->ejecutar((string) file_get_contents(dirname(__DIR__) . "/migraciones/{$migracion}"));
        }

        self::assertSame([
            ['id' => $rechazada, 'sala_id' => $sala, 'solicitante_nombre' => 'Eva Prueba', 'nombre' => 'El Galpón', 'capacidad' => 40, 'tiempo_entre_funciones' => 15, 'imagen' => null, 'estado' => 'rechazada', 'motivo' => 'Falta la póliza.'],
            ['id' => $pendiente, 'sala_id' => $sala, 'solicitante_nombre' => null, 'nombre' => 'El Galpón', 'capacidad' => 40, 'tiempo_entre_funciones' => 15, 'imagen' => null, 'estado' => 'pendiente', 'motivo' => null],
        ], $this->base->filas('SELECT id, sala_id, solicitante_nombre, nombre, capacidad, tiempo_entre_funciones, imagen, estado, motivo FROM solicitud_sala ORDER BY enviada_en'));
        self::assertSame(0, $this->base->valor("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'programacion' AND TABLE_NAME = 'solicitud_sala'"));
    }
}
