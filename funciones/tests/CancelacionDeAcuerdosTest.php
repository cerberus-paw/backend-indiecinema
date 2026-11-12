<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * El aviso a los que marcaron «Me sirve» cuando programación cancela la función porque el acuerdo
 * venció sin mínimo (EF-03.2), por la API interna, con un cuentas de mentira y un servidor de
 * correo de prueba.
 */
final class CancelacionDeAcuerdosTest extends TestCase
{
    use ConBaseDePruebas;
    use ConCuentasDePrueba;

    private const TOKEN_PROGRAMACION = 'token-de-programacion';
    private const FUNCION = '11111111-1111-4111-8111-111111111111';
    private const EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ELIAS = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private static ServidorSmtpDePrueba $correo;

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    public static function setUpBeforeClass(): void
    {
        self::$correo = ServidorSmtpDePrueba::iniciar();
        self::iniciarCuentas();
    }

    public static function tearDownAfterClass(): void
    {
        self::$correo->detener();
        self::detenerCuentas();
    }

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto'],
            'subsistemas' => ['cuentas' => self::$urlCuentas],
            'interno' => ['token' => 'token-de-funciones'],
            'llamadores' => ['programacion' => hash('sha256', self::TOKEN_PROGRAMACION)],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
            'sitio' => ['url' => 'http://localhost:8080'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        self::$correo->vaciar();
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function avisar(string $acuerdoId, array $cuerpo = ['titulo' => 'Zama'], string $token = self::TOKEN_PROGRAMACION): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion(
            'POST',
            "/interno/acuerdos/{$acuerdoId}/avisar-cancelacion",
            [],
            $cuerpo,
            ['X-Llamador' => 'programacion', 'Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
            [],
            [],
        ));
    }

    /**
     * Un acuerdo con dos franjas, mañana y pasado (con franjas pasadas, funciones lo cerraría sin
     * mínimo al leerlo): Eva marcó las dos y Elías una.
     */
    private function acuerdo(string $estado): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO acuerdo_fecha (id, funcion_id, minimo_asistentes, estado, creado_en, cerrado_en) VALUES (?, ?, 20, ?, NOW(), IF(? = 'abierto', NULL, NOW()))",
            [$id, self::FUNCION, $estado, $estado],
        );
        $franjas = [Uuid::nuevo(), Uuid::nuevo()];
        foreach ($franjas as $dias => $franja) {
            $this->base->ejecutar(
                'INSERT INTO acuerdo_franja (id, acuerdo_id, fecha_hora_inicio, creada_en) VALUES (?, ?, NOW() + INTERVAL ? DAY, NOW())',
                [$franja, $id, $dias + 1],
            );
        }
        foreach ([[self::EVA, $franjas[0]], [self::EVA, $franjas[1]], [self::ELIAS, $franjas[1]]] as [$usuario, $franja]) {
            $this->base->ejecutar(
                'INSERT INTO voto_acuerdo (id, acuerdo_id, funcion_id, franja_id, usuario_id, creado_en) VALUES (?, ?, ?, ?, ?, NOW())',
                [Uuid::nuevo(), $id, self::FUNCION, $franja, $usuario],
            );
        }

        return $id;
    }

    public function testAvisaUnaVezACadaUnoDeLosQueMarcaron(): void
    {
        $acuerdo = $this->acuerdo('sin_minimo');

        $respuesta = $this->avisar($acuerdo);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(['avisados' => 2], json_decode($respuesta->cuerpo(), true));
        // Los correos salen después de responder, para que programación no los espere.
        self::assertCount(0, self::$correo->correos());
        $respuesta->terminar();
        $correos = self::$correo->correos();
        self::assertCount(2, $correos);
        self::assertEqualsCanonicalizing(['<' . self::EVA . '@ejemplo.test>', '<' . self::ELIAS . '@ejemplo.test>'], array_merge(...array_column($correos, 'para')));
        [, $cuerpo] = explode("\r\n\r\n", $correos[0]['mensaje'], 2);
        self::assertStringContainsString('el mínimo de 20 asistentes', (string) base64_decode(str_replace("\r\n", '', $cuerpo), true));
    }

    /**
     * La tarea lo vuelve a pedir si no recibió la respuesta: la segunda vez no sale ningún correo.
     */
    public function testPedirloOtraVezNoRepiteLosCorreos(): void
    {
        $acuerdo = $this->acuerdo('sin_minimo');
        $this->avisar($acuerdo)->terminar();
        self::$correo->vaciar();

        $otraVez = $this->avisar($acuerdo);

        self::assertSame(['avisados' => 0], json_decode($otraVez->cuerpo(), true));
        $otraVez->terminar();
        self::assertCount(0, self::$correo->correos());
    }

    public function testSoloUnAcuerdoQueCerroSinMinimoYSoloLoPideProgramacion(): void
    {
        self::assertSame(422, $this->avisar($this->acuerdo('abierto'))->estado());
        self::assertSame(422, $this->avisar(Uuid::nuevo())->estado());
        self::assertSame(400, $this->avisar($this->acuerdo('sin_minimo'), [])->estado());
        self::assertSame(403, $this->avisar($this->acuerdo('sin_minimo'), token: 'otro-token')->estado());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM acuerdo_fecha WHERE cancelacion_avisada_en IS NOT NULL'));
    }
}
