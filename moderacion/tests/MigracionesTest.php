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
}
