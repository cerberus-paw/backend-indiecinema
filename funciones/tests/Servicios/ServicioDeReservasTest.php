<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Pruebas\ConBaseDePruebas;
use IndieCinema\Funciones\Pruebas\ConProgramacionDePrueba;
use IndieCinema\Funciones\Repositorios\RepositorioDeConducta;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Funciones\Servicios\CuponesDeRecaudacion;
use IndieCinema\Funciones\Servicios\ServicioDeConducta;
use IndieCinema\Funciones\Servicios\ServicioDeReservas;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Log;
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
     * Mientras el que reservó paga, sus lugares son suyos; cuando vence el plazo, quedan libres en
     * el momento, sin esperar a que la tarea la cancele (X-01.5).
     */
    public function testLosLugaresDeUnaPendienteVencidaQuedanLibresSinEsperarALaTarea(): void
    {
        // Reservó los 40 a las 12:00 (15:00 en UTC, como guarda la base) y tenía 15 minutos.
        $this->base->ejecutar(
            "INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en, vence_en) VALUES (?, ?, ?, 'VENC2345', ?, '140000.00', 'pendiente_pago', '2026-10-20 15:00:00', '2026-10-20 15:15:00')",
            [Uuid::nuevo(), self::PROGRAMADA, Uuid::nuevo(), self::CUPO],
        );
        $espectador = new Usuario(Uuid::nuevo(), Rol::Espectador);
        // Con la zona explícita: la CI corre PHP en UTC, y el contenedor, en la hora de acá.
        $argentina = new DateTimeZone('America/Argentina/Buenos_Aires');

        try {
            $this->servicio()->reservar($espectador, self::PROGRAMADA, 1, new DateTimeImmutable('2026-10-20 12:14:00', $argentina));
            self::fail('Reservó un lugar que todavía se estaba pagando.');
        } catch (ReglaIncumplida $error) {
            self::assertSame('La función está agotada.', $error->getMessage());
        }
        // Lo más que puede pedir una persona (IC-463): con los 40 ocupados, no habría ni uno.
        $reserva = $this->servicio()->reservar($espectador, self::PROGRAMADA, Reserva::MAXIMO_POR_PERSONA, new DateTimeImmutable('2026-10-20 12:15:00', $argentina));

        self::assertSame(Reserva::MAXIMO_POR_PERSONA, $reserva->cantidad);
        // La vencida sigue pendiente hasta que pase la tarea: lo que cambió es que ya no cuenta.
        self::assertSame(1, $this->base->valor("SELECT COUNT(*) FROM reserva WHERE estado = 'pendiente_pago'"));
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

    public function testNoSeSuperaElMaximoPorPersonaEnUnaReserva(): void
    {
        $espectador = new Usuario(Uuid::nuevo(), Rol::Espectador);

        try {
            $this->servicio()->reservar($espectador, self::PROGRAMADA, Reserva::MAXIMO_POR_PERSONA + 1, new DateTimeImmutable('2026-10-20 12:00:00'));
            self::fail('Permitió reservar más del máximo por persona.');
        } catch (ReglaIncumplida $error) {
            self::assertSame('Se pueden reservar hasta 6 lugares por persona en cada función.', $error->getMessage());
        }

        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM reserva WHERE usuario_id = ?', [$espectador->id]));
    }

    public function testNoSeSuperaElMaximoPorPersonaEnDosReservasSeguidas(): void
    {
        $espectador = new Usuario(Uuid::nuevo(), Rol::Espectador);
        $servicio = $this->servicio();
        $ahora = new DateTimeImmutable('2026-10-20 12:00:00');

        $primera = $servicio->reservar($espectador, self::PROGRAMADA, 4, $ahora);
        self::assertSame(4, $primera->cantidad);

        try {
            $servicio->reservar($espectador, self::PROGRAMADA, 3, $ahora);
            self::fail('Permitió superar el máximo en una segunda reserva.');
        } catch (ReglaIncumplida $error) {
            self::assertSame('Ya tenés 4 lugares reservados: podés sumar hasta 2 lugares más (máximo 6 por persona).', $error->getMessage());
        }

        $segunda = $servicio->reservar($espectador, self::PROGRAMADA, 2, $ahora);
        self::assertSame(2, $segunda->cantidad);

        try {
            $servicio->reservar($espectador, self::PROGRAMADA, 1, $ahora);
            self::fail('Permitió reservar teniendo ya 6 lugares.');
        } catch (ReglaIncumplida $error) {
            self::assertSame('Ya tenés reservados los 6 lugares máximos por persona para esta función.', $error->getMessage());
        }

        $servicio->cancelar($espectador, $segunda->id, $ahora);
        $recuperada = $servicio->reservar($espectador, self::PROGRAMADA, 2, $ahora);
        self::assertSame(2, $recuperada->cantidad);

        $otro = new Usuario(Uuid::nuevo(), Rol::Espectador);
        $reservaOtro = $servicio->reservar($otro, self::PROGRAMADA, 6, $ahora);
        self::assertSame(6, $reservaOtro->cantidad);
    }

    public function testDosReservasALaVezDelMismoEspectadorNoSuperanElMaximoPorPersona(): void
    {
        $espectadorId = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [Uuid::nuevo(), self::PROGRAMADA, $espectadorId, 'AAAA1111', 5, '0.00', 'confirmada', '2026-10-19 10:00:00'],
        );

        $arranque = microtime(true) + 0.5;
        $procesos = [];
        for ($i = 0; $i < 4; ++$i) {
            $procesos[] = $this->lanzarReserva($arranque, $espectadorId);
        }
        $resultados = array_map($this->resultado(...), $procesos);

        sort($resultados);
        self::assertSame([
            'Ya tenés reservados los 6 lugares máximos por persona para esta función.',
            'Ya tenés reservados los 6 lugares máximos por persona para esta función.',
            'Ya tenés reservados los 6 lugares máximos por persona para esta función.',
            'confirmada',
        ], $resultados);
        self::assertSame(6, (int) $this->base->valor('SELECT SUM(cantidad) FROM reserva WHERE funcion_id = ? AND usuario_id = ?', [self::PROGRAMADA, $espectadorId]));
    }

    /**
     * @return array{proceso: resource, salida: resource, errores: resource}
     */
    private function lanzarReserva(float $arranque, ?string $espectadorId = null): array
    {
        $proceso = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Dobles/reservar.php', self::$url, self::PROGRAMADA, $espectadorId ?? Uuid::nuevo(), (string) $arranque],
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

    public function testUnUsuarioBloqueadoEnLaSalaNoPuedeReservarPeroSiEnOtra(): void
    {
        $bloqueado = new Usuario('66666666-6666-4666-8666-666666666666', Rol::Espectador);
        $ahora = new DateTimeImmutable('2026-10-20 12:00:00');

        try {
            $this->servicio()->reservar($bloqueado, self::PROGRAMADA, 1, $ahora);
            self::fail('Tenía que rechazar la reserva de un usuario bloqueado en la sala.');
        } catch (ReglaIncumplida $error) {
            self::assertSame('No podés reservar en esta sala.', $error->getMessage());
        }
        self::assertSame(0, $this->confirmadas());

        $enOtra = $this->servicio()->reservar($bloqueado, '67676767-6767-4767-8767-676767676767', 2, $ahora);
        self::assertSame(2, $enOtra->cantidad);
    }

    public function testBajoElUmbralLimitaADosReservasSimultaneas(): void
    {
        $configuracion = new Configuracion(['base' => self::baseDePruebas()]);
        $servicio = new ServicioDeReservas(
            self::funcionesDeProgramacion(),
            new RepositorioDeReservas($this->base),
            conducta: new ServicioDeConducta(new RepositorioDeConducta($this->base), $configuracion),
        );
        $espectador = new Usuario(Uuid::nuevo(), Rol::Espectador);
        $ahora = new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC'));

        // Dos negativas recientes (-20) y cuatro ausencias (-20) en funciones pasadas dejan el puntaje en 60 (en el umbral).
        $funcionPasada = Uuid::nuevo();
        foreach (['2026-04-21 12:00:00', '2026-10-10 12:00:00'] as $fecha) {
            $id = Uuid::nuevo();
            $this->base->ejecutar(
                "INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, 1, '0.00', 'asistio', ?)",
                [$id, $funcionPasada, $espectador->id, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), $fecha],
            );
            $this->base->ejecutar(
                'INSERT INTO conducta (reserva_id, usuario_id, organizador_id, positiva, motivo, creada_en) VALUES (?, ?, ?, 0, ?, ?)',
                [$id, $espectador->id, Uuid::nuevo(), 'Mala conducta', $fecha],
            );
        }
        for ($i = 0; $i < 4; ++$i) {
            $this->base->ejecutar(
                "INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, 1, '0.00', 'ausente', '2026-10-01 12:00:00')",
                [Uuid::nuevo(), $funcionPasada, $espectador->id, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8))],
            );
        }

        // Primera y segunda reserva simultánea pasan (en funciones distintas); la tercera se rechaza.
        $primera = $servicio->reservar($espectador, self::PROGRAMADA, 1, $ahora);
        $segunda = $servicio->reservar($espectador, '67676767-6767-4767-8767-676767676767', 1, $ahora);
        self::assertSame(1, $primera->cantidad);
        self::assertSame(1, $segunda->cantidad);

        try {
            $servicio->reservar($espectador, self::PROGRAMADA, 1, $ahora);
            self::fail('Permitió una tercera reserva simultánea bajo el umbral.');
        } catch (ReglaIncumplida $error) {
            self::assertSame('Por tu historial de conducta podés tener hasta 2 reservas a la vez.', $error->getMessage());
        }

        // Cancelar una libera cupo de reservas simultáneas para volver a reservar.
        $servicio->cancelar($espectador, $primera->id, $ahora);
        $reemplazo = $servicio->reservar($espectador, self::PROGRAMADA, 1, $ahora);
        self::assertSame(1, $reemplazo->cantidad);

        // Cuando vence una negativa a los 6 meses el puntaje sube a 70 y vuelve el tope normal.
        $despuesDeSeisMeses = new DateTimeImmutable('2026-10-22 12:00:00', new DateTimeZone('UTC'));
        $tercera = $servicio->reservar($espectador, self::PROGRAMADA, 1, $despuesDeSeisMeses);
        self::assertSame(1, $tercera->cantidad);
    }

    public function testSoloQuienAlcanzaElUmbralAccedeAPreventaYCupones(): void
    {
        $configuracion = new Configuracion([
            'base' => self::baseDePruebas(),
            'conducta' => [
                'umbral_beneficio' => 80,
                'dias_preventa' => 7,
            ],
        ]);
        $tito = new Usuario(Uuid::nuevo(), Rol::Espectador);
        $eva = new Usuario(Uuid::nuevo(), Rol::Espectador);
        $cupones = new class ($tito->id, $eva->id) extends CuponesDeRecaudacion {
            public function __construct(private readonly string $tito, private readonly string $eva)
            {
                parent::__construct(new Configuracion([]), new Log('funciones', fopen('php://memory', 'w')));
            }

            public function buscar(string $codigo): ?array
            {
                return match ($codigo) {
                    'TITO2345' => ['usuario_id' => $this->tito, 'porcentaje' => 20, 'vence_en' => new DateTimeImmutable('2030-01-01')],
                    'EVA23456' => ['usuario_id' => $this->eva, 'porcentaje' => 20, 'vence_en' => new DateTimeImmutable('2030-01-01')],
                    default => null,
                };
            }
        };
        $servicio = new ServicioDeReservas(
            self::funcionesDeProgramacion(),
            new RepositorioDeReservas($this->base),
            conducta: new ServicioDeConducta(new RepositorioDeConducta($this->base), $configuracion),
            cupones: $cupones,
        );

        // Tito queda con 55 puntos: 2 negativas (-20) + 5 ausencias (-25).
        $pasada = Uuid::nuevo();
        for ($i = 0; $i < 2; ++$i) {
            $id = Uuid::nuevo();
            $this->base->ejecutar(
                "INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, 1, '0.00', 'asistio', '2026-10-10 12:00:00')",
                [$id, $pasada, $tito->id, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8))],
            );
            $this->base->ejecutar(
                "INSERT INTO conducta (reserva_id, usuario_id, organizador_id, positiva, motivo, creada_en) VALUES (?, ?, ?, 0, 'Falta', '2026-10-10 12:00:00')",
                [$id, $tito->id, Uuid::nuevo()],
            );
        }
        for ($i = 0; $i < 5; ++$i) {
            $this->base->ejecutar(
                "INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, 1, '0.00', 'ausente', '2026-10-10 12:00:00')",
                [Uuid::nuevo(), $pasada, $tito->id, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8))],
            );
        }

        // PROGRAMADA es 2026-10-24T20:30:00. A más de 7 días (2026-10-10) está en preventa exclusiva.
        $enPreventa = new DateTimeImmutable('2026-10-10 12:00:00', new DateTimeZone('UTC'));
        try {
            $servicio->reservar($tito, self::PROGRAMADA, 1, $enPreventa);
            self::fail('Permitió a alguien con 55 puntos reservar en preventa.');
        } catch (ReglaIncumplida $error) {
            self::assertStringContainsString('preventa', $error->getMessage());
            self::assertStringContainsString('80 puntos', $error->getMessage());
        }

        // Eva (100 puntos >= 80) sí puede reservar en preventa y usar cupón.
        $reservaEva = $servicio->reservar($eva, self::PROGRAMADA, 1, $enPreventa, cupon: 'EVA23456');
        self::assertSame('2800.00', $reservaEva->total);

        // Ya dentro de los últimos 7 días, Tito tampoco puede aplicar un cupón por su conducta (< 80).
        $sinPreventa = new DateTimeImmutable('2026-10-20 12:00:00', new DateTimeZone('UTC'));
        try {
            $servicio->reservar($tito, self::PROGRAMADA, 1, $sinPreventa, cupon: 'TITO2345');
            self::fail('Permitió usar cupón con menos de 80 puntos.');
        } catch (ReglaIncumplida $error) {
            self::assertStringContainsString('80 puntos para usar cupones de descuento', $error->getMessage());
        }

        // Sin cupón y fuera de preventa sí reserva normalmente (dentro de su tope).
        $reservaTito = $servicio->reservar($tito, self::PROGRAMADA, 1, $sinPreventa);
        self::assertSame('3500.00', $reservaTito->total);
    }
}
