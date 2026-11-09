<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use PHPUnit\Framework\TestCase;

/**
 * La reserva de una función paga con el cobro prendido (X-01.2), de punta a punta: las rutas de
 * funciones con la sesión que manda nginx, la base de verdad, una programación de mentira con
 * funciones a 3500 y a la gorra, y un Mercado Pago de mentira que crea las preferencias.
 */
final class PagoConMercadoPagoTest extends TestCase
{
    use ConBaseDePruebas;
    use ConCuentasDePrueba;
    use ConMercadoPagoDePrueba;
    use ConProgramacionDePrueba {
        setUpBeforeClass as iniciarProgramacion;
        tearDownAfterClass as detenerProgramacion;
    }

    private const SECRETO = 'secreto-de-nginx';
    private const ACEPTA_JSON = 'application/json, text/plain, */*';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ELIAS = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const PROXIMA = '12121212-1212-4212-8212-121212121212';
    private const A_LA_GORRA = '78787878-7878-4878-8878-787878787878';

    private const SITIO = 'https://indiecinema.test';

    private static ServidorSmtpDePrueba $correo;

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    public static function setUpBeforeClass(): void
    {
        self::iniciarProgramacion();
        self::$correo = ServidorSmtpDePrueba::iniciar();
        self::iniciarCuentas();
        self::iniciarMercadoPago();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerProgramacion();
        self::$correo->detener();
        self::detenerCuentas();
        self::detenerMercadoPago();
    }

    protected function setUp(): void
    {
        $this->configurar();
        $this->base = self::baseConTablasNuevas($this->configuracion);
        self::$correo->vaciar();
        self::vaciarMercadoPago();
    }

    /**
     * @param array<string, array<string, mixed>> $cambios secciones que reemplazan a las de siempre
     */
    private function configurar(array $cambios = []): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['programacion' => self::$url, 'cuentas' => self::$urlCuentas],
            'interno' => ['token' => 'token-de-funciones'],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test', 'nombre' => 'IndieCinema'],
            'sitio' => ['url' => self::SITIO],
            'mercadopago' => self::mercadoPagoPrendido(),
            ...$cambios,
        ]);
    }

    /**
     * @param array<string, string> $cuerpo
     */
    private function pedir(string $metodo, string $ruta, array $cuerpo = [], ?string $acepta = null, string $usuario = self::EVA): Respuesta
    {
        $cabeceras = ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => 'espectador'];
        if ($acepta !== null) {
            $cabeceras['Accept'] = $acepta;
        }
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        $respuesta = $aplicacion->atender(new Peticion($metodo, (string) parse_url($ruta, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF], []));
        // Lo que sale después de responder, como el correo con fetch.
        $respuesta->terminar();

        return $respuesta;
    }

    private function reservar(string $cantidad = '2', string $funcion = self::PROXIMA, ?string $acepta = null): Respuesta
    {
        return $this->pedir('POST', "/reservar/{$funcion}", ['_csrf' => self::CSRF, 'cantidad' => $cantidad], $acepta);
    }

    /**
     * @return array<string, mixed>
     */
    private function ultimaReserva(): array
    {
        return $this->base->fila('SELECT * FROM reserva ORDER BY creada_en DESC, id LIMIT 1') ?? self::fail('No quedó ninguna reserva.');
    }

    /**
     * La preferencia que recibió Mercado Pago.
     *
     * @return array<string, mixed>
     */
    private static function preferencia(): array
    {
        $preferencias = array_values(array_filter(self::pedidosAMercadoPago(), static fn (array $pedido): bool => $pedido['ruta'] === '/checkout/preferences'));
        self::assertCount(1, $preferencias);

        return $preferencias[0]['cuerpo'];
    }

    public function testUnaFuncionPagaQuedaPendienteDePagoYVaAlPasoDelPago(): void
    {
        $respuesta = $this->reservar('2');

        $reserva = $this->ultimaReserva();
        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/funcion/reservas/{$reserva['id']}", $respuesta->cabecera('Location'));
        self::assertSame('pendiente_pago', $reserva['estado']);
        self::assertSame('7000.00', $reserva['total']);
        self::assertNotNull($reserva['preferencia_id']);
        self::assertStringStartsWith('https://www.mercadopago.com.ar/checkout/v1/redirect?pref_id=', (string) $reserva['enlace_de_pago']);
        // Vence en 15 minutos, contados desde que se creó.
        self::assertSame(15 * 60, strtotime((string) $reserva['vence_en']) - strtotime((string) $reserva['creada_en']));
    }

    /**
     * Lo que se paga sale de la reserva, y el aviso dice cuál pagó.
     */
    public function testLaPreferenciaCobraLaReservaYAvisaAlSitio(): void
    {
        $this->reservar('2');

        $reserva = $this->ultimaReserva();
        $preferencia = self::preferencia();
        self::assertSame($reserva['id'], $preferencia['external_reference']);
        self::assertSame(2, $preferencia['items'][0]['quantity']);
        self::assertEquals(3500, $preferencia['items'][0]['unit_price']);
        self::assertSame('ARS', $preferencia['items'][0]['currency_id']);
        self::assertSame('Entrada para «Zama»', $preferencia['items'][0]['title']);
        self::assertTrue($preferencia['binary_mode']);
        self::assertTrue($preferencia['expires']);
        self::assertSame(strtotime((string) $reserva['vence_en'] . ' UTC'), strtotime($preferencia['expiration_date_to']));
        self::assertSame(self::SITIO . '/funcion/pagos/aviso', $preferencia['notification_url']);
        self::assertSame('approved', $preferencia['auto_return']);
        // Los tres resultados vuelven a la misma página, que muestra lo que dice la base (X-01.3).
        $regreso = self::SITIO . "/funcion/reservas/{$reserva['id']}/regreso";
        self::assertSame(['success' => $regreso, 'pending' => $regreso, 'failure' => $regreso], $preferencia['back_urls']);
    }

    /**
     * Si el pedido se repite, Mercado Pago no crea otra preferencia para la misma reserva.
     */
    public function testLaPreferenciaVaConLaReservaComoClaveDeIdempotencia(): void
    {
        $this->reservar();

        $pedido = self::pedidosAMercadoPago()[0];
        self::assertSame('preferencia-' . $this->ultimaReserva()['id'], $pedido['idempotencia']);
    }

    /**
     * En localhost no hay adónde avisar: Mercado Pago rechaza una dirección que no es pública.
     */
    public function testSinHttpsNoPideElAvisoNiElRegresoAutomatico(): void
    {
        $this->configurar(['sitio' => ['url' => 'http://localhost:8080']]);

        $this->reservar();

        $preferencia = self::preferencia();
        self::assertArrayNotHasKey('notification_url', $preferencia);
        self::assertArrayNotHasKey('auto_return', $preferencia);
        self::assertSame('http://localhost:8080/funcion/reservas/' . $this->ultimaReserva()['id'] . '/regreso', $preferencia['back_urls']['success']);
    }

    /**
     * El comprobante de una pendiente es el paso «Pago»: el enlace a Mercado Pago y hasta qué hora.
     */
    public function testElComprobanteDeUnaPendienteMuestraElEnlaceParaPagar(): void
    {
        $this->reservar();
        $reserva = $this->ultimaReserva();

        $respuesta = $this->pedir('GET', "/reservas/{$reserva['id']}");

        $cuerpo = $respuesta->cuerpo();
        self::assertSame(200, $respuesta->estado(), $cuerpo);
        PaginasParaValidar::guardar('reserva-pendiente-de-pago', $cuerpo);
        self::assertStringContainsString('href="' . htmlspecialchars((string) $reserva['enlace_de_pago']) . '"', $cuerpo);
        self::assertStringContainsString('Pagar con Mercado Pago', $cuerpo);
        self::assertStringNotContainsString('Reserva confirmada', $cuerpo);
    }

    public function testElCorreoDelComprobanteNoSaleHastaQueSePaga(): void
    {
        $this->reservar();
        $this->reservar(acepta: self::ACEPTA_JSON);

        self::assertSame([], self::$correo->correos());
    }

    public function testEnJsonDevuelveLaReservaPendienteConElEnlaceParaPagar(): void
    {
        $respuesta = $this->reservar('1', acepta: self::ACEPTA_JSON);

        $datos = json_decode($respuesta->cuerpo(), true);
        $reserva = $this->ultimaReserva();
        self::assertSame(201, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('pendiente_pago', $datos['reserva']['estado']);
        self::assertSame($reserva['enlace_de_pago'], $datos['reserva']['enlace_de_pago']);
        self::assertSame("/funcion/reservas/{$reserva['id']}", $datos['url']);
        // Ocupa su lugar mientras se paga.
        self::assertSame(39, $datos['lugares_libres']);
    }

    /**
     * Si Mercado Pago no responde, la reserva no queda reteniendo lugares que nadie puede pagar.
     */
    public function testSiMercadoPagoNoRespondeLaReservaSeCancelaYLosLugaresQuedanLibres(): void
    {
        self::mercadoPagoCaido();

        $respuesta = $this->reservar('3');

        self::assertSame(503, $respuesta->estado());
        $reserva = $this->ultimaReserva();
        self::assertSame('cancelada', $reserva['estado']);
        self::assertSame(
            ['pendiente_pago', 'cancelada', self::EVA, 'espectador', 'No se pudo iniciar el pago.'],
            array_values($this->base->fila('SELECT estado_anterior, estado_nuevo, autor_id, autor_rol, motivo FROM cambio_reserva WHERE reserva_id = ?', [$reserva['id']]) ?? []),
        );
        self::assertSame(0, (int) $this->base->valor("SELECT COALESCE(SUM(cantidad), 0) FROM reserva WHERE estado <> 'cancelada'"));
    }

    /**
     * Un access token que Mercado Pago no acepta es un error de configuración: 500, y la reserva
     * tampoco queda.
     */
    public function testConUnAccessTokenQueNoSirveLaReservaSeCancela(): void
    {
        $this->configurar(['mercadopago' => ['access_token' => 'APP_USR-otro'] + self::mercadoPagoPrendido()]);

        $respuesta = $this->reservar();

        self::assertSame(500, $respuesta->estado());
        self::assertSame('cancelada', $this->ultimaReserva()['estado']);
    }

    public function testUnaFuncionALaGorraSeConfirmaSinPasarPorMercadoPago(): void
    {
        $respuesta = $this->reservar('2', self::A_LA_GORRA);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('confirmada', $this->ultimaReserva()['estado']);
        self::assertSame([], self::pedidosAMercadoPago());
        self::assertCount(1, self::$correo->correos());
    }

    public function testConElCobroApagadoUnaFuncionPagaSeConfirmaComoAntes(): void
    {
        $this->configurar(['mercadopago' => ['activo' => false]]);

        $this->reservar();

        $reserva = $this->ultimaReserva();
        self::assertSame('confirmada', $reserva['estado']);
        self::assertNull($reserva['vence_en']);
        self::assertSame([], self::pedidosAMercadoPago());
    }

    /**
     * Prendido y sin el access token, el subsistema no arranca: mejor eso que una reserva a medias.
     */
    public function testPrendidoYSinElAccessTokenNoReservaNada(): void
    {
        $mercadopago = self::mercadoPagoPrendido();
        unset($mercadopago['access_token']);
        $this->configurar(['mercadopago' => $mercadopago]);

        $respuesta = $this->reservar();

        self::assertSame(500, $respuesta->estado());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM reserva'));
    }

    /**
     * Una pendiente se cancela como una confirmada, y suelta sus lugares sin esperar a que venza.
     */
    public function testElEspectadorCancelaUnaPendiente(): void
    {
        $this->reservar('2');
        $id = (string) $this->ultimaReserva()['id'];

        $respuesta = $this->pedir('POST', "/reservas/{$id}/cancelar", ['_csrf' => self::CSRF]);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('cancelada', $this->ultimaReserva()['estado']);
    }

    /**
     * Lo que Mercado Pago agrega a la dirección de regreso: cualquiera lo puede escribir a mano.
     */
    private const REGRESO_APROBADO = '?collection_status=approved&status=approved&payment_id=123456789&payment_type=credit_card&preference_id=pref&external_reference=';

    /**
     * Volver de Mercado Pago con «approved» en la dirección no confirma nada (X-01.3): sólo el
     * aviso del pago verificado.
     */
    public function testVolverConElPagoAprobadoEnLaDireccionNoConfirmaLaReserva(): void
    {
        $this->reservar();
        $id = (string) $this->ultimaReserva()['id'];

        $respuesta = $this->pedir('GET', "/reservas/{$id}/regreso" . self::REGRESO_APROBADO . $id);

        $cuerpo = $respuesta->cuerpo();
        self::assertSame(200, $respuesta->estado(), $cuerpo);
        PaginasParaValidar::guardar('regreso-de-mercado-pago', $cuerpo);
        self::assertSame('pendiente_pago', $this->ultimaReserva()['estado']);
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM cambio_reserva'));
        self::assertStringContainsString('Estamos esperando la confirmación de Mercado Pago', $cuerpo);
        self::assertStringContainsString('Pagar con Mercado Pago', $cuerpo);
        self::assertStringNotContainsString('Pago acreditado', $cuerpo);
        self::assertSame([], self::$correo->correos());
    }

    /**
     * La página dice lo que dice la base, aunque la dirección diga lo contrario.
     */
    public function testSiElPagoYaSeAcreditoLoDiceAunqueLaDireccionDigaQueFallo(): void
    {
        $this->reservar();
        $id = (string) $this->ultimaReserva()['id'];
        $this->base->ejecutar("UPDATE reserva SET estado = 'confirmada' WHERE id = ?", [$id]);

        $cuerpo = $this->pedir('GET', "/reservas/{$id}/regreso?collection_status=rejected&status=rejected")->cuerpo();

        self::assertStringContainsString('Pago acreditado', $cuerpo);
        self::assertStringContainsString('Reserva confirmada', $cuerpo);
    }

    public function testSiLaReservaSeCanceloLoDice(): void
    {
        $this->reservar();
        $id = (string) $this->ultimaReserva()['id'];
        $this->pedir('POST', "/reservas/{$id}/cancelar", ['_csrf' => self::CSRF]);

        $cuerpo = $this->pedir('GET', "/reservas/{$id}/regreso" . self::REGRESO_APROBADO . $id)->cuerpo();

        self::assertStringContainsString('La reserva está cancelada', $cuerpo);
        self::assertSame('cancelada', $this->ultimaReserva()['estado']);
    }

    /**
     * Pasado el plazo, ya no hay enlace para pagar.
     */
    public function testSiVencioElPlazoNoOfreceElPago(): void
    {
        $this->reservar();
        $id = (string) $this->ultimaReserva()['id'];
        $this->base->ejecutar('UPDATE reserva SET vence_en = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $id]);

        $cuerpo = $this->pedir('GET', "/reservas/{$id}/regreso")->cuerpo();

        self::assertStringContainsString('Venció el plazo para pagar', $cuerpo);
        self::assertStringNotContainsString('Pagar con Mercado Pago', $cuerpo);
    }

    /**
     * La de otro espectador no existe, como el comprobante.
     */
    public function testElRegresoDeLaReservaDeOtroEs404(): void
    {
        $this->reservar();
        $id = (string) $this->ultimaReserva()['id'];

        self::assertSame(404, $this->pedir('GET', "/reservas/{$id}/regreso", usuario: self::ELIAS)->estado());
    }
}
