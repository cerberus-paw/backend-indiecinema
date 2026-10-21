<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Modelo;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use PHPUnit\Framework\TestCase;

final class ReservaTest extends TestCase
{
    private const ESPECTADOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private function funcion(string $estado = 'programada', ?string $fechaHora = '2026-10-24 20:30:00', float $precio = 3500): FuncionDeProgramacion
    {
        return new FuncionDeProgramacion(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            $estado,
            $fechaHora === null ? null : new DateTimeImmutable($fechaHora),
            $precio,
            40,
            ['Zama'],
            true,
            'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'Cine Club El Galpón',
            'Luján',
            true,
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
        );
    }

    private function antes(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-20 12:00:00');
    }

    public function testQuedaConfirmadaConElTotalDeLosLugaresYUnCodigoParaLaPuerta(): void
    {
        $reserva = Reserva::nueva($this->funcion(), self::ESPECTADOR, 3, $this->antes());

        self::assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $reserva->funcionId);
        self::assertSame(self::ESPECTADOR, $reserva->usuarioId);
        self::assertSame(3, $reserva->cantidad);
        self::assertSame('10500.00', $reserva->total);
        self::assertSame(EstadoReserva::Confirmada, $reserva->estado);
        self::assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{8}$/D', $reserva->codigo);
        self::assertSame('UTC', $reserva->creadaEn->getTimezone()->getName());
    }

    public function testCadaReservaTieneSuIdYSuCodigo(): void
    {
        $una = Reserva::nueva($this->funcion(), self::ESPECTADOR, 1, $this->antes());
        $otra = Reserva::nueva($this->funcion(), self::ESPECTADOR, 1, $this->antes());

        self::assertNotSame($una->id, $otra->id);
        self::assertNotSame($una->codigo, $otra->codigo);
        self::assertNotSame($una->codigo, $una->conOtroCodigo()->codigo);
        self::assertSame($una->id, $una->conOtroCodigo()->id);
    }

    public function testUnaFuncionALaGorraTotalizaCero(): void
    {
        self::assertSame('0.00', Reserva::nueva($this->funcion(precio: 0), self::ESPECTADOR, 2, $this->antes())->total);
    }

    public function testSeReservaMientrasLaFuncionTodaviaNoTieneFecha(): void
    {
        foreach (['en_votacion_titulos', 'en_acuerdo_fecha'] as $estado) {
            $reserva = Reserva::nueva($this->funcion($estado, null), self::ESPECTADOR, 1, $this->antes());
            self::assertSame(EstadoReserva::Confirmada, $reserva->estado);
        }
    }

    public function testHayQueReservarAlMenosUnLugar(): void
    {
        foreach ([0, -2] as $cantidad) {
            try {
                Reserva::nueva($this->funcion(), self::ESPECTADOR, $cantidad, $this->antes());
                self::fail("Aceptó reservar {$cantidad} lugares.");
            } catch (ReglaIncumplida $error) {
                self::assertSame('Reservá al menos un lugar.', $error->getMessage());
            }
        }
    }

    public function testUnaFuncionCanceladaNoSeReserva(): void
    {
        $this->expectException(ReglaIncumplida::class);
        $this->expectExceptionMessage('La función se canceló');

        Reserva::nueva($this->funcion('cancelada'), self::ESPECTADOR, 1, $this->antes());
    }

    public function testUnaFuncionQueYaEmpezoOTerminoNoSeReserva(): void
    {
        $casos = [
            'finalizada' => [$this->funcion('finalizada'), $this->antes()],
            'a la hora justa' => [$this->funcion(), new DateTimeImmutable('2026-10-24 20:30:00')],
            'programada y vencida' => [$this->funcion(), new DateTimeImmutable('2026-10-24 21:00:00')],
        ];
        foreach ($casos as $caso => [$funcion, $ahora]) {
            try {
                Reserva::nueva($funcion, self::ESPECTADOR, 1, $ahora);
                self::fail("Aceptó reservar una función {$caso}.");
            } catch (ReglaIncumplida $error) {
                self::assertSame('La función ya empezó: ya no se puede reservar.', $error->getMessage());
            }
        }
    }

    public function testUnaConfirmadaSeCancelaOQuedaConAsistencia(): void
    {
        $reserva = Reserva::nueva($this->funcion(), self::ESPECTADOR, 2, $this->antes());

        $cancelada = $reserva->cancelar();
        $asistio = $reserva->registrarAsistencia();

        self::assertSame(EstadoReserva::Cancelada, $cancelada->estado);
        self::assertSame(EstadoReserva::Asistio, $asistio->estado);
        // El resto no cambia, y la original tampoco: el repositorio necesita saber de dónde venía.
        self::assertSame([$reserva->id, $reserva->codigo, $reserva->cantidad, $reserva->total], [$cancelada->id, $cancelada->codigo, $cancelada->cantidad, $cancelada->total]);
        self::assertSame(EstadoReserva::Confirmada, $reserva->estado);
    }

    public function testCanceladaYConAsistenciaNoCambianMas(): void
    {
        $reserva = Reserva::nueva($this->funcion(), self::ESPECTADOR, 1, $this->antes());
        $casos = [
            'Una reserva cancelada no puede quedar cancelada.' => fn () => $reserva->cancelar()->cancelar(),
            'Una reserva cancelada no puede quedar con asistencia.' => fn () => $reserva->cancelar()->registrarAsistencia(),
            'Una reserva con asistencia no puede quedar cancelada.' => fn () => $reserva->registrarAsistencia()->cancelar(),
            'Una reserva con asistencia no puede quedar con asistencia.' => fn () => $reserva->registrarAsistencia()->registrarAsistencia(),
        ];
        foreach ($casos as $mensaje => $paso) {
            try {
                $paso();
                self::fail("Pasó: {$mensaje}");
            } catch (ReglaIncumplida $error) {
                self::assertSame($mensaje, $error->getMessage());
            }
        }
    }

    public function testLaCanceladaEsLaUnicaQueNoOcupaLugares(): void
    {
        self::assertTrue(EstadoReserva::Confirmada->ocupaLugares());
        self::assertTrue(EstadoReserva::Asistio->ocupaLugares());
        self::assertFalse(EstadoReserva::Cancelada->ocupaLugares());
    }
}
