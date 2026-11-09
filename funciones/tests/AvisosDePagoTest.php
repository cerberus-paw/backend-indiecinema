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
 * El aviso de pago de Mercado Pago (X-01.4), de punta a punta: el POST que manda Mercado Pago,
 * sin sesión ni cookie, con su firma; el pago que devuelve su API de mentira; y la reserva en la
 * base de verdad. Los avisos falsos, repetidos o fuera de orden no confirman de más.
 */
final class AvisosDePagoTest extends TestCase
{
    use ConBaseDePruebas;
    use ConCuentasDePrueba;
    use ConMercadoPagoDePrueba;
    use ConProgramacionDePrueba {
        setUpBeforeClass as iniciarProgramacion;
        tearDownAfterClass as detenerProgramacion;
    }

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const PROXIMA = '12121212-1212-4212-8212-121212121212';
    private const PAGO = '1324567890';
    private const OTRO_PAGO = '1324567891';

    private static ServidorSmtpDePrueba $correo;

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    /** @var resource lo que escribe el log en el último pedido */
    private $log;

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
     * @param array<string, mixed> $mercadopago lo que cambia de [mercadopago]
     */
    private function configurar(array $mercadopago = []): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['programacion' => self::$url, 'cuentas' => self::$urlCuentas],
            'interno' => ['token' => 'token-de-funciones'],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test', 'nombre' => 'IndieCinema'],
            'sitio' => ['url' => 'https://indiecinema.test'],
            'mercadopago' => [...self::mercadoPagoPrendido(), ...$mercadopago],
        ]);
    }

    /**
     * @param array<string, string> $cabeceras
     * @param array<string, mixed>  $consulta
     * @param array<string, mixed>  $cuerpo
     * @param ?string               $usuario   null: sin sesión, como llega el aviso
     * @param array<string, string> $cookies
     */
    private function pedir(string $metodo, string $ruta, array $cabeceras = [], array $consulta = [], array $cuerpo = [], ?string $usuario = null, array $cookies = []): Respuesta
    {
        // nginx siempre manda su secreto; sin sesión, sin usuario.
        $cabeceras['X-Nginx-Secreto'] = self::SECRETO;
        if ($usuario !== null) {
            $cabeceras += ['X-Usuario-Id' => $usuario, 'X-Rol' => 'espectador'];
        }
        $this->log = fopen('php://memory', 'w+');
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', $this->log));
        $respuesta = $aplicacion->atender(new Peticion($metodo, $ruta, $consulta, $cuerpo, $cabeceras, $cookies, []));
        $respuesta->terminar();

        return $respuesta;
    }

    /**
     * El aviso como lo manda Mercado Pago: ?data.id=…&type=payment, con el mismo id en el cuerpo.
     * PHP deja el data.id de la dirección como data_id en $_GET.
     *
     * @param ?array<string, string> $cabeceras null: firmado con la clave del webhook
     */
    private function avisar(string $pagoId = self::PAGO, ?array $cabeceras = null, string $tipo = 'payment'): Respuesta
    {
        return $this->pedir(
            'POST',
            '/pagos/aviso',
            $cabeceras ?? self::cabecerasFirmadas($pagoId),
            ['data_id' => $pagoId, 'type' => $tipo],
            ['action' => 'payment.updated', 'api_version' => 'v1', 'type' => $tipo, 'data' => ['id' => $pagoId]],
        );
    }

    /**
     * Una reserva pendiente de pago, hecha por la página como la haría el espectador.
     *
     * @return array<string, mixed>
     */
    private function pendiente(string $cantidad = '2'): array
    {
        $respuesta = $this->pedir('POST', '/reservar/' . self::PROXIMA, cuerpo: ['_csrf' => self::CSRF, 'cantidad' => $cantidad], usuario: self::EVA, cookies: ['csrf' => self::CSRF]);
        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::vaciarMercadoPago();

        return $this->reserva();
    }

    /**
     * @return array<string, mixed>
     */
    private function reserva(): array
    {
        return $this->base->fila('SELECT * FROM reserva ORDER BY creada_en DESC, id LIMIT 1') ?? self::fail('No hay ninguna reserva.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historial(string $reservaId): array
    {
        return $this->base->filas('SELECT estado_anterior, estado_nuevo, autor_id, autor_rol, motivo FROM cambio_reserva WHERE reserva_id = ? ORDER BY id', [$reservaId]);
    }

    private function leerLog(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    public function testUnPagoAprobadoConfirmaLaReservaYMandaElComprobante(): void
    {
        $reserva = $this->pendiente();
        self::pagoEnMercadoPago(self::PAGO, $reserva['id'], $reserva['total']);

        $respuesta = $this->avisar();

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $confirmada = $this->reserva();
        self::assertSame('confirmada', $confirmada['estado']);
        self::assertSame(self::PAGO, $confirmada['pago_id']);
        self::assertSame(
            [['estado_anterior' => 'pendiente_pago', 'estado_nuevo' => 'confirmada', 'autor_id' => self::EVA, 'autor_rol' => 'espectador', 'motivo' => 'Pago ' . self::PAGO . ' aprobado en Mercado Pago.']],
            $this->historial($reserva['id']),
        );
        $correos = self::$correo->correos();
        self::assertCount(1, $correos);
        self::assertStringContainsString('Subject: Reserva confirmada', $correos[0]['mensaje']);
        self::assertSame(['<' . self::EVA . '@ejemplo.test>'], $correos[0]['para']);
        self::assertSame([], self::reembolsosPedidos());
    }

    /**
     * Mercado Pago reintenta el aviso, y manda uno por cada cambio del pago: el segundo encuentra
     * la reserva confirmada con ese pago y no hace nada. Tampoco manda otro correo.
     */
    public function testUnAvisoRepetidoNoConfirmaDosVecesNiMandaOtroCorreo(): void
    {
        $reserva = $this->pendiente();
        self::pagoEnMercadoPago(self::PAGO, $reserva['id'], $reserva['total']);

        foreach ([1, 2, 3] as $vez) {
            self::assertSame(200, $this->avisar()->estado(), "El aviso {$vez}.");
        }

        self::assertCount(1, $this->historial($reserva['id']));
        self::assertCount(1, self::$correo->correos());
        self::assertSame([], self::reembolsosPedidos());
    }

    /**
     * Lo que cuenta es cómo está el pago ahora en Mercado Pago, no el orden de los avisos: el del
     * rechazo no hace nada, y el del pago aprobado la confirma aunque llegue antes un aviso viejo.
     */
    public function testLosAvisosFueraDeOrdenNoConfirmanUnPagoRechazado(): void
    {
        $reserva = $this->pendiente();
        self::pagoEnMercadoPago(self::PAGO, $reserva['id'], $reserva['total'], ['status' => 'rejected', 'status_detail' => 'cc_rejected_other_reason']);

        self::assertSame(200, $this->avisar()->estado());
        self::assertSame('pendiente_pago', $this->reserva()['estado']);

        // El espectador vuelve a intentar con otra tarjeta, y esta vez sale.
        self::pagoEnMercadoPago(self::OTRO_PAGO, $reserva['id'], $reserva['total']);
        self::assertSame(200, $this->avisar(self::OTRO_PAGO)->estado());
        // Y llega tarde otro aviso del primero, todavía rechazado.
        self::assertSame(200, $this->avisar()->estado());

        $confirmada = $this->reserva();
        self::assertSame('confirmada', $confirmada['estado']);
        self::assertSame(self::OTRO_PAGO, $confirmada['pago_id']);
        self::assertSame([], self::reembolsosPedidos());
    }

    public function testSinFirmaNoCambiaNadaYNiSiquieraLePreguntaAMercadoPago(): void
    {
        $reserva = $this->pendiente();
        self::pagoEnMercadoPago(self::PAGO, $reserva['id'], $reserva['total']);

        $respuesta = $this->avisar(cabeceras: ['Content-Type' => 'application/json']);

        self::assertSame(401, $respuesta->estado());
        self::assertSame('pendiente_pago', $this->reserva()['estado']);
        self::assertSame([], self::pedidosAMercadoPago());
        self::assertStringContainsString('sin una firma válida', $this->leerLog());
    }

    public function testConLaFirmaDeOtraClaveNoCambiaNada(): void
    {
        $reserva = $this->pendiente();
        self::pagoEnMercadoPago(self::PAGO, $reserva['id'], $reserva['total']);

        self::assertSame(401, $this->avisar(cabeceras: self::cabecerasFirmadas(self::PAGO, 'otra-clave'))->estado());
        self::assertSame('pendiente_pago', $this->reserva()['estado']);
    }

    /**
     * La firma de un aviso verdadero no sirve para hacer pasar otro pago.
     */
    public function testLaFirmaDeOtroPagoNoSirve(): void
    {
        $reserva = $this->pendiente();
        self::pagoEnMercadoPago(self::OTRO_PAGO, $reserva['id'], $reserva['total']);

        self::assertSame(401, $this->avisar(self::OTRO_PAGO, self::cabecerasFirmadas(self::PAGO))->estado());
        self::assertSame('pendiente_pago', $this->reserva()['estado']);
    }

    /**
     * Alguien paga una preferencia propia de $1 con la reserva como referencia: no la confirma, y
     * se le devuelve lo que pagó.
     */
    public function testUnPagoQueNoEsPorElTotalNoConfirmaYSeDevuelve(): void
    {
        $reserva = $this->pendiente();
        self::pagoEnMercadoPago(self::PAGO, $reserva['id'], '1.00');

        self::assertSame(200, $this->avisar()->estado());

        self::assertSame('pendiente_pago', $this->reserva()['estado']);
        self::assertSame([self::PAGO], self::reembolsosPedidos());
    }

    public function testUnPagoEnOtraMonedaNoConfirma(): void
    {
        $reserva = $this->pendiente();
        self::pagoEnMercadoPago(self::PAGO, $reserva['id'], $reserva['total'], ['currency_id' => 'USD']);

        $this->avisar();

        self::assertSame('pendiente_pago', $this->reserva()['estado']);
    }

    /**
     * Un pago de la cuenta que no es de ninguna reserva: no es nuestro, no se toca.
     */
    public function testUnPagoQueNoEsDeNingunaReservaNoHaceNada(): void
    {
        $reserva = $this->pendiente();
        self::pagoEnMercadoPago(self::PAGO, Uuid::nuevo(), $reserva['total']);

        self::assertSame(200, $this->avisar()->estado());

        self::assertSame('pendiente_pago', $this->reserva()['estado']);
        self::assertSame([], self::reembolsosPedidos());
    }

    /**
     * El espectador canceló mientras pagaba: el cobro sobra y se devuelve.
     */
    public function testUnPagoDeUnaReservaCanceladaSeDevuelve(): void
    {
        $reserva = $this->pendiente();
        $this->pedir('POST', "/reservas/{$reserva['id']}/cancelar", cuerpo: ['_csrf' => self::CSRF], usuario: self::EVA, cookies: ['csrf' => self::CSRF]);
        self::pagoEnMercadoPago(self::PAGO, $reserva['id'], $reserva['total']);

        self::assertSame(200, $this->avisar()->estado());

        self::assertSame('cancelada', $this->reserva()['estado']);
        self::assertSame([self::PAGO], self::reembolsosPedidos());
        self::assertSame([], self::$correo->correos());
    }

    /**
     * Pagó dos veces la misma reserva (dos pestañas): la confirma el primero y el segundo se
     * devuelve.
     */
    public function testUnSegundoPagoDeLaMismaReservaSeDevuelve(): void
    {
        $reserva = $this->pendiente();
        self::pagoEnMercadoPago(self::PAGO, $reserva['id'], $reserva['total']);
        self::pagoEnMercadoPago(self::OTRO_PAGO, $reserva['id'], $reserva['total']);

        $this->avisar();
        $this->avisar(self::OTRO_PAGO);

        self::assertSame(self::PAGO, $this->reserva()['pago_id']);
        self::assertSame([self::OTRO_PAGO], self::reembolsosPedidos());
    }

    /**
     * Mercado Pago avisa también de otras cosas (las órdenes): se contesta 200 para que no las
     * reintente, sin preguntar nada.
     */
    public function testUnAvisoQueNoEsDeUnPagoSeContestaSinHacerNada(): void
    {
        $this->pendiente();

        $respuesta = $this->avisar(tipo: 'merchant_order');

        self::assertSame(200, $respuesta->estado());
        self::assertSame([], self::pedidosAMercadoPago());
    }

    public function testUnAvisoSinElIdDelPagoEs400(): void
    {
        $respuesta = $this->pedir('POST', '/pagos/aviso', self::cabecerasFirmadas(''), ['type' => 'payment'], ['type' => 'payment']);

        self::assertSame(400, $respuesta->estado());
    }

    /**
     * Si Mercado Pago no responde, el aviso falla para que lo vuelva a mandar.
     */
    public function testSiMercadoPagoNoRespondeElAvisoFallaYLaReservaSigueEsperando(): void
    {
        $this->pendiente();
        self::mercadoPagoCaido();

        self::assertSame(503, $this->avisar()->estado());
        self::assertSame('pendiente_pago', $this->reserva()['estado']);
    }

    public function testConElCobroApagadoLaRutaNoExiste(): void
    {
        $this->configurar(['activo' => false]);

        self::assertSame(404, $this->avisar()->estado());
    }
}
