<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Pruebas\Modelo;

use IndieCinema\Recaudacion\Modelo\CobroDeReserva;
use IndieCinema\Recaudacion\Modelo\CobrosDeFuncion;
use IndieCinema\Recaudacion\Modelo\VentasDeFuncion;
use PHPUnit\Framework\TestCase;

/**
 * Lo vendido de una función para los reportes, sacado de lo que manda funciones.
 */
final class VentasDeFuncionTest extends TestCase
{
    private static function reserva(string $usuario, int $cantidad, string $estado, bool $reembolsada = false): CobroDeReserva
    {
        return new CobroDeReserva('reserva-' . $usuario, $usuario, $cantidad, '5000.00', '5000.00', 'pago-' . $usuario, $estado, $reembolsada);
    }

    public function testCuentaLosLugaresLosQueFueronYQuienesCompraron(): void
    {
        $ventas = VentasDeFuncion::deLosCobros(new CobrosDeFuncion('funcion', [
            self::reserva('ana', 2, 'asistio'),
            self::reserva('beto', 1, 'ausente'),
            self::reserva('ana', 1, 'confirmada'),
        ], '20000.00'));

        self::assertSame([4, 2, ['ana', 'beto']], [$ventas->vendidas, $ventas->asistentes, $ventas->compradoresIds]);
    }

    public function testUnaCanceladaNoOcupaLugarNiEsDeAlguienQueVino(): void
    {
        // Funciones la manda porque se había pagado: cuenta para el reparto, no para la ocupación.
        $ventas = VentasDeFuncion::deLosCobros(new CobrosDeFuncion('funcion', [
            self::reserva('ana', 2, 'asistio'),
            self::reserva('beto', 3, 'cancelada', reembolsada: true),
            self::reserva('caro', 1, 'cancelada'),
        ], '10000.00'));

        self::assertSame([2, 2, ['ana']], [$ventas->vendidas, $ventas->asistentes, $ventas->compradoresIds]);
        self::assertSame('10000.00', $ventas->cobrado);
    }
}
