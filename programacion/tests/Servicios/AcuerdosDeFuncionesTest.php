<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Servicios;

use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Pruebas\ConFuncionesDePrueba;
use IndieCinema\Programacion\Servicios\AcuerdosDeFunciones;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AcuerdosDeFuncionesTest extends TestCase
{
    use ConFuncionesDePrueba;

    private const FUNCION_ID = '11111111-1111-4111-8111-111111111111';

    public static function setUpBeforeClass(): void
    {
        self::setUpBeforeClassFunciones();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownAfterClassFunciones();
    }

    private function servicio(): AcuerdosDeFunciones
    {
        return self::acuerdosDeFunciones();
    }

    public function testRechazaIdDeFuncionInvalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El ID de la función no es válido.');
        $this->servicio()->proponer('id-invalido', 10, [['fecha_hora_inicio' => '2026-10-25 20:00:00']]);
    }

    public function testRechazaMinimoDeAsistentesCeroONegativo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('El mínimo de asistentes debe ser mayor a cero.');
        $this->servicio()->proponer(self::FUNCION_ID, 0, [['fecha_hora_inicio' => '2026-10-25 20:00:00']]);
    }

    public function testRechazaSinFranjas(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Debe proponer al menos una franja horaria.');
        $this->servicio()->proponer(self::FUNCION_ID, 15, []);
    }

    public function testCreaAcuerdoEnFuncionesPorLaApiInterna(): void
    {
        $franjas = [
            ['fecha_hora_inicio' => '2026-10-25 20:00:00', 'fecha_hora_fin' => '2026-10-25 22:30:00'],
            ['fecha_hora_inicio' => '2026-10-26 21:00:00', 'fecha_hora_fin' => null],
        ];

        $resultado = $this->servicio()->proponer(self::FUNCION_ID, 20, $franjas);

        self::assertSame(self::FUNCION_ID, $resultado['funcion_id']);
        self::assertSame(20, $resultado['minimo_asistentes']);
        self::assertSame('abierto', $resultado['estado']);
        self::assertCount(2, $resultado['franjas']);
    }

    public function testConsultaAcuerdoPorFuncion(): void
    {
        $resultado = $this->servicio()->obtenerPorFuncion(self::FUNCION_ID, 'user-1');

        self::assertNotNull($resultado);
        self::assertSame('acuerdo-mock-1', $resultado['id']);
        self::assertSame('franja-mock-0', $resultado['voto_usuario_franja_id']);
        self::assertCount(2, $resultado['franjas']);
    }
}
