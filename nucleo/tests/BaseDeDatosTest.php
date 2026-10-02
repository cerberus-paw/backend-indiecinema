<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Pruebas\Dobles\RepositorioDePrueba;
use IndieCinema\Nucleo\Seguridad\Rol;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Corre contra un MySQL de verdad, porque lo que importa (las preparadas en el servidor, los
 * tipos, las transacciones) no se puede imitar. Sin las variables PRUEBAS_MYSQL_* se saltea; el
 * README dice cómo levantar uno con Docker.
 */
final class BaseDeDatosTest extends TestCase
{
    private BaseDeDatos $base;

    protected function setUp(): void
    {
        if (getenv('PRUEBAS_MYSQL_HOST') === false) {
            self::markTestSkipped('Sin PRUEBAS_MYSQL_HOST no hay MySQL para probar.');
        }

        $this->base = BaseDeDatos::conectar(new Configuracion(['base' => [
            'host' => getenv('PRUEBAS_MYSQL_HOST'),
            'puerto' => (int) (getenv('PRUEBAS_MYSQL_PUERTO') ?: 3306),
            'esquema' => getenv('PRUEBAS_MYSQL_ESQUEMA') ?: 'pruebas',
            'usuario' => getenv('PRUEBAS_MYSQL_USUARIO') ?: 'root',
            'clave' => (string) getenv('PRUEBAS_MYSQL_CLAVE'),
        ]]));
        // Temporal: es de esta conexión y desaparece con ella, así cada prueba arranca de cero.
        $this->base->ejecutar('CREATE TEMPORARY TABLE sala (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(100) NOT NULL UNIQUE,
            capacidad INT NOT NULL
        )');
        $this->base->ejecutar('INSERT INTO sala (nombre, capacidad) VALUES (?, ?), (?, ?)', ['El Galpón', 40, 'Cine Club Luján', 80]);
    }

    public function testUnRepositorioLeeConUnaConsultaPreparadaYLosNumerosVuelvenComoNumeros(): void
    {
        $sala = (new RepositorioDePrueba($this->base))->salaPorNombre('Cine Club Luján');

        self::assertSame(['id' => 2, 'nombre' => 'Cine Club Luján', 'capacidad' => 80], $sala);
    }

    public function testUnValorConComillasSeBuscaTalCualYNoCambiaLaConsulta(): void
    {
        self::assertNull((new RepositorioDePrueba($this->base))->salaPorNombre("x' OR '1'='1"));
    }

    public function testNoSePuedeEncadenarOtraSentencia(): void
    {
        try {
            $this->base->filas('SELECT 1; DELETE FROM sala');
            self::fail('Aceptó dos sentencias en una consulta.');
        } catch (PDOException) {
        }

        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM sala'));
    }

    public function testUnErrorDeSqlLanzaUnaExcepcion(): void
    {
        $this->expectException(PDOException::class);

        $this->base->filas('SELECT columna_que_no_existe FROM sala');
    }

    public function testUnEnteroSirveParaLimitYLosNombresVanConOSinDosPuntos(): void
    {
        $filas = $this->base->filas('SELECT nombre FROM sala WHERE capacidad >= :minima ORDER BY id LIMIT :cuantas', [
            'minima' => 10,
            ':cuantas' => 1,
        ]);

        self::assertSame([['nombre' => 'El Galpón']], $filas);
    }

    public function testLosEstadosLasFechasYLosNulosSeMandanTalCual(): void
    {
        self::assertSame('organizador', $this->base->valor('SELECT ?', [Rol::Organizador]));
        self::assertSame('2026-10-05 20:30:00', $this->base->valor('SELECT CAST(? AS CHAR)', [new DateTimeImmutable('2026-10-05 20:30')]));
        self::assertSame(1, $this->base->valor('SELECT ? IS NULL', [null]));
    }

    public function testSinFilasFilaYValorDevuelvenNull(): void
    {
        self::assertNull($this->base->fila('SELECT * FROM sala WHERE id = ?', [99]));
        self::assertNull($this->base->valor('SELECT id FROM sala WHERE id = ?', [99]));
    }

    public function testUnaTransaccionTerminadaQuedaYDevuelveSuResultado(): void
    {
        $id = $this->base->enTransaccion(static function (BaseDeDatos $base): string {
            $base->ejecutar('INSERT INTO sala (nombre, capacidad) VALUES (?, ?)', ['Sala nueva', 30]);

            return $base->ultimoId();
        });

        self::assertSame('3', $id);
        self::assertSame(3, $this->base->valor('SELECT COUNT(*) FROM sala'));
    }

    public function testUnaTransaccionQueFallaSeDeshaceYLaExcepcionSigue(): void
    {
        try {
            $this->base->enTransaccion(static function (BaseDeDatos $base): void {
                $base->ejecutar('UPDATE sala SET capacidad = 0');
                throw new RuntimeException('se cortó a la mitad');
            });
            self::fail('La excepción no siguió.');
        } catch (RuntimeException $error) {
            self::assertSame('se cortó a la mitad', $error->getMessage());
        }

        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sala WHERE capacidad = 0'));
    }
}
