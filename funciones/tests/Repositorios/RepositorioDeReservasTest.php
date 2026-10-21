<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Repositorios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Pruebas\ConBaseDePruebas;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Nucleo\Configuracion;
use PHPUnit\Framework\TestCase;

final class RepositorioDeReservasTest extends TestCase
{
    use ConBaseDePruebas;

    private RepositorioDeReservas $reservas;

    protected function setUp(): void
    {
        $this->reservas = new RepositorioDeReservas(self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()])));
    }

    private function guardada(int $cantidad = 2): Reserva
    {
        $funcion = new FuncionDeProgramacion(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'programada', new DateTimeImmutable('2026-10-24 20:30:00'), 3500, 40,
            ['Zama'], true, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'Cine Club El Galpón', 'Luján', true, 'ffffffff-ffff-4fff-8fff-ffffffffffff',
        );
        $reserva = Reserva::nueva($funcion, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $cantidad, new DateTimeImmutable('2026-10-20 12:00:00'));
        self::assertTrue($this->reservas->agregar($reserva));

        return $reserva;
    }

    public function testSeLeeComoSeGuardo(): void
    {
        $reserva = $this->guardada();

        self::assertEquals($reserva, $this->reservas->buscar($reserva->id));
        self::assertNull($this->reservas->buscar('cccccccc-cccc-4ccc-8ccc-cccccccccccc'));
    }

    public function testElEstadoNuevoQuedaGuardadoYLaCanceladaSueltaSusLugares(): void
    {
        $reserva = $this->guardada(3);
        self::assertSame(3, $this->reservas->lugaresOcupados($reserva->funcionId));

        self::assertTrue($this->reservas->guardarEstado($reserva->cancelar(), EstadoReserva::Confirmada));

        self::assertSame(EstadoReserva::Cancelada, $this->reservas->buscar($reserva->id)?->estado);
        self::assertSame(0, $this->reservas->lugaresOcupados($reserva->funcionId));
    }

    public function testQuienEntroSigueOcupandoSusLugares(): void
    {
        $reserva = $this->guardada(3);

        self::assertTrue($this->reservas->guardarEstado($reserva->registrarAsistencia(), EstadoReserva::Confirmada));

        self::assertSame(3, $this->reservas->lugaresOcupados($reserva->funcionId));
    }

    /**
     * El espectador cancela mientras el organizador registra la asistencia, los dos sobre la
     * reserva confirmada que leyeron: gana el primero y el segundo no la pisa.
     */
    public function testSiOtroLaCambioAntesNoLaPisa(): void
    {
        $leidaPorElEspectador = $this->guardada();
        $leidaPorElOrganizador = $this->reservas->buscar($leidaPorElEspectador->id);
        self::assertNotNull($leidaPorElOrganizador);

        self::assertTrue($this->reservas->guardarEstado($leidaPorElOrganizador->registrarAsistencia(), EstadoReserva::Confirmada));
        self::assertFalse($this->reservas->guardarEstado($leidaPorElEspectador->cancelar(), EstadoReserva::Confirmada));

        self::assertSame(EstadoReserva::Asistio, $this->reservas->buscar($leidaPorElEspectador->id)?->estado);
    }
}
