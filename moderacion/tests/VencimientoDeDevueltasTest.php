<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Moderacion\Repositorios\RepositorioDeAvisos;
use IndieCinema\Moderacion\Repositorios\RepositorioDeSolicitudes;
use IndieCinema\Moderacion\Servicios\UsuariosDeCuentas;
use IndieCinema\Moderacion\Servicios\VencimientoDeDevueltas;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * La tarea diaria que cuida el plazo de las solicitudes devueltas (EF-22.10), como la arma
 * bin/vencer-devueltas.php, contra un MySQL de verdad, una cuentas de mentira
 * (ConServidoresDePrueba), que da el correo del solicitante, y el buzón de prueba.
 */
final class VencimientoDeDevueltasTest extends TestCase
{
    use ConBaseDePruebas;
    use ConServidoresDePrueba;

    /** La que conoce Dobles/cuentas.php: Eva Prueba, eva@ejemplo.com. */
    private const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const MODERADORA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

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

    private function configuracion(?string $servidorDeCorreo = null): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'moderacion'],
            'base' => self::baseDePruebas(),
            'subsistemas' => ['cuentas' => self::$urls['cuentas'], 'programacion' => self::$urls['programacion']],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => $servidorDeCorreo ?? self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
            'solicitudes' => ['destino' => 'equipo@indiecinema.test', 'plazo_devolucion_dias' => 15, 'recordatorio_devolucion_dias' => 3],
        ]);
    }

    private function tarea(?string $servidorDeCorreo = null): VencimientoDeDevueltas
    {
        $configuracion = $this->configuracion($servidorDeCorreo);
        $log = new Log('moderacion', $this->salidaDelLog);

        return new VencimientoDeDevueltas(
            new RepositorioDeSolicitudes($this->base),
            new RepositorioDeAvisos($this->base),
            new UsuariosDeCuentas(ClienteInterno::desdeConfiguracion($configuracion, $log)),
            ClienteSmtp::desdeConfiguracion($configuracion, $log),
            $configuracion,
            $log,
        );
    }

    private static function hoy(): DateTimeImmutable
    {
        return new DateTimeImmutable('today', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    /**
     * Una solicitud devuelta por el seguro, cuyo plazo vence $vence (desde hoy, en Argentina).
     */
    private function devuelta(string $vence, string $sala = 'El Galpón', bool $cambio = false): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO solicitud_sala (id, sala_id, usuario_id, nombre, descripcion, direccion, localidad, capacidad,
                peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, estado, enviada_en, cambio,
                devolucion_motivo, devuelta_por, devuelta_en, devolucion_vence, devolucion_faltantes)
             VALUES (?, ?, ?, ?, 'Un galpón.', 'Calle 1', 'Luján', 40, 2, 120, 15, 'devuelta', UTC_TIMESTAMP(), ?,
                'La póliza venció.', ?, UTC_TIMESTAMP(), ?, 'seguro')",
            [$id, Uuid::nuevo(), self::ESPECTADORA, $sala, (int) $cambio, self::MODERADORA, self::hoy()->modify($vence)->format('Y-m-d')],
        );
        $this->base->ejecutar(
            "INSERT INTO documento (id, sala_id, solicitud_id, tipo, archivo, vence_en, subido_en, estado)
             SELECT ?, sala_id, id, 'habilitacion', '0123456789abcdef0123456789abcdef.pdf', CURDATE() + INTERVAL 1 YEAR, UTC_TIMESTAMP(), 'pendiente'
             FROM solicitud_sala WHERE id = ?",
            [Uuid::nuevo(), $id],
        );

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function solicitud(string $id): array
    {
        return $this->base->fila('SELECT estado, motivo, resuelta_por, resuelta_en IS NOT NULL AS resuelta FROM solicitud_sala WHERE id = ?', [$id]) ?? [];
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

    public function testUnaDevueltaConElPlazoVencidoQuedaVencidaYSeLeAvisa(): void
    {
        $id = $this->devuelta('-1 day');

        self::assertSame(1, $this->tarea()->correr());

        self::assertSame(['estado' => 'vencida', 'motivo' => 'Venció el plazo para completar la documentación.', 'resuelta_por' => null, 'resuelta' => 1], $this->solicitud($id));
        // Lo que quedaba pendiente se cierra con ella.
        self::assertSame('rechazado', $this->base->valor('SELECT estado FROM documento WHERE solicitud_id = ?', [$id]));
        $correos = self::correos();
        self::assertCount(1, $correos);
        self::assertSame('<eva@ejemplo.com>', $correos[0]['para']);
        self::assertSame('Venció la solicitud de «El Galpón»', $correos[0]['asunto']);
        self::assertStringContainsString('el seguro', $correos[0]['texto']);
        self::assertStringContainsString('mandar una solicitud nueva en /solicitar-sala', $correos[0]['texto']);
    }

    public function testCorrerlaOtraVezNoRepiteNada(): void
    {
        $id = $this->devuelta('-1 day');
        $this->tarea()->correr();
        self::$correo->vaciar();

        self::assertSame(0, $this->tarea()->correr());

        self::assertSame('vencida', $this->solicitud($id)['estado']);
        self::assertSame([], self::correos());
    }

    /**
     * El último día todavía la puede completar: vence al día siguiente.
     */
    public function testElUltimoDiaNoVence(): void
    {
        $id = $this->devuelta('today');

        self::assertSame(0, $this->tarea()->correr());

        self::assertSame('devuelta', $this->solicitud($id)['estado']);
    }

    public function testATresDiasLeLlegaElRecordatorioUnaSolaVez(): void
    {
        $id = $this->devuelta('+3 days');

        $this->tarea()->correr();
        $this->tarea()->correr();

        self::assertSame('devuelta', $this->solicitud($id)['estado']);
        $correos = self::correos();
        self::assertCount(1, $correos);
        self::assertSame('<eva@ejemplo.com>', $correos[0]['para']);
        self::assertSame('Se termina el plazo para completar la solicitud de «El Galpón»', $correos[0]['asunto']);
        self::assertStringContainsString('Tenés hasta el ' . self::hoy()->modify('+3 days')->format('d/m/Y'), $correos[0]['texto']);
        self::assertStringContainsString('vuelvas a subir el seguro', $correos[0]['texto']);
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM aviso WHERE clave = ?', ["devuelta-recordatorio:{$id}:" . self::hoy()->modify('+3 days')->format('Y-m-d')]));
    }

    public function testConMasDeTresDiasNoHayRecordatorio(): void
    {
        $this->devuelta('+4 days');

        $this->tarea()->correr();

        self::assertSame([], self::correos());
    }

    /**
     * Una segunda devolución tiene otro plazo, y su propio recordatorio.
     */
    public function testDevueltaDosVecesRecibeElRecordatorioDeLaSegunda(): void
    {
        $id = $this->devuelta('+2 days');
        $this->tarea()->correr();
        // La completó, se la devolvieron otra vez y ya le quedan tres días.
        $this->base->ejecutar('UPDATE solicitud_sala SET devolucion_vence = ? WHERE id = ?', [self::hoy()->modify('+3 days')->format('Y-m-d'), $id]);

        $this->tarea()->correr();

        self::assertCount(2, self::correos());
    }

    public function testLasQueNoEstanDevueltasNoSeTocan(): void
    {
        $id = $this->devuelta('-1 day');
        $this->base->ejecutar("UPDATE solicitud_sala SET estado = 'pendiente' WHERE id = ?", [$id]);

        self::assertSame(0, $this->tarea()->correr());

        self::assertSame('pendiente', $this->solicitud($id)['estado']);
        self::assertSame([], self::correos());
    }

    /**
     * Si el correo falla, la solicitud queda vencida igual y el aviso sale en la próxima vuelta.
     */
    public function testSiElCorreoFallaSeReintentaEnLaProximaVuelta(): void
    {
        $vencida = $this->devuelta('-1 day');
        $porVencer = $this->devuelta('+1 day', 'La Terraza');

        self::assertSame(1, $this->tarea('smtp://127.0.0.1:1')->correr());

        self::assertSame('vencida', $this->solicitud($vencida)['estado']);
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM aviso'));
        self::assertStringContainsString("No se le avisó al solicitante de la solicitud {$vencida}", $this->log());

        $this->tarea()->correr();

        self::assertSame(['Venció la solicitud de «El Galpón»', 'Se termina el plazo para completar la solicitud de «La Terraza»'], array_column(self::correos(), 'asunto'));
        self::assertSame('devuelta', $this->solicitud($porVencer)['estado']);
    }

    public function testLosCorreosDeUnCambioDeDireccionLoDicen(): void
    {
        $this->devuelta('-1 day', cambio: true);
        $this->devuelta('+1 day', 'La Terraza', cambio: true);

        $this->tarea()->correr();

        $correos = self::correos();
        self::assertSame(['Venció el pedido de cambio de dirección de «El Galpón»', 'Se termina el plazo para completar el cambio de dirección de «La Terraza»'], array_column($correos, 'asunto'));
        foreach ($correos as $correo) {
            self::assertStringContainsString('La sala sigue publicada con la dirección de antes.', $correo['texto']);
        }
        self::assertStringContainsString('pedirlo de nuevo desde «Mis salas»', $correos[0]['texto']);
    }
}
