<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Pruebas\ConBaseDePruebas;
use IndieCinema\Funciones\Pruebas\ConProgramacionDePrueba;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Funciones\Servicios\ServicioDeReservas;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Las reservas contra un MySQL de verdad y una programación de mentira, que da 40 lugares a cada
 * función: el cupo se controla en la base, con otras reservas de por medio.
 */
final class ServicioDeReservasTest extends TestCase
{
    use ConBaseDePruebas;
    use ConProgramacionDePrueba;

    private const PROGRAMADA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const SIN_PUBLICAR = '99999999-9999-4999-8999-999999999999';
    private const CUPO = 40;

    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
    }

    private function servicio(): ServicioDeReservas
    {
        return new ServicioDeReservas(self::funcionesDeProgramacion(), new RepositorioDeReservas($this->base));
    }

    private function reservar(int $cantidad, string $funcionId = self::PROGRAMADA): Reserva
    {
        return $this->servicio()->reservar(new Usuario(Uuid::nuevo(), Rol::Espectador), $funcionId, $cantidad, new DateTimeImmutable('2026-10-20 12:00:00'));
    }

    /**
     * Ocupa lugares sin pasar por el servicio, como si ya los hubieran reservado otros.
     */
    private function ocupar(int $cantidad, string $estado = 'confirmada'): void
    {
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [Uuid::nuevo(), self::PROGRAMADA, Uuid::nuevo(), strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), $cantidad, '0.00', $estado, '2026-10-19 10:00:00'],
        );
    }

    private function confirmadas(): int
    {
        return (int) $this->base->valor("SELECT COALESCE(SUM(cantidad), 0) FROM reserva WHERE funcion_id = ? AND estado <> 'cancelada'", [self::PROGRAMADA]);
    }

    public function testLaReservaQuedaGuardadaConfirmada(): void
    {
        $reserva = $this->reservar(2);

        $guardada = $this->base->fila('SELECT * FROM reserva WHERE id = ?', [$reserva->id]);
        self::assertNotNull($guardada);
        self::assertSame(self::PROGRAMADA, $guardada['funcion_id']);
        self::assertSame($reserva->usuarioId, $guardada['usuario_id']);
        self::assertSame($reserva->codigo, $guardada['codigo']);
        self::assertSame(2, $guardada['cantidad']);
        self::assertSame('7000.00', $guardada['total']);
        self::assertSame('confirmada', $guardada['estado']);
    }

    public function testUnaFuncionQueNoExisteOSinPublicarEsUn404(): void
    {
        foreach (['eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', self::SIN_PUBLICAR, 'no-es-un-id'] as $id) {
            try {
                $this->reservar(1, $id);
                self::fail("Reservó en {$id}.");
            } catch (ExcepcionHttp $error) {
                self::assertSame(404, $error->estado);
            }
        }
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM reserva'));
    }

    public function testNoSeReservanMasLugaresQueLosQueQuedan(): void
    {
        $this->ocupar(37);

        foreach ([4 => 'Quedan 3 lugares.', 41 => 'Quedan 3 lugares.'] as $cantidad => $mensaje) {
            try {
                $this->reservar($cantidad);
                self::fail("Reservó {$cantidad} lugares con 3 libres.");
            } catch (ReglaIncumplida $error) {
                self::assertSame($mensaje, $error->getMessage());
            }
        }
        $this->reservar(2);

        $this->expectExceptionMessage('Queda 1 lugar.');
        $this->reservar(2);
    }

    public function testConElCupoCompletoLaFuncionEstaAgotada(): void
    {
        $this->ocupar(30);
        $this->ocupar(10, 'asistio');

        $this->expectException(ReglaIncumplida::class);
        $this->expectExceptionMessage('La función está agotada.');
        $this->reservar(1);
    }

    public function testLasCanceladasDevuelvenSusLugares(): void
    {
        $this->ocupar(39);
        $this->ocupar(5, 'cancelada');

        $this->reservar(1);

        self::assertSame(self::CUPO, $this->confirmadas());
    }

    /**
     * El criterio de la historia: varios espectadores, cada uno en su proceso y con su conexión,
     * van por el último lugar al mismo tiempo, y sólo uno lo consigue.
     */
    public function testDeVariasReservasALaVezPorElUltimoLugarSeConfirmaUnaSola(): void
    {
        $this->ocupar(self::CUPO - 1);

        $arranque = microtime(true) + 0.5;
        $procesos = [];
        for ($i = 0; $i < 6; ++$i) {
            $procesos[] = $this->lanzarReserva($arranque);
        }
        $resultados = array_map($this->resultado(...), $procesos);

        sort($resultados);
        self::assertSame(['La función está agotada.', 'La función está agotada.', 'La función está agotada.', 'La función está agotada.', 'La función está agotada.', 'confirmada'], $resultados);
        self::assertSame(self::CUPO, $this->confirmadas());
    }

    /**
     * Que compitan no alcanza para saber que se esperan: con la función bloqueada desde otra
     * conexión, la reserva no termina hasta que se suelta.
     */
    public function testUnaReservaEsperaAQueTermineLaOtraDeLaMismaFuncion(): void
    {
        $otra = BaseDeDatos::conectar(new Configuracion(['base' => self::baseDePruebas()]));
        self::assertSame(1, $otra->valor('SELECT GET_LOCK(?, 5)', ['funciones.reserva.' . self::PROGRAMADA]));

        $proceso = $this->lanzarReserva(microtime(true));
        usleep(800_000);
        self::assertTrue(proc_get_status($proceso['proceso'])['running'], 'La reserva no esperó a que se soltara la función.');
        self::assertSame(0, $this->confirmadas());

        $otra->valor('SELECT RELEASE_LOCK(?)', ['funciones.reserva.' . self::PROGRAMADA]);
        self::assertSame('confirmada', $this->resultado($proceso));
    }

    /**
     * @return array{proceso: resource, salida: resource, errores: resource}
     */
    private function lanzarReserva(float $arranque): array
    {
        $proceso = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Dobles/reservar.php', self::$url, self::PROGRAMADA, Uuid::nuevo(), (string) $arranque],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $tuberias,
        );
        self::assertNotFalse($proceso);

        return ['proceso' => $proceso, 'salida' => $tuberias[1], 'errores' => $tuberias[2]];
    }

    /**
     * @param array{proceso: resource, salida: resource, errores: resource} $lanzado
     */
    private function resultado(array $lanzado): string
    {
        $salida = (string) stream_get_contents($lanzado['salida']);
        $errores = (string) stream_get_contents($lanzado['errores']);
        proc_close($lanzado['proceso']);
        self::assertSame('', $errores);

        return $salida;
    }
}
