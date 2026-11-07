<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Pruebas\Errores;

use ErrorException;
use IndieCinema\Nucleo\Errores\ManejadorDeErrores;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\ControladorDePrueba;
use IndieCinema\Nucleo\Ruteo\Ruta;
use IndieCinema\Nucleo\Vista;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * E4-11.2: en producción un error no le muestra al usuario ni el mensaje interno ni la traza, ni
 * en HTML ni en JSON, y el detalle completo queda en el log.
 *
 * AplicacionTest ya cubre el camino de una petición común (la página de 500, el JSON de quien
 * manda «Accept: application/json» y el detalle que sí aparece en desarrollo). Acá van los bordes
 * del manejador, que son los que pueden filtrar algo sin que nadie lo note: los avisos de PHP,
 * las rutas internas, y qué queda si falla hasta la plantilla de error.
 */
final class ManejadorDeErroresTest extends TestCase
{
    private const MENSAJE_GENERICO = 'Algo salió mal. Probá de nuevo en un rato.';
    private const DETALLE = 'detalle interno que no tiene que ver el usuario';
    private const PLANTILLAS = __DIR__ . '/../../plantillas';

    /** @var resource */
    private $salidaDelLog;

    private string $erroresEnPantalla;

    private int $nivelDeError;

    private bool $instalado = false;

    protected function setUp(): void
    {
        $this->salidaDelLog = fopen('php://memory', 'w+');
        $this->erroresEnPantalla = (string) ini_get('display_errors');
        $this->nivelDeError = error_reporting();
    }

    /**
     * instalar() cambia el manejador de errores y dos ini de todo el proceso: hay que devolverlos
     * o la prueba siguiente corre con los de esta.
     */
    protected function tearDown(): void
    {
        if ($this->instalado) {
            restore_error_handler();
            $this->instalado = false;
        }
        ini_set('display_errors', $this->erroresEnPantalla);
        error_reporting($this->nivelDeError);
    }

    private function manejador(bool $mostrarDetalle = false): ManejadorDeErrores
    {
        return new ManejadorDeErrores(new Log('prueba', $this->salidaDelLog), $mostrarDetalle);
    }

    private function instalar(): void
    {
        $this->manejador()->instalar();
        $this->instalado = true;
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    /**
     * @param list<string> $directorios sin ninguno, no hay ni plantilla de error que renderizar
     */
    private static function vista(array $directorios = [self::PLANTILLAS]): Vista
    {
        return new Vista($directorios, '', false);
    }

    public function testInstalarApagaLosErroresEnPantalla(): void
    {
        ini_set('display_errors', '1');

        $this->instalar();

        self::assertSame('0', ini_get('display_errors'));
    }

    public function testUnAvisoDePhpCortaLaPeticionEnLugarDeImprimirse(): void
    {
        $this->instalar();

        // Sin el manejador, esto saldría impreso en medio del HTML que se estaba armando.
        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('aviso interno de PHP');

        trigger_error('aviso interno de PHP', E_USER_WARNING);
    }

    public function testUnAvisoSilenciadoConArrobaNoCortaLaPeticion(): void
    {
        $this->instalar();

        // Con el @, el manejador devuelve false y PHP lo ignora. Lo que se prueba es que la línea
        // no corte: si cortara, la ErrorException haría fallar la prueba acá mismo, y el aviso
        // tampoco tiene por qué haber dejado nada en el log.
        @trigger_error('silenciado con arroba', E_USER_WARNING);

        self::assertSame('', $this->log());
    }

    public function testEnDesarrolloElJsonSiLlevaElDetalle(): void
    {
        $respuesta = $this->manejador(mostrarDetalle: true)->respuestaPara(
            new RuntimeException(self::DETALLE),
            new Peticion('POST', '/reservas', cabeceras: ['Accept' => 'application/json']),
            self::vista(),
        );

        self::assertStringContainsString(self::DETALLE, $respuesta->cuerpo());
    }

    /**
     * Una ruta /interno/ la llama otro subsistema, no un navegador: la respuesta es JSON aunque no
     * haya mandado «Accept: application/json», y tampoco ahí va el detalle.
     */
    public function testUnErrorEnUnaRutaInternaRespondeJsonAunqueNoPidaJson(): void
    {
        $ruta = new Ruta('GET', '/interno/falla', [ControladorDePrueba::class, 'falla'], llamadores: ['programacion']);
        $peticion = (new Peticion('GET', '/interno/falla'))->conRuta($ruta, []);

        $respuesta = $this->manejador()->respuestaPara(new RuntimeException(self::DETALLE), $peticion, self::vista());

        self::assertSame(500, $respuesta->estado());
        self::assertSame('{"error":"' . self::MENSAJE_GENERICO . '"}', $respuesta->cuerpo());
        self::assertStringNotContainsString(self::DETALLE, $respuesta->cuerpo());
    }

    /**
     * El último respaldo: si no se puede renderizar ni la página de error, sale texto plano con el
     * mensaje genérico. Acá el manejador está en modo desarrollo a propósito, para ver que ni así
     * el detalle llega al cuerpo.
     */
    public function testSiFallaLaPlantillaDeErrorRespondeTextoPlanoSinElDetalle(): void
    {
        $respuesta = $this->manejador(mostrarDetalle: true)->respuestaPara(
            new RuntimeException(self::DETALLE),
            new Peticion('GET', '/cartelera'),
            self::vista([]),
        );

        self::assertSame(500, $respuesta->estado());
        self::assertSame(self::MENSAJE_GENERICO, $respuesta->cuerpo());
        self::assertSame('text/plain; charset=utf-8', $respuesta->cabecera('Content-Type'));
        self::assertStringContainsString('No se pudo mostrar la página de error.', $this->log());
    }

    /**
     * Una ExcepcionHttp es una respuesta prevista (un 403, un 405), no una falla: su mensaje es
     * para el usuario, lleva sus cabeceras y no ensucia el log de errores.
     */
    public function testUnaExcepcionHttpConservaSuMensajeYSusCabecerasYNoVaAlLog(): void
    {
        $respuesta = $this->manejador()->respuestaPara(
            ExcepcionHttp::metodoNoPermitido(['GET', 'POST']),
            new Peticion('DELETE', '/cartelera', cabeceras: ['Accept' => 'application/json']),
            self::vista(),
        );

        self::assertSame(405, $respuesta->estado());
        self::assertSame('GET, POST', $respuesta->cabecera('Allow'));
        self::assertSame('{"error":"Esta página no acepta ese tipo de pedido."}', $respuesta->cuerpo());
        self::assertSame('', $this->log());
    }
}
