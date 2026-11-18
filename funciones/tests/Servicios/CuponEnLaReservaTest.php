<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Pruebas\ConBaseDePruebas;
use IndieCinema\Funciones\Pruebas\ConProgramacionDePrueba;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Funciones\Servicios\CuponesDeRecaudacion;
use IndieCinema\Funciones\Servicios\ServicioDeReservas;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * El cupón de descuento en la reserva (EF-17.1), contra un MySQL de verdad: descuenta, se usa una
 * sola vez, vuelve a quedar libre si la reserva se cancela, y sin cupón la reserva es la de
 * siempre. Recaudación es una clase de mentira que conoce dos cupones.
 */
final class CuponEnLaReservaTest extends TestCase
{
    use ConBaseDePruebas;
    use ConProgramacionDePrueba;

    private const PROGRAMADA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const A_LA_GORRA = '78787878-7878-4878-8878-787878787878';
    private const DE_EVA = 'EVA23456';
    private const VENCIDO = 'VIEJ2345';

    private BaseDeDatos $base;
    private Usuario $eva;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
        $this->eva = new Usuario('77777777-7777-4777-8777-777777777777', Rol::Espectador);
    }

    /** El mismo momento que las otras pruebas de reservas: antes de las funciones del doble. */
    private static function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-20 12:00:00');
    }

    private function servicio(): ServicioDeReservas
    {
        $eva = $this->eva->id;
        $cupones = new class ($eva) extends CuponesDeRecaudacion {
            public function __construct(private readonly string $eva)
            {
                parent::__construct(new Configuracion([]), new Log('funciones', fopen('php://memory', 'w')));
            }

            public function buscar(string $codigo): ?array
            {
                return match ($codigo) {
                    'EVA23456' => ['usuario_id' => $this->eva, 'porcentaje' => 20, 'vence_en' => new DateTimeImmutable('2030-01-01')],
                    'VIEJ2345' => ['usuario_id' => $this->eva, 'porcentaje' => 20, 'vence_en' => new DateTimeImmutable('2020-01-01')],
                    default => null,
                };
            }
        };

        return new ServicioDeReservas(self::funcionesDeProgramacion(), new RepositorioDeReservas($this->base), cupones: $cupones);
    }

    public function testSinCuponLaReservaEsLaDeSiempre(): void
    {
        $reserva = $this->servicio()->reservar($this->eva, self::PROGRAMADA, 2, ahora: self::ahora());

        self::assertSame('7000.00', $reserva->total);
        self::assertNull($reserva->cupon);
        self::assertNull($this->base->valor('SELECT cupon FROM reserva WHERE id = ?', [$reserva->id]));
    }

    public function testElCuponDescuentaElPorcentajeEnCadaLugar(): void
    {
        $reserva = $this->servicio()->reservar($this->eva, self::PROGRAMADA, 3, cupon: self::DE_EVA, ahora: self::ahora());

        // 3500 menos el 20 % son 2800 por lugar: 8400 por tres, divisible justo por la cantidad,
        // que es como lo cobra Mercado Pago.
        self::assertSame('8400.00', $reserva->total);
        self::assertSame(self::DE_EVA, $this->base->valor('SELECT cupon FROM reserva WHERE id = ?', [$reserva->id]));
    }

    public function testSeUsaUnaSolaVezYVuelveSiSeCancelaLaReserva(): void
    {
        $primera = $this->servicio()->reservar($this->eva, self::PROGRAMADA, 1, cupon: self::DE_EVA, ahora: self::ahora());
        try {
            $this->servicio()->reservar($this->eva, self::PROGRAMADA, 1, cupon: self::DE_EVA, ahora: self::ahora());
            self::fail('Usó el mismo cupón dos veces.');
        } catch (ReglaIncumplida $error) {
            self::assertSame(RepositorioDeReservas::CUPON_EN_USO, $error->getMessage());
        }

        $this->servicio()->cancelar($this->eva, $primera->id, self::ahora());
        $otra = $this->servicio()->reservar($this->eva, self::PROGRAMADA, 1, cupon: self::DE_EVA, ahora: self::ahora());
        self::assertSame('2800.00', $otra->total);
    }

    public function testLaBaseNoDejaDosReservasEnPieConElMismoCupon(): void
    {
        $this->servicio()->reservar($this->eva, self::PROGRAMADA, 1, cupon: self::DE_EVA, ahora: self::ahora());

        // Como si la otra reserva hubiera pasado el control a la vez: lo ataja el índice único.
        $funcion = self::funcionesDeProgramacion()->buscar(self::PROGRAMADA, $this->eva->id);
        self::assertNotNull($funcion);
        $this->expectException(ReglaIncumplida::class);
        (new RepositorioDeReservas($this->base))->agregar(Reserva::nueva($funcion, $this->eva->id, 1, self::ahora())->conCupon(self::DE_EVA, 20, 3500));
    }

    public function testRechazaElCuponAjenoElVencidoYElDeUnaFuncionALaGorra(): void
    {
        $otro = new Usuario(Uuid::nuevo(), Rol::Espectador);
        foreach ([
            [$otro, self::PROGRAMADA, self::DE_EVA, 'Ese cupón no existe.'],
            [$this->eva, self::PROGRAMADA, 'NOEXISTE', 'Ese cupón no existe.'],
            [$this->eva, self::PROGRAMADA, self::VENCIDO, 'Ese cupón ya venció.'],
            [$this->eva, self::A_LA_GORRA, self::DE_EVA, 'Esta función no se paga'],
        ] as [$quien, $funcion, $cupon, $mensaje]) {
            try {
                $this->servicio()->reservar($quien, $funcion, 1, cupon: $cupon, ahora: self::ahora());
                self::fail("Aceptó el cupón {$cupon}.");
            } catch (ReglaIncumplida $error) {
                self::assertStringStartsWith($mensaje, $error->getMessage());
            }
        }
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM reserva'));
    }
}
