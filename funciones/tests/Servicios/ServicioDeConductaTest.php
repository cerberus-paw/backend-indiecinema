<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Pruebas\ConBaseDePruebas;
use IndieCinema\Funciones\Repositorios\RepositorioDeConducta;
use IndieCinema\Funciones\Servicios\ServicioDeConducta;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

final class ServicioDeConductaTest extends TestCase
{
    use ConBaseDePruebas;

    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
    }

    public function testElPuntajeArrancaEnCienYBajaConNegativasVigentesYAusencias(): void
    {
        $servicio = new ServicioDeConducta(new RepositorioDeConducta($this->base), new Configuracion([]));
        $usuario = Uuid::nuevo();
        $funcion = Uuid::nuevo();
        $organizador = Uuid::nuevo();
        $ahora = new DateTimeImmutable('2026-11-15 12:00:00');

        self::assertSame(100, $servicio->puntaje($usuario, $ahora));

        // Dos negativas vigentes (-20) + una positiva (0) + cuatro ausencias (-20) = 60.
        $this->insertarReservaYConducta($usuario, $funcion, 'asistio', 0, 'Llegó tarde', '2026-10-01 20:00:00', $organizador);
        $this->insertarReservaYConducta($usuario, $funcion, 'asistio', 0, 'Molestó en la sala', '2026-11-01 20:00:00', $organizador);
        $this->insertarReservaYConducta($usuario, $funcion, 'asistio', 1, null, '2026-11-02 20:00:00', $organizador);
        // Una negativa de más de 6 meses atrás ya no descuenta.
        $this->insertarReservaYConducta($usuario, $funcion, 'asistio', 0, 'Antigua', '2026-04-01 20:00:00', $organizador);

        for ($i = 0; $i < 4; ++$i) {
            $this->insertarReserva($usuario, $funcion, 'ausente');
        }

        self::assertSame(60, $servicio->puntaje($usuario, $ahora));
    }

    public function testRespetaConfiguracionYNuncaBajaDeCero(): void
    {
        $configuracion = new Configuracion([
            'conducta' => [
                'puntaje_inicial' => 50,
                'descuento_negativa' => 30,
                'descuento_ausencia' => 25,
                'meses_vigencia' => 6,
            ],
        ]);
        $servicio = new ServicioDeConducta(new RepositorioDeConducta($this->base), $configuracion);
        $usuario = Uuid::nuevo();
        $funcion = Uuid::nuevo();
        $ahora = new DateTimeImmutable('2026-11-15 12:00:00');

        $this->insertarReservaYConducta($usuario, $funcion, 'asistio', 0, 'Falta grave', '2026-11-10 20:00:00', Uuid::nuevo());
        $this->insertarReserva($usuario, $funcion, 'ausente');

        self::assertSame(0, $servicio->puntaje($usuario, $ahora));
    }

    public function testBajoElUmbralReduceElTopeSimultaneoYSeRecuperaAlVencerNegativa(): void
    {
        $servicio = new ServicioDeConducta(new RepositorioDeConducta($this->base), new Configuracion([]));
        $usuario = Uuid::nuevo();
        $funcion = Uuid::nuevo();
        $organizador = Uuid::nuevo();
        $ahora = new DateTimeImmutable('2026-11-15 12:00:00');

        self::assertSame(60, $servicio->umbral());
        self::assertFalse($servicio->bajoElUmbral($usuario, $ahora));
        self::assertNull($servicio->topeSimultaneoPara($usuario, $ahora));

        // Dos negativas vigentes (-20) + cuatro ausencias (-20) = puntaje 60 (en el umbral).
        $this->insertarReservaYConducta($usuario, $funcion, 'asistio', 0, 'Llegó tarde', '2026-05-16 00:00:00', $organizador);
        $this->insertarReservaYConducta($usuario, $funcion, 'asistio', 0, 'Interrumpió la sala', '2026-11-01 20:00:00', $organizador);
        for ($i = 0; $i < 4; ++$i) {
            $this->insertarReserva($usuario, $funcion, 'ausente');
        }

        self::assertSame(60, $servicio->puntaje($usuario, $ahora));
        self::assertTrue($servicio->bajoElUmbral($usuario, $ahora));
        self::assertSame(2, $servicio->topeSimultaneoPara($usuario, $ahora));

        // Dos días después la primera negativa cumple 6 meses: sube a 70 y recupera el tope normal.
        $despues = new DateTimeImmutable('2026-11-17 12:00:00');
        self::assertSame(70, $servicio->puntaje($usuario, $despues));
        self::assertFalse($servicio->bajoElUmbral($usuario, $despues));
        self::assertNull($servicio->topeSimultaneoPara($usuario, $despues));
    }

    public function testRespetaUmbralYTopeDeConfiguracion(): void
    {
        $configuracion = new Configuracion([
            'conducta' => [
                'umbral' => 80,
                'tope_bajo_umbral' => 1,
            ],
        ]);
        $servicio = new ServicioDeConducta(new RepositorioDeConducta($this->base), $configuracion);
        $usuario = Uuid::nuevo();
        $funcion = Uuid::nuevo();
        $ahora = new DateTimeImmutable('2026-11-15 12:00:00');

        $this->insertarReservaYConducta($usuario, $funcion, 'asistio', 0, 'Molestó', '2026-11-10 20:00:00', Uuid::nuevo());
        $this->insertarReservaYConducta($usuario, $funcion, 'asistio', 0, 'Tarde', '2026-11-11 20:00:00', Uuid::nuevo());

        self::assertSame(80, $servicio->umbral());
        self::assertSame(80, $servicio->puntaje($usuario, $ahora));
        self::assertTrue($servicio->bajoElUmbral($usuario, $ahora));
        self::assertSame(1, $servicio->topeSimultaneoPara($usuario, $ahora));
    }

    private function insertarReserva(string $usuario, string $funcion, string $estado): string
    {
        $id = Uuid::nuevo();
        $codigo = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, 1, 0, ?, ?)',
            [$id, $funcion, $usuario, $codigo, $estado, '2026-10-01 12:00:00'],
        );

        return $id;
    }

    private function insertarReservaYConducta(
        string $usuario,
        string $funcion,
        string $estado,
        int $positiva,
        ?string $motivo,
        string $creadaEn,
        string $organizador,
    ): void {
        $reservaId = $this->insertarReserva($usuario, $funcion, $estado);
        $this->base->ejecutar(
            'INSERT INTO conducta (reserva_id, usuario_id, organizador_id, positiva, motivo, creada_en) VALUES (?, ?, ?, ?, ?, ?)',
            [$reservaId, $usuario, $organizador, $positiva, $motivo, $creadaEn],
        );
    }
}
