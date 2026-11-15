<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use DateTimeImmutable;
use IndieCinema\Moderacion\Repositorios\RepositorioDeAvisos;
use IndieCinema\Moderacion\Repositorios\RepositorioDeDocumentos;
use IndieCinema\Moderacion\Repositorios\RepositorioDeSuspensiones;
use IndieCinema\Moderacion\Servicios\ArchivosDeSolicitudes;
use IndieCinema\Moderacion\Servicios\AvisosAlOrganizador;
use IndieCinema\Moderacion\Servicios\SalasDeProgramacion;
use IndieCinema\Moderacion\Servicios\ServicioDeSuspensiones;
use IndieCinema\Moderacion\Servicios\SuspensionDeVencidas;
use IndieCinema\Moderacion\Servicios\UsuariosDeCuentas;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * La tarea diaria que suspende las salas con un documento vencido (EF-07.5), como la arma
 * bin/suspender-vencidas.php, contra un MySQL de verdad y una programación de mentira
 * (ConServidoresDePrueba), que recibe el pedido de apagar la sala.
 */
final class SuspensionDeVencidasTest extends TestCase
{
    use ConBaseDePruebas;
    use ConServidoresDePrueba;

    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private static ServidorSmtpDePrueba $correo;

    private BaseDeDatos $base;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        self::iniciarServidores();
        self::$correo = ServidorSmtpDePrueba::iniciar();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerServidores();
        self::$correo->detener();
    }

    protected function setUp(): void
    {
        self::reiniciarServidores();
        self::$correo->vaciar();
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'moderacion'],
            'base' => self::baseDePruebas(),
            'subsistemas' => ['cuentas' => self::$urls['cuentas'], 'programacion' => self::$urls['programacion']],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
        ]);
    }

    private function tarea(): SuspensionDeVencidas
    {
        $configuracion = $this->configuracion();
        $log = new Log('moderacion', $this->salidaDelLog);
        $cliente = ClienteInterno::desdeConfiguracion($configuracion, $log);
        $salas = new SalasDeProgramacion($cliente, new ArchivosDeSolicitudes($configuracion));

        return new SuspensionDeVencidas(
            new RepositorioDeDocumentos($this->base),
            new ServicioDeSuspensiones(new RepositorioDeSuspensiones($this->base), $salas, $log),
            new AvisosAlOrganizador(new RepositorioDeAvisos($this->base), $salas, new UsuariosDeCuentas($cliente), ClienteSmtp::desdeConfiguracion($configuracion, $log), $log),
            $log,
        );
    }

    /**
     * Una sala publicada en programación.
     */
    private function sala(string $nombre = 'El Galpón'): string
    {
        $id = Uuid::nuevo();
        self::salasEnProgramacion(self::salasDeProgramacion() + [$id => ['nombre' => $nombre, 'localidad' => 'Luján', 'organizador_id' => self::ORGANIZADOR, 'habilitada' => true]]);

        return $id;
    }

    /**
     * Un documento de la sala, aceptado salvo que se diga otra cosa, que vence $cuando (desde hoy).
     */
    private function documento(string $sala, string $tipo, string $cuando, string $estado = 'aceptado'): void
    {
        $this->base->ejecutar(
            "INSERT INTO documento (id, sala_id, tipo, archivo, vence_en, subido_en, estado)
             VALUES (?, ?, ?, '0123456789abcdef0123456789abcdef.pdf', ?, UTC_TIMESTAMP(), ?)",
            [Uuid::nuevo(), $sala, $tipo, new DateTimeImmutable('today')->modify($cuando)->format('Y-m-d'), $estado],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function suspensiones(string $sala): array
    {
        return $this->base->filas('SELECT causa, tipo_documento, motivo, abierta_por FROM suspension WHERE sala_id = ? AND levantada_en IS NULL ORDER BY tipo_documento', [$sala]);
    }

    /**
     * @return list<array{para: string, asunto: string, texto: string}> los correos que salieron, ya decodificados
     */
    private static function correos(): array
    {
        return array_map(static function (array $correo): array {
            [$cabeceras, $cuerpo] = explode("\r\n\r\n", $correo['mensaje'], 2);
            $asunto = iconv_mime_decode_headers($cabeceras, 0, 'UTF-8')['Subject'] ?? '';

            return [
                'para' => implode(',', $correo['para']),
                'asunto' => is_array($asunto) ? implode(' ', $asunto) : (string) $asunto,
                'texto' => (string) base64_decode(str_replace("\r\n", '', $cuerpo), true),
            ];
        }, self::$correo->correos());
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    public function testElSeguroVencidoSuspendeLaSalaYLaApagaEnProgramacion(): void
    {
        $sala = $this->sala();
        $this->documento($sala, 'seguro', 'today');
        $this->documento($sala, 'habilitacion', '+1 year');

        self::assertSame(1, $this->tarea()->correr());

        self::assertSame([['causa' => 'vencimiento', 'tipo_documento' => 'seguro', 'motivo' => 'Venció el seguro.', 'abierta_por' => null]], $this->suspensiones($sala));
        self::assertSame([['ruta' => 'habilitacion', 'sala' => $sala, 'habilitada' => false, 'motivo' => 'Venció el seguro.', 'moderador_id' => null]], self::pedidosA('programacion'));
        self::assertFalse(self::salasDeProgramacion()[$sala]['habilitada']);
    }

    public function testLosDosVencidosSonDosSuspensionesYUnSoloPedido(): void
    {
        $sala = $this->sala();
        $this->documento($sala, 'seguro', '-3 days');
        $this->documento($sala, 'habilitacion', '-1 day');

        self::assertSame(2, $this->tarea()->correr());

        self::assertSame(['seguro', 'habilitacion'], array_column($this->suspensiones($sala), 'tipo_documento'));
        self::assertSame('Venció la habilitación.', $this->suspensiones($sala)[1]['motivo']);
        self::assertCount(1, self::pedidosA('programacion'));
    }

    public function testCorrerlaOtraVezNoHaceNada(): void
    {
        $sala = $this->sala();
        $this->documento($sala, 'seguro', '-1 day');
        $this->tarea()->correr();
        file_put_contents(self::$registros['programacion'], '');

        self::assertSame(0, $this->tarea()->correr());

        self::assertCount(1, $this->suspensiones($sala));
        self::assertSame([], self::pedidosA('programacion'));
    }

    /**
     * Lo que vale es el aceptado con el vencimiento más lejano: uno pendiente o rechazado no
     * cuenta, y uno vencido con otro aceptado vigente del mismo tipo, tampoco.
     */
    public function testSoloCuentaElAceptadoQueVenceMasLejos(): void
    {
        $manana = $this->sala('Mañana');
        $this->documento($manana, 'seguro', '+1 day');
        $renovada = $this->sala('Renovada');
        $this->documento($renovada, 'seguro', '-10 days');
        $this->documento($renovada, 'seguro', '+1 year');
        $pendiente = $this->sala('Pendiente');
        $this->documento($pendiente, 'seguro', '-1 day', 'pendiente');
        $rechazada = $this->sala('Rechazada');
        $this->documento($rechazada, 'seguro', '-1 day', 'rechazado');

        self::assertSame(0, $this->tarea()->correr());

        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM suspension'));
        self::assertSame([], self::pedidosA('programacion'));
    }

    /**
     * Si programación no responde, esa sala queda para la próxima vuelta y las demás siguen.
     */
    public function testSiProgramacionNoRespondeLaSalaQuedaParaLaProximaVuelta(): void
    {
        $sala = $this->sala();
        $this->documento($sala, 'seguro', '-1 day');
        self::tirar('programacion');

        self::assertSame(0, $this->tarea()->correr());

        self::assertSame([], $this->suspensiones($sala));
        self::assertStringContainsString("No se suspendió la sala {$sala}", $this->log());

        self::reiniciarServidores();
        self::salasEnProgramacion([$sala => ['nombre' => 'El Galpón', 'localidad' => 'Luján', 'organizador_id' => self::ORGANIZADOR, 'habilitada' => true]]);
        self::assertSame(1, $this->tarea()->correr());
    }

    /**
     * Siete días antes, el organizador recibe un aviso, una sola vez por documento.
     */
    public function testAvisaSieteDiasAntesDelVencimientoUnaSolaVez(): void
    {
        $sala = $this->sala();
        $this->documento($sala, 'seguro', '+7 days');
        $this->documento($sala, 'habilitacion', '+8 days');

        $this->tarea()->correr();
        $this->tarea()->correr();

        $correos = self::correos();
        self::assertCount(1, $correos);
        self::assertSame('<omar@ejemplo.com>', $correos[0]['para']);
        self::assertSame('Se vence el seguro de «El Galpón»', $correos[0]['asunto']);
        self::assertStringContainsString('vence el ' . new DateTimeImmutable('today')->modify('+7 days')->format('d/m/Y'), $correos[0]['texto']);
        self::assertStringContainsString("/moderacion/salas/{$sala}/documentacion", $correos[0]['texto']);
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM suspension'));
    }

    public function testAlSuspenderleAvisaAlOrganizadorConElMotivo(): void
    {
        $sala = $this->sala();
        $this->documento($sala, 'habilitacion', '-1 day');

        $this->tarea()->correr();
        $this->tarea()->correr();

        $correos = self::correos();
        self::assertCount(1, $correos);
        self::assertSame('Suspendimos la sala «El Galpón»', $correos[0]['asunto']);
        self::assertStringContainsString('Venció la habilitación.', $correos[0]['texto']);
        self::assertStringContainsString("/moderacion/salas/{$sala}/documentacion", $correos[0]['texto']);
    }

    /**
     * Sin cuentas no hay a quién escribirle: la suspensión queda y el aviso sale en la próxima
     * vuelta.
     */
    public function testSiCuentasNoRespondeLaSuspensionQuedaYElAvisoSeReintenta(): void
    {
        $sala = $this->sala();
        $this->documento($sala, 'seguro', '-1 day');
        self::tirar('cuentas');

        self::assertSame(1, $this->tarea()->correr());

        self::assertCount(1, $this->suspensiones($sala));
        self::assertSame([], self::correos());
        self::assertStringContainsString("No salió el aviso a la sala {$sala}", $this->log());

        self::reiniciarServidores();
        self::salasEnProgramacion([$sala => ['nombre' => 'El Galpón', 'localidad' => 'Luján', 'organizador_id' => self::ORGANIZADOR, 'habilitada' => false]]);
        $this->tarea()->correr();
        self::assertCount(1, self::correos());
    }

    /**
     * El crontab del servidor la corre una vez por día.
     */
    public function testEstaEnElCrontab(): void
    {
        self::assertMatchesRegularExpression(
            '#^\d+ \d+ \* \* \* cd @BACK@ && docker compose exec -T moderacion php bin/suspender-vencidas\.php$#m',
            (string) file_get_contents(dirname(__DIR__, 2) . '/herramientas/crontab'),
        );
    }
}
