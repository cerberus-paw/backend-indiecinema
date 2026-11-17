<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * La tabla parametro_reparto tal como la dejan las migraciones, contra un MySQL de verdad (EF-08.1):
 * lo que se prueba son las restricciones de la base, no las de la aplicación. La aplicación avisa
 * antes, pero un reparto que no suma 100 % dejaría plata sin dueño, así que lo rechaza la base.
 */
final class MigracionesTest extends TestCase
{
    use ConBaseDePruebas;

    private const CLAVE_DUPLICADA = 1062;
    private const RESTRICCION_INCUMPLIDA = 3819;

    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
    }

    /**
     * @param array{string, string, string} $porcentajes sala, dueño y plataforma
     */
    private function cargar(array $porcentajes, string $vigenteDesde = '2026-12-01 00:00:00'): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO parametro_reparto (id, sala, dueno, plataforma, vigente_desde, cargado_por)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, ...$porcentajes, $vigenteDesde, Uuid::nuevo()],
        );

        return $id;
    }

    private function assertRechaza(int $codigo, callable $escritura): void
    {
        try {
            $escritura();
            self::fail("La base aceptó una escritura que tenía que rechazar con el error {$codigo}.");
        } catch (PDOException $e) {
            self::assertSame($codigo, $e->errorInfo[1] ?? null, $e->getMessage());
        }
    }

    public function testLaMigracionDejaCargadoElSetentaQuinceQuinceDeLaPropuesta(): void
    {
        $fila = $this->base->fila('SELECT sala, dueno, plataforma, vigente_desde, cargado_por FROM parametro_reparto');

        self::assertNotNull($fila);
        self::assertSame(['70.00', '15.00', '15.00'], [(string) $fila['sala'], (string) $fila['dueno'], (string) $fila['plataforma']]);
        // Anterior a cualquier función del sitio: toda función finalizada tiene un parámetro.
        self::assertSame('2000-01-01 00:00:00', (string) $fila['vigente_desde']);
        // No lo cargó un administrador, lo cargó la migración.
        self::assertNull($fila['cargado_por']);
    }

    public function testElRepartoQueNoSumaCienNoEntra(): void
    {
        $this->assertRechaza(self::RESTRICCION_INCUMPLIDA, fn () => $this->cargar(['70.00', '15.00', '14.00']));
        $this->assertRechaza(self::RESTRICCION_INCUMPLIDA, fn () => $this->cargar(['70.00', '15.00', '16.00']));
        // Con dos decimales la suma tiene que dar exacta: 33,33 + 33,33 + 33,33 son 99,99.
        $this->assertRechaza(self::RESTRICCION_INCUMPLIDA, fn () => $this->cargar(['33.33', '33.33', '33.33']));

        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM parametro_reparto'));
    }

    public function testElRepartoConDecimalesQueSumanCienEntra(): void
    {
        $this->cargar(['33.33', '33.33', '33.34']);

        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM parametro_reparto'));
    }

    public function testNingunaParteDelRepartoPuedeSerNegativa(): void
    {
        $this->assertRechaza(self::RESTRICCION_INCUMPLIDA, fn () => $this->cargar(['110.00', '-5.00', '-5.00']));
    }

    public function testDosRepartosNoPuedenEmpezarAVivirEnElMismoMomento(): void
    {
        $this->cargar(['60.00', '20.00', '20.00'], '2026-12-01 00:00:00');

        // Con dos, «cuál rige en esta fecha» no tendría una sola respuesta.
        $this->assertRechaza(
            self::CLAVE_DUPLICADA,
            fn () => $this->cargar(['50.00', '25.00', '25.00'], '2026-12-01 00:00:00'),
        );
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM parametro_reparto'));
    }
}
