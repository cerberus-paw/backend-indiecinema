<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Las tablas del reparto tal como las dejan las migraciones, contra un MySQL de verdad: el
 * parámetro con su vigencia (EF-08.1) y los repartos con sus partes (EF-08.2). Lo que se prueba
 * son las restricciones de la base, no las de la aplicación. La aplicación avisa antes, pero un
 * reparto que no suma 100 %, o repetido, dejaría plata sin dueño o la pagaría dos veces, así que
 * también lo rechaza la base.
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

    /**
     * Un reparto de una función, con el parámetro de la migración (EF-08.2).
     *
     * @return string el id
     */
    private function repartir(string $funcionId, string $cobrado = '10000.00', ?string $parametroId = null): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO reparto (id, funcion_id, cobrado, parametro_id, finalizada_en)
             VALUES (?, ?, ?, ?, ?)',
            [
                $id,
                $funcionId,
                $cobrado,
                $parametroId ?? (string) $this->base->valor('SELECT id FROM parametro_reparto ORDER BY vigente_desde LIMIT 1'),
                '2026-11-20 23:20:00',
            ],
        );

        return $id;
    }

    private function agregarParte(string $repartoId, string $rol, ?string $beneficiarioId, string $monto): void
    {
        $this->base->ejecutar(
            'INSERT INTO reparto_parte (id, reparto_id, rol, beneficiario_id, monto) VALUES (?, ?, ?, ?, ?)',
            [Uuid::nuevo(), $repartoId, $rol, $beneficiarioId, $monto],
        );
    }

    public function testUnaFuncionNoPuedeTenerDosRepartos(): void
    {
        $funcion = Uuid::nuevo();
        $this->repartir($funcion);

        // Es lo que hace que correr la tarea de más no reparta dos veces lo mismo.
        $this->assertRechaza(self::CLAVE_DUPLICADA, fn () => $this->repartir($funcion));
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM reparto'));
    }

    public function testUnRepartoNoPuedeSerNegativoNiSusPartes(): void
    {
        $reparto = $this->repartir(Uuid::nuevo());

        $this->assertRechaza(self::RESTRICCION_INCUMPLIDA, fn () => $this->repartir(Uuid::nuevo(), '-1.00'));
        $this->assertRechaza(self::RESTRICCION_INCUMPLIDA, fn () => $this->agregarParte($reparto, 'sala', Uuid::nuevo(), '-0.01'));
    }

    /**
     * La plataforma es el sitio y no tiene a quién transferirle; la sala y el dueño, sí. Una parte
     * sin beneficiario que no sea de la plataforma sería plata sin dueño.
     */
    public function testSoloLaParteDeLaPlataformaVaSinBeneficiario(): void
    {
        $reparto = $this->repartir(Uuid::nuevo());

        $this->agregarParte($reparto, 'plataforma', null, '1500.00');
        $this->assertRechaza(self::RESTRICCION_INCUMPLIDA, fn () => $this->agregarParte($reparto, 'plataforma', Uuid::nuevo(), '1500.00'));
        $this->assertRechaza(self::RESTRICCION_INCUMPLIDA, fn () => $this->agregarParte($reparto, 'dueno', null, '1500.00'));
    }

    public function testUnBeneficiarioTieneUnaSolaParteDeCadaRolEnUnReparto(): void
    {
        $reparto = $this->repartir(Uuid::nuevo());
        $organizador = Uuid::nuevo();

        // El organizador de la sala puede ser además el dueño de la obra: son dos partes distintas.
        $this->agregarParte($reparto, 'sala', $organizador, '7000.00');
        $this->agregarParte($reparto, 'dueno', $organizador, '1500.00');

        // Dos veces la misma, no: las obras de un mismo dueño van sumadas en una.
        $this->assertRechaza(self::CLAVE_DUPLICADA, fn () => $this->agregarParte($reparto, 'dueno', $organizador, '1500.00'));
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM reparto_parte'));
    }

    public function testBorrarUnRepartoSeLlevaSusPartesYBorrarElParametroNoSeLoLleva(): void
    {
        $reparto = $this->repartir(Uuid::nuevo());
        $this->agregarParte($reparto, 'plataforma', null, '1500.00');

        // El parámetro no se borra nunca, pero si se borrara, el reparto ya hecho se queda.
        $this->base->ejecutar('DELETE FROM parametro_reparto');
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM reparto'));
        self::assertNull($this->base->valor('SELECT parametro_id FROM reparto WHERE id = ?', [$reparto]));

        $this->base->ejecutar('DELETE FROM reparto WHERE id = ?', [$reparto]);
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM reparto_parte'));
    }

    private function liquidar(string $beneficiarioId, string $periodo = '2026-11', string $monto = '7070.00'): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO liquidacion (id, beneficiario_id, periodo, monto) VALUES (?, ?, ?, ?)',
            [$id, $beneficiarioId, $periodo, $monto],
        );

        return $id;
    }

    public function testUnBeneficiarioTieneUnaSolaLiquidacionPorMes(): void
    {
        $beneficiario = Uuid::nuevo();
        $this->liquidar($beneficiario);

        // La tarea rearma la del mes en vez de agregar otra: si no, se pagaría dos veces.
        $this->assertRechaza(self::CLAVE_DUPLICADA, fn () => $this->liquidar($beneficiario));
        // Otro mes del mismo beneficiario sí es otra liquidación.
        $this->liquidar($beneficiario, '2026-10');
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM liquidacion'));
    }

    public function testUnaLiquidacionNoPuedeSerNegativa(): void
    {
        $this->assertRechaza(self::RESTRICCION_INCUMPLIDA, fn () => $this->liquidar(Uuid::nuevo(), monto: '-1.00'));
    }

    /**
     * El estado y la fecha no se pueden contradecir: una transferida sin fecha no diría cuándo se
     * pagó, y una pendiente con fecha diría que se pagó sin estarlo.
     */
    public function testUnaLiquidacionTransferidaTieneFechaYUnaPendienteNo(): void
    {
        $id = $this->liquidar(Uuid::nuevo());

        $this->assertRechaza(
            self::RESTRICCION_INCUMPLIDA,
            fn () => $this->base->ejecutar("UPDATE liquidacion SET estado = 'transferida' WHERE id = ?", [$id]),
        );
        $this->assertRechaza(
            self::RESTRICCION_INCUMPLIDA,
            fn () => $this->base->ejecutar('UPDATE liquidacion SET transferida_en = NOW() WHERE id = ?', [$id]),
        );

        $this->base->ejecutar("UPDATE liquidacion SET estado = 'transferida', transferida_en = NOW() WHERE id = ?", [$id]);
        self::assertSame('transferida', (string) $this->base->valor('SELECT estado FROM liquidacion WHERE id = ?', [$id]));
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
