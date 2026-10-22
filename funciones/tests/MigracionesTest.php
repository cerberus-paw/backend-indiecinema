<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Uuid;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Las tablas del subsistema funciones tal como las dejan las migraciones, contra un MySQL de
 * verdad: lo que se prueba son las restricciones de la base, no las de la aplicación.
 */
final class MigracionesTest extends TestCase
{
    use ConBaseDePruebas;

    private const VALOR_INVALIDO = 1265;
    private const FUERA_DE_RANGO = 1264;
    private const RESTRICCION_CHECK = 3819;
    private const CAMPO_OBLIGATORIO = 1364;
    private const REPETIDO = 1062;

    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas(new Configuracion(['base' => self::baseDePruebas()]));
    }

    /**
     * @param int|int[] $codigo
     */
    private function assertRechaza(int|array $codigo, callable $escritura): void
    {
        try {
            $escritura();
            self::fail('La base aceptó una escritura que tenía que rechazar.');
        } catch (PDOException $e) {
            $codigos = is_array($codigo) ? $codigo : [$codigo];
            self::assertContains($e->errorInfo[1] ?? null, $codigos, $e->getMessage());
        }
    }

    /**
     * Dos lugares a 3500 en una función cualquiera: funciones no ve el esquema de programación ni
     * el de cuentas, así que los ids no tienen que existir en ningún lado.
     *
     * @return array<string, mixed>
     */
    private function reservaValida(): array
    {
        return [
            'id' => Uuid::nuevo(),
            'funcion_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'usuario_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'codigo' => 'K7M2XQ9P',
            'cantidad' => 2,
            'total' => '7000.00',
            'creada_en' => '2026-10-21 18:30:00',
        ];
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function insertarReserva(array $datos): void
    {
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$datos['id'], $datos['funcion_id'], $datos['usuario_id'], $datos['codigo'], $datos['cantidad'], $datos['total'], $datos['creada_en']],
        );
    }

    public function testLasMigracionesSeAplicanDesdeCeroYCreanLasTablas(): void
    {
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM reserva'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM acuerdo_fecha'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM acuerdo_franja'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM voto_acuerdo'));
    }

    public function testLaReservaQuedaGuardadaConfirmadaYConSusValores(): void
    {
        $datos = $this->reservaValida();
        $this->insertarReserva($datos);

        $guardada = $this->base->fila('SELECT * FROM reserva WHERE id = ?', [$datos['id']]);
        self::assertNotNull($guardada);
        self::assertSame($datos['funcion_id'], $guardada['funcion_id']);
        self::assertSame($datos['usuario_id'], $guardada['usuario_id']);
        self::assertSame('K7M2XQ9P', $guardada['codigo']);
        self::assertSame(2, $guardada['cantidad']);
        self::assertSame('7000.00', $guardada['total']);
        self::assertSame('confirmada', $guardada['estado']);
        self::assertSame('2026-10-21 18:30:00', $guardada['creada_en']);
    }

    public function testUnUsuarioPuedeTenerVariasReservasDeLaMismaFuncion(): void
    {
        $this->insertarReserva($this->reservaValida());
        $otra = $this->reservaValida();
        $otra['codigo'] = 'H4N8WR3T';
        $this->insertarReserva($otra);

        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM reserva WHERE usuario_id = ?', [$otra['usuario_id']]));
    }

    public function testDosReservasNoTienenElMismoCodigo(): void
    {
        $this->insertarReserva($this->reservaValida());
        $otra = $this->reservaValida();
        $otra['funcion_id'] = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

        $this->assertRechaza(self::REPETIDO, fn () => $this->insertarReserva($otra));
    }

    public function testLaBaseRechazaUnaCantidadCeroONegativa(): void
    {
        foreach ([0 => self::RESTRICCION_CHECK, -1 => self::FUERA_DE_RANGO] as $cantidad => $codigo) {
            $datos = $this->reservaValida();
            $datos['cantidad'] = $cantidad;
            $this->assertRechaza($codigo, fn () => $this->insertarReserva($datos));
        }
    }

    public function testLaBaseRechazaUnTotalNegativo(): void
    {
        $datos = $this->reservaValida();
        $datos['total'] = '-1.00';

        $this->assertRechaza(self::RESTRICCION_CHECK, fn () => $this->insertarReserva($datos));
    }

    public function testLaReservaDeUnaFuncionALaGorraTotalizaCero(): void
    {
        $datos = $this->reservaValida();
        $datos['total'] = '0.00';
        $this->insertarReserva($datos);

        self::assertSame('0.00', $this->base->valor('SELECT total FROM reserva WHERE id = ?', [$datos['id']]));
    }

    public function testElEstadoPasaPorLosDelCicloDeVida(): void
    {
        $datos = $this->reservaValida();
        $this->insertarReserva($datos);

        foreach (['asistio', 'cancelada'] as $estado) {
            $this->base->ejecutar('UPDATE reserva SET estado = ? WHERE id = ?', [$estado, $datos['id']]);
            self::assertSame($estado, $this->base->valor('SELECT estado FROM reserva WHERE id = ?', [$datos['id']]));
        }
    }

    public function testLaBaseRechazaUnEstadoFueraDelEnum(): void
    {
        $datos = $this->reservaValida();
        $this->insertarReserva($datos);

        // pendiente_pago llega con el extra de Mercado Pago, en una migración nueva.
        $this->assertRechaza(self::VALOR_INVALIDO, fn () => $this->base->ejecutar(
            'UPDATE reserva SET estado = ? WHERE id = ?',
            ['pendiente_pago', $datos['id']],
        ));
    }

    public function testLaBaseRechazaLaFaltaDeCamposObligatorios(): void
    {
        $this->assertRechaza(self::CAMPO_OBLIGATORIO, fn () => $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, cantidad, total, creada_en) VALUES (?, ?, ?, ?, ?, ?)',
            [Uuid::nuevo(), 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 1, '3500.00', '2026-10-21 18:30:00'],
        ));
    }

    public function testUnAcuerdoDeFechaSeGuardaAbiertoYConSusFranjas(): void
    {
        $acuerdoId = Uuid::nuevo();
        $funcionId = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO acuerdo_fecha (id, funcion_id, minimo_asistentes, creado_en) VALUES (?, ?, ?, ?)',
            [$acuerdoId, $funcionId, 20, '2026-10-22 10:00:00'],
        );

        $franja1Id = Uuid::nuevo();
        $franja2Id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO acuerdo_franja (id, acuerdo_id, fecha_hora_inicio, fecha_hora_fin, creada_en) VALUES (?, ?, ?, ?, ?)',
            [$franja1Id, $acuerdoId, '2026-10-25 20:00:00', '2026-10-25 22:30:00', '2026-10-22 10:00:00'],
        );
        $this->base->ejecutar(
            'INSERT INTO acuerdo_franja (id, acuerdo_id, fecha_hora_inicio, fecha_hora_fin, creada_en) VALUES (?, ?, ?, ?, ?)',
            [$franja2Id, $acuerdoId, '2026-10-26 21:00:00', null, '2026-10-22 10:00:00'],
        );

        $guardado = $this->base->fila('SELECT * FROM acuerdo_fecha WHERE id = ?', [$acuerdoId]);
        self::assertNotNull($guardado);
        self::assertSame('abierto', $guardado['estado']);
        self::assertSame(20, $guardado['minimo_asistentes']);
        self::assertNull($guardado['franja_ganadora_id']);

        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM acuerdo_franja WHERE acuerdo_id = ?', [$acuerdoId]));
    }

    public function testUnUsuarioTieneComoMaximoUnVotoPorFuncion(): void
    {
        $acuerdoId = Uuid::nuevo();
        $funcionId = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO acuerdo_fecha (id, funcion_id, minimo_asistentes, creado_en) VALUES (?, ?, ?, ?)',
            [$acuerdoId, $funcionId, 15, '2026-10-22 10:00:00'],
        );

        $franja1Id = Uuid::nuevo();
        $franja2Id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO acuerdo_franja (id, acuerdo_id, fecha_hora_inicio, creada_en) VALUES (?, ?, ?, ?)',
            [$franja1Id, $acuerdoId, '2026-10-25 20:00:00', '2026-10-22 10:00:00'],
        );
        $this->base->ejecutar(
            'INSERT INTO acuerdo_franja (id, acuerdo_id, fecha_hora_inicio, creada_en) VALUES (?, ?, ?, ?)',
            [$franja2Id, $acuerdoId, '2026-10-26 20:00:00', '2026-10-22 10:00:00'],
        );

        $usuarioId = Uuid::nuevo();
        // Primer voto: éxito
        $this->base->ejecutar(
            'INSERT INTO voto_acuerdo (id, acuerdo_id, funcion_id, franja_id, usuario_id, creado_en) VALUES (?, ?, ?, ?, ?, ?)',
            [Uuid::nuevo(), $acuerdoId, $funcionId, $franja1Id, $usuarioId, '2026-10-22 10:05:00'],
        );

        // Segundo voto del mismo usuario para la misma función: la base lo rechaza por clave única
        $this->assertRechaza(self::REPETIDO, fn () => $this->base->ejecutar(
            'INSERT INTO voto_acuerdo (id, acuerdo_id, funcion_id, franja_id, usuario_id, creado_en) VALUES (?, ?, ?, ?, ?, ?)',
            [Uuid::nuevo(), $acuerdoId, $funcionId, $franja2Id, $usuarioId, '2026-10-22 10:10:00'],
        ));

        // Pero sí puede cambiar su voto actualizando la franja:
        $this->base->ejecutar(
            'UPDATE voto_acuerdo SET franja_id = ? WHERE acuerdo_id = ? AND usuario_id = ?',
            [$franja2Id, $acuerdoId, $usuarioId],
        );
        self::assertSame($franja2Id, $this->base->valor(
            'SELECT franja_id FROM voto_acuerdo WHERE acuerdo_id = ? AND usuario_id = ?',
            [$acuerdoId, $usuarioId],
        ));
    }

    public function testLaBaseRechazaUnMinimoDeAsistentesCero(): void
    {
        $this->assertRechaza(self::RESTRICCION_CHECK, fn () => $this->base->ejecutar(
            'INSERT INTO acuerdo_fecha (id, funcion_id, minimo_asistentes, creado_en) VALUES (?, ?, ?, ?)',
            [Uuid::nuevo(), Uuid::nuevo(), 0, '2026-10-22 10:00:00'],
        ));
    }

    public function testBorrarUnAcuerdoBorraSusFranjasYVotosEnCascada(): void
    {
        $acuerdoId = Uuid::nuevo();
        $funcionId = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO acuerdo_fecha (id, funcion_id, minimo_asistentes, creado_en) VALUES (?, ?, ?, ?)',
            [$acuerdoId, $funcionId, 10, '2026-10-22 10:00:00'],
        );

        $franjaId = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO acuerdo_franja (id, acuerdo_id, fecha_hora_inicio, creada_en) VALUES (?, ?, ?, ?)',
            [$franjaId, $acuerdoId, '2026-10-25 20:00:00', '2026-10-22 10:00:00'],
        );

        $this->base->ejecutar(
            'INSERT INTO voto_acuerdo (id, acuerdo_id, funcion_id, franja_id, usuario_id, creado_en) VALUES (?, ?, ?, ?, ?, ?)',
            [Uuid::nuevo(), $acuerdoId, $funcionId, $franjaId, Uuid::nuevo(), '2026-10-22 10:05:00'],
        );

        $this->base->ejecutar('DELETE FROM acuerdo_fecha WHERE id = ?', [$acuerdoId]);

        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM acuerdo_franja WHERE acuerdo_id = ?', [$acuerdoId]));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM voto_acuerdo WHERE acuerdo_id = ?', [$acuerdoId]));
    }

    public function testElEstadoDelAcuerdoPasaPorLosDelCicloDeVida(): void
    {
        $acuerdoId = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO acuerdo_fecha (id, funcion_id, minimo_asistentes, creado_en) VALUES (?, ?, ?, ?)',
            [$acuerdoId, Uuid::nuevo(), 10, '2026-10-22 10:00:00'],
        );

        foreach (['confirmado', 'sin_minimo', 'cancelado'] as $estado) {
            $this->base->ejecutar('UPDATE acuerdo_fecha SET estado = ? WHERE id = ?', [$estado, $acuerdoId]);
            self::assertSame($estado, $this->base->valor('SELECT estado FROM acuerdo_fecha WHERE id = ?', [$acuerdoId]));
        }

        $this->assertRechaza(self::VALOR_INVALIDO, fn () => $this->base->ejecutar(
            'UPDATE acuerdo_fecha SET estado = ? WHERE id = ?',
            ['estado_invalido', $acuerdoId],
        ));
    }
}

