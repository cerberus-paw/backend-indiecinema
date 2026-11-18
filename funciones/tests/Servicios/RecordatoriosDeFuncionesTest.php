<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas\Servicios;

use IndieCinema\Funciones\Pruebas\ConBaseDePruebas;
use IndieCinema\Funciones\Pruebas\ConCuentasDePrueba;
use IndieCinema\Funciones\Pruebas\ConProgramacionDePrueba;
use IndieCinema\Funciones\Repositorios\RepositorioDeReservas;
use IndieCinema\Funciones\Servicios\AvisosDeReservas;
use IndieCinema\Funciones\Servicios\RecordatoriosDeFunciones;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * El recordatorio del día antes (EF-03.3), con la base de verdad, una programación de mentira
 * (Dobles/programacion.php: EN_CINCO_HORAS empieza en cinco horas y PROXIMA en un mes), un
 * cuentas de mentira y un servidor de correo de prueba.
 */
final class RecordatoriosDeFuncionesTest extends TestCase
{
    use ConBaseDePruebas;
    use ConCuentasDePrueba;
    use ConProgramacionDePrueba {
        setUpBeforeClass as iniciarProgramacion;
        tearDownAfterClass as detenerProgramacion;
    }

    private const EN_CINCO_HORAS = '45454545-4545-4545-8545-454545454545';
    private const PROXIMA = '12121212-1212-4212-8212-121212121212';
    private const EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ELIAS = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    /** Cuentas de mentira no lo conoce: su correo no sale. */
    private const BORRADO = '00000000-0000-4000-8000-000000000000';

    private static ServidorSmtpDePrueba $correo;

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    public static function setUpBeforeClass(): void
    {
        self::iniciarProgramacion();
        self::$correo = ServidorSmtpDePrueba::iniciar();
        self::iniciarCuentas();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerProgramacion();
        self::$correo->detener();
        self::detenerCuentas();
    }

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
            'sitio' => ['url' => 'http://localhost:8080'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        self::$correo->vaciar();
    }

    private function recordatorios(): RecordatoriosDeFunciones
    {
        $log = new Log('funciones', fopen('php://memory', 'w'));
        $cuentas = new ClienteInterno('funciones', 'token-de-funciones', ['cuentas' => self::$urlCuentas], $log, 500);

        return new RecordatoriosDeFunciones(
            self::funcionesDeProgramacion(),
            new RepositorioDeReservas($this->base),
            new AvisosDeReservas($cuentas, ClienteSmtp::desdeConfiguracion($this->configuracion, $log), $log, $this->configuracion),
        );
    }

    private function reserva(string $funcion, string $estado = 'confirmada', string $usuario = self::EVA): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            // Una pendiente de pago lleva su vencimiento: lo exige la tabla.
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en, vence_en) VALUES (?, ?, ?, ?, 2, 7000, ?, ?, ?)',
            [$id, $funcion, $usuario, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), $estado, '2026-10-19 13:00:00', $estado === 'pendiente_pago' ? '2030-01-01 00:00:00' : null],
        );

        return $id;
    }

    /**
     * @return list<array{para: list<string>, asunto: string, texto: string}>
     */
    private static function correos(): array
    {
        return array_map(static function (array $correo): array {
            [$cabeceras, $cuerpo] = explode("\r\n\r\n", $correo['mensaje'], 2);
            preg_match('/^Subject: (.+(?:\r\n .+)*)/m', $cabeceras, $asunto);

            return [
                'para' => $correo['para'],
                'asunto' => mb_decode_mimeheader(str_replace("\r\n", '', $asunto[1] ?? '')),
                'texto' => str_replace("\r\n", "\n", (string) base64_decode(str_replace("\r\n", '', $cuerpo), true)),
            ];
        }, self::$correo->correos());
    }

    public function testCadaConfirmadaDeLaFuncionDeMananaRecibeUnoConLaSalaYElCodigo(): void
    {
        $this->reserva(self::EN_CINCO_HORAS);
        $this->reserva(self::EN_CINCO_HORAS, usuario: self::ELIAS);
        // Las pendientes de pago y las canceladas no, ni la que es dentro de un mes.
        $this->reserva(self::EN_CINCO_HORAS, 'pendiente_pago');
        $this->reserva(self::EN_CINCO_HORAS, 'cancelada');
        $this->reserva(self::PROXIMA);

        self::assertSame(['enviados' => 2, 'fallidos' => 0], $this->recordatorios()->recordar());

        $correos = self::correos();
        self::assertCount(2, $correos);
        self::assertEqualsCanonicalizing(['<' . self::EVA . '@ejemplo.test>', '<' . self::ELIAS . '@ejemplo.test>'], array_merge(...array_column($correos, 'para')));
        self::assertSame('Recordatorio: «Zama»', $correos[0]['asunto']);
        self::assertStringContainsString('Sala: Cine Club El Galpón', $correos[0]['texto']);
        self::assertStringContainsString('Dirección: Av. San Martín 450, Luján', $correos[0]['texto']);
        self::assertStringContainsString('Código de la reserva: ', $correos[0]['texto']);
    }

    public function testCorrerlaDosVecesNoRepiteLosCorreos(): void
    {
        $this->reserva(self::EN_CINCO_HORAS);

        $this->recordatorios()->recordar();
        self::$correo->vaciar();

        self::assertSame(['enviados' => 0, 'fallidos' => 0], $this->recordatorios()->recordar());
        self::assertCount(0, self::$correo->correos());
    }

    /**
     * Si el correo no sale (acá, cuentas no conoce al usuario), la reserva queda sin marcar y la
     * próxima pasada lo intenta de nuevo.
     */
    public function testSiElCorreoNoSaleQuedaParaLaProximaPasada(): void
    {
        $reserva = $this->reserva(self::EN_CINCO_HORAS, usuario: self::BORRADO);

        self::assertSame(['enviados' => 0, 'fallidos' => 1], $this->recordatorios()->recordar());

        self::assertNull($this->base->valor('SELECT recordada_en FROM reserva WHERE id = ?', [$reserva]));
    }
}
