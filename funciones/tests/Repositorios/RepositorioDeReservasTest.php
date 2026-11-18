<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Funciones\Modelo\AutorDelCambio;
use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Pruebas\ConBaseDePruebas;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Seguridad\Rol;
use PHPUnit\Framework\TestCase;

final class RepositorioDeReservasTest extends TestCase
{
    use ConBaseDePruebas;

    private BaseDeDatos $base;
    private RepositorioDeReservas $reservas;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
        $this->reservas = new RepositorioDeReservas($this->base);
    }

    /**
     * Lo que quedó en cambio_reserva, en orden. Ninguna pantalla lo muestra (E4-03 pide que el
     * cambio quede registrado), así que lo lee la prueba y no el repositorio.
     *
     * @return list<array<string, mixed>>
     */
    private function historial(string $reservaId): array
    {
        return $this->base->filas(
            'SELECT estado_anterior, estado_nuevo, autor_id, autor_rol, motivo, hecho_en FROM cambio_reserva WHERE reserva_id = ? ORDER BY hecho_en, id',
            [$reservaId],
        );
    }

    private static function espectador(): AutorDelCambio
    {
        return new AutorDelCambio('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', Rol::Espectador);
    }

    private static function organizador(): AutorDelCambio
    {
        return new AutorDelCambio('ffffffff-ffff-4fff-8fff-ffffffffffff', Rol::Organizador);
    }

    private function guardada(int $cantidad = 2, string $funcionId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', string $creada = '2026-10-20 12:00:00'): Reserva
    {
        $funcion = new FuncionDeProgramacion(
            $funcionId, 'programada', new DateTimeImmutable('2026-10-24 20:30:00'), 3500, 40,
            ['Zama'], true, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'Cine Club El Galpón', 'Luján', true, 'ffffffff-ffff-4fff-8fff-ffffffffffff',
        );
        $reserva = Reserva::nueva($funcion, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $cantidad, new DateTimeImmutable($creada));
        self::assertTrue($this->reservas->agregar($reserva));

        return $reserva;
    }

    public function testSeLeeComoSeGuardo(): void
    {
        $reserva = $this->guardada();

        self::assertEquals($reserva, $this->reservas->buscar($reserva->id));
        self::assertNull($this->reservas->buscar('cccccccc-cccc-4ccc-8ccc-cccccccccccc'));
    }

    private function pendiente(): Reserva
    {
        $funcion = new FuncionDeProgramacion(
            'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'programada', new DateTimeImmutable('2026-10-24 20:30:00'), 3500, 40,
            ['Zama'], true, 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'Cine Club El Galpón', 'Luján', true, 'ffffffff-ffff-4fff-8fff-ffffffffffff',
        );
        $reserva = Reserva::nueva($funcion, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 2, new DateTimeImmutable('2026-10-20 12:00:00'), 15);
        self::assertTrue($this->reservas->agregar($reserva));

        return $reserva;
    }

    public function testUnaPendienteSeLeeConSuVencimientoYSuPreferencia(): void
    {
        $reserva = $this->pendiente()->conPreferencia('123-abc', 'https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=123-abc');

        self::assertTrue($this->reservas->guardarPreferencia($reserva));
        self::assertEquals($reserva, $this->reservas->buscar($reserva->id));
        // Mientras espera el pago, y está a tiempo, ocupa sus lugares.
        self::assertSame(2, $this->reservas->lugaresOcupados($reserva->funcionId, new DateTimeImmutable('2026-10-20 12:10:00')));
    }

    /**
     * Si el espectador la canceló mientras se creaba la preferencia, ya no hay qué cobrar.
     */
    public function testLaPreferenciaNoSeAnotaEnUnaQueYaNoEsperaElPago(): void
    {
        $reserva = $this->pendiente();
        self::assertTrue($this->reservas->guardarEstado($reserva->cancelar(), EstadoReserva::PendientePago, self::espectador()));

        self::assertFalse($this->reservas->guardarPreferencia($reserva->conPreferencia('123-abc', 'https://mp.test')));
        self::assertNull($this->reservas->buscar($reserva->id)?->preferenciaId);
    }

    public function testElEstadoNuevoQuedaGuardadoYLaCanceladaSueltaSusLugares(): void
    {
        $reserva = $this->guardada(3);
        self::assertSame(3, $this->reservas->lugaresOcupados($reserva->funcionId));

        self::assertTrue($this->reservas->guardarEstado($reserva->cancelar(), EstadoReserva::Confirmada, self::espectador()));

        self::assertSame(EstadoReserva::Cancelada, $this->reservas->buscar($reserva->id)?->estado);
        self::assertSame(0, $this->reservas->lugaresOcupados($reserva->funcionId));
    }

    public function testQuienEntroSigueOcupandoSusLugares(): void
    {
        $reserva = $this->guardada(3);

        self::assertTrue($this->reservas->guardarEstado($reserva->registrarAsistencia(), EstadoReserva::Confirmada, self::organizador()));

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

        self::assertTrue($this->reservas->guardarEstado($leidaPorElOrganizador->registrarAsistencia(), EstadoReserva::Confirmada, self::organizador()));
        self::assertFalse($this->reservas->guardarEstado($leidaPorElEspectador->cancelar(), EstadoReserva::Confirmada, self::espectador()));

        self::assertSame(EstadoReserva::Asistio, $this->reservas->buscar($leidaPorElEspectador->id)?->estado);
    }

    public function testCadaCambioQuedaEnElHistorialConSuAutor(): void
    {
        $reserva = $this->guardada();

        $this->reservas->guardarEstado($reserva->registrarAsistencia(), EstadoReserva::Confirmada, self::organizador(), new DateTimeImmutable('2026-10-24 20:10:00', new DateTimeZone('America/Argentina/Buenos_Aires')));

        self::assertSame([[
            'estado_anterior' => 'confirmada',
            'estado_nuevo' => 'asistio',
            'autor_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
            'autor_rol' => 'organizador',
            'motivo' => null,
            'hecho_en' => '2026-10-24 23:10:00',
        ]], $this->historial($reserva->id));
    }

    public function testUnCambioQueNoSeGuardoNoQuedaEnElHistorial(): void
    {
        $reserva = $this->guardada();
        $this->reservas->guardarEstado($reserva->cancelar(), EstadoReserva::Confirmada, self::espectador());

        self::assertFalse($this->reservas->guardarEstado($reserva->registrarAsistencia(), EstadoReserva::Confirmada, self::organizador()));
        self::assertCount(1, $this->historial($reserva->id));
    }

    public function testLasDeUnaFuncionYPorSuCodigo(): void
    {
        $primera = $this->guardada(creada: '2026-10-20 12:00:00');
        $segunda = $this->guardada(creada: '2026-10-21 12:00:00');
        $deOtra = $this->guardada(funcionId: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc');

        self::assertSame([$primera->id, $segunda->id], array_column($this->reservas->deLaFuncion($primera->funcionId), 'id'));
        self::assertSame($segunda->id, $this->reservas->porCodigo($primera->funcionId, $segunda->codigo)?->id);
        // El código de otra función no sirve.
        self::assertNull($this->reservas->porCodigo($primera->funcionId, $deOtra->codigo));
    }

    public function testLaPaginaDelAdministradorDeLaMasNuevaALaMasViejaYPorEstado(): void
    {
        $vieja = $this->guardada(creada: '2026-10-20 12:00:00');
        $nueva = $this->guardada(creada: '2026-10-22 12:00:00');
        $cancelada = $this->guardada(creada: '2026-10-21 12:00:00');
        $this->reservas->guardarEstado($cancelada->cancelar(), EstadoReserva::Confirmada, self::espectador());

        [$todas, $total] = $this->reservas->pagina(null, 1, 2);
        self::assertSame([$nueva->id, $cancelada->id], array_column($todas, 'id'));
        self::assertSame(3, $total);
        [$segunda] = $this->reservas->pagina(null, 2, 2);
        self::assertSame([$vieja->id], array_column($segunda, 'id'));

        [$canceladas, $cuantas] = $this->reservas->pagina(EstadoReserva::Cancelada, 1, 10);
        self::assertSame([$cancelada->id], array_column($canceladas, 'id'));
        self::assertSame(1, $cuantas);
    }
}
