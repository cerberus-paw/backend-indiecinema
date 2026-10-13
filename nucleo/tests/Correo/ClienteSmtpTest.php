<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Correo;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Correo\Correo;
use IndieCinema\Nucleo\Correo\CorreoNoEnviado;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * El cliente manda por la red de verdad a un servidor SMTP de mentira, que anota lo que recibe:
 * así se prueba la conversación con curl y el correo tal como llega.
 */
final class ClienteSmtpTest extends TestCase
{
    private static ServidorSmtpDePrueba $servidor;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        self::$servidor = ServidorSmtpDePrueba::iniciar();
    }

    public static function tearDownAfterClass(): void
    {
        self::$servidor->detener();
    }

    protected function setUp(): void
    {
        self::$servidor->vaciar();
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    private function cliente(?string $servidor = null, int $esperaMaximaMs = 2000): ClienteSmtp
    {
        return new ClienteSmtp(
            $servidor ?? self::$servidor->url(),
            false,
            'no-responder@indiecinema.test',
            'IndieCinema Mañana',
            null,
            null,
            new Log('prueba', $this->salidaDelLog),
            $esperaMaximaMs,
        );
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    /**
     * Las cabeceras ya decodificadas y el texto, de un mensaje como llegó al servidor.
     *
     * @return array{array<string, string>, string}
     */
    private static function separar(string $mensaje): array
    {
        [$crudas, $cuerpo] = explode("\r\n\r\n", $mensaje, 2);
        $cabeceras = [];
        // Una cabecera larga sigue en el renglón siguiente, que empieza con un espacio.
        foreach (explode("\r\n", (string) preg_replace("/\r\n(?=[ \t])/", '', $crudas)) as $linea) {
            [$nombre, $valor] = explode(': ', $linea, 2);
            $cabeceras[$nombre] = mb_decode_mimeheader($valor);
        }

        return [$cabeceras, (string) base64_decode(str_replace("\r\n", '', $cuerpo), true)];
    }

    public function testMandaElCorreoConSusCabecerasYElTexto(): void
    {
        $this->cliente()->enviar(new Correo(
            'equipo@indiecinema.test',
            'Solicitud para ser organizador: El Galpón de la Estación, en Luján, con un nombre largo que no entra en un renglón',
            "Hola:\nla sala tiene 60 butacas.\n\nSaludos.",
            'galpon@ejemplo.com',
        ));

        $correos = self::$servidor->correos();
        self::assertCount(1, $correos);
        self::assertSame('<no-responder@indiecinema.test>', $correos[0]['de']);
        self::assertSame(['<equipo@indiecinema.test>'], $correos[0]['para']);

        [$cabeceras, $texto] = self::separar($correos[0]['mensaje']);
        self::assertSame('IndieCinema Mañana <no-responder@indiecinema.test>', $cabeceras['From']);
        self::assertSame('<equipo@indiecinema.test>', $cabeceras['To']);
        self::assertSame('<galpon@ejemplo.com>', $cabeceras['Reply-To']);
        self::assertSame('Solicitud para ser organizador: El Galpón de la Estación, en Luján, con un nombre largo que no entra en un renglón', $cabeceras['Subject']);
        self::assertSame('text/plain; charset=UTF-8', $cabeceras['Content-Type']);
        self::assertMatchesRegularExpression('/^<[0-9a-f-]{36}@indiecinema\.test>$/', $cabeceras['Message-ID']);
        self::assertSame("Hola:\r\nla sala tiene 60 butacas.\r\n\r\nSaludos.", $texto);
    }

    public function testSinDireccionDeRespuestaNoLlevaReplyTo(): void
    {
        $this->cliente()->enviar(new Correo('equipo@indiecinema.test', 'Prueba', 'Texto.'));

        [$cabeceras] = self::separar(self::$servidor->correos()[0]['mensaje']);
        self::assertArrayNotHasKey('Reply-To', $cabeceras);
    }

    /**
     * En SMTP, un renglón con un punto solo cierra el mensaje: en base64 el texto no puede tenerlo.
     */
    public function testUnRenglonConUnPuntoSoloNoCortaElTexto(): void
    {
        $this->cliente()->enviar(new Correo('equipo@indiecinema.test', 'Prueba', "Antes\n.\nDespués"));

        [, $texto] = self::separar(self::$servidor->correos()[0]['mensaje']);
        self::assertSame("Antes\r\n.\r\nDespués", $texto);
    }

    public function testSiElServidorRechazaElDestinatarioAvisaYLoAnotaSinLaDireccion(): void
    {
        try {
            $this->cliente()->enviar(new Correo('rechazado@indiecinema.test', 'Prueba', 'Texto.'));
            self::fail('Tenía que avisar que no se mandó.');
        } catch (CorreoNoEnviado) {
        }

        self::assertSame([], self::$servidor->correos());
        self::assertStringContainsString('El servidor de correo no aceptó el correo', $this->log());
        self::assertStringNotContainsString('rechazado@', $this->log());
    }

    public function testSiElServidorNoRespondeNoEsperaDeMas(): void
    {
        $inicio = microtime(true);
        try {
            // En el puerto 1 no escucha nadie.
            $this->cliente('smtp://127.0.0.1:1', 500)->enviar(new Correo('equipo@indiecinema.test', 'Prueba', 'Texto.'));
            self::fail('Tenía que avisar que no se mandó.');
        } catch (CorreoNoEnviado) {
        }

        self::assertLessThan(2, microtime(true) - $inicio);
    }

    public function testUnaDireccionNoPuedeColarOtraCabecera(): void
    {
        foreach (["equipo@indiecinema.test\r\nBcc: otro@ejemplo.com", 'equipo@indiecinema.test>, <otro@ejemplo.com'] as $direccion) {
            try {
                $this->cliente()->enviar(new Correo('equipo@indiecinema.test', 'Prueba', 'Texto.', $direccion));
                self::fail('Tenía que rechazar la dirección.');
            } catch (InvalidArgumentException) {
            }
        }

        self::assertSame([], self::$servidor->correos());
    }

    public function testElAsuntoNoPuedeTenerSaltosDeLinea(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cliente()->enviar(new Correo('equipo@indiecinema.test', "Prueba\r\nBcc: otro@ejemplo.com", 'Texto.'));
    }

    public function testSoloHablaSmtp(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->cliente('file:///etc/passwd');
    }

    public function testConUsuarioNoMandaLaClaveSinCifrar(): void
    {
        $configuracion = static fn (array $correo): Configuracion => new Configuracion(['correo' => $correo + [
            'servidor' => 'smtp://correo.ejemplo.com:587',
            'remitente' => 'no-responder@indiecinema.test',
            'usuario' => 'indiecinema',
            'clave' => 'secreta',
        ]]);
        $log = new Log('prueba', $this->salidaDelLog);

        // Con STARTTLS o con TLS desde el principio, sí.
        ClienteSmtp::desdeConfiguracion($configuracion(['starttls' => true]), $log);
        ClienteSmtp::desdeConfiguracion($configuracion(['servidor' => 'smtps://correo.ejemplo.com:465']), $log);

        $this->expectException(InvalidArgumentException::class);
        ClienteSmtp::desdeConfiguracion($configuracion([]), $log);
    }

    public function testSinUsuarioNoPideClave(): void
    {
        $cliente = ClienteSmtp::desdeConfiguracion(new Configuracion(['correo' => [
            'servidor' => self::$servidor->url(),
            'remitente' => 'no-responder@indiecinema.test',
        ]]), new Log('prueba', $this->salidaDelLog));

        $cliente->enviar(new Correo('equipo@indiecinema.test', 'Prueba', 'Texto.'));

        self::assertCount(1, self::$servidor->correos());
    }
}
