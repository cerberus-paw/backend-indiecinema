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
use PHPUnit\Framework\TestCase;

/**
 * La devolución del pago al cancelar una reserva pagada (EF-06.4), de punta a punta: las tres
 * cancelaciones por sus rutas —el espectador desde su reserva, el administrador desde su panel y
 * programación cuando se cancela la función—, la base de verdad y el Mercado Pago de mentira, que
 * anota los reembolsos que le piden.
 *
 * Lo que se prueba es que se devuelva una sola vez, que lo que no se cobró no pida ningún
 * reembolso, y que si Mercado Pago no responde la cancelación quede igual y el reembolso quede
 * pendiente a la vista del administrador.
 */
final class ReembolsoAlCancelarTest extends TestCase
{
    use ConBaseDePruebas;
    use ConCuentasDePrueba;
    use ConMercadoPagoDePrueba;
    use ConProgramacionDePrueba {
        setUpBeforeClass as iniciarProgramacion;
        tearDownAfterClass as detenerProgramacion;
    }

    /** La cabecera con la que el panel pide la respuesta en JSON, como la manda fetch. */
    private const ACEPTA_JSON = ['Accept' => 'application/json'];

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ELIAS = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const ADMINISTRADORA = '88888888-8888-4888-8888-888888888888';
    private const ORGANIZADORA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    private const PROXIMA = '12121212-1212-4212-8212-121212121212';
    private const A_LA_GORRA = '78787878-7878-4878-8878-787878787878';
    private const PAGO = '1324567890';
    private const TOKEN_DE_PROGRAMACION = 'token-de-programacion';
    private const TOKEN_DE_RECAUDACION = 'token-de-recaudacion';

    private static ServidorSmtpDePrueba $correo;

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    /** @var resource lo que escribió el log en el último pedido */
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
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['programacion' => self::$url, 'cuentas' => self::$urlCuentas],
            'interno' => ['token' => 'token-de-funciones'],
            'llamadores' => [
                'programacion' => hash('sha256', self::TOKEN_DE_PROGRAMACION),
                'recaudacion' => hash('sha256', self::TOKEN_DE_RECAUDACION),
            ],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test', 'nombre' => 'IndieCinema'],
            'sitio' => ['url' => 'https://indiecinema.test'],
            'mercadopago' => self::mercadoPagoPrendido(),
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        self::$correo->vaciar();
        self::vaciarMercadoPago();
    }

    /**
     * @param ?string               $usuario   null: sin sesión, como llega el aviso de Mercado Pago
     * @param array<string, mixed>  $cuerpo    en un POST con sesión se le suma el token CSRF
     * @param array<string, string> $cabeceras
     * @param array<string, mixed>  $consulta
     */
    private function pedir(string $metodo, string $ruta, ?string $usuario = self::EVA, string $rol = 'espectador', array $cuerpo = [], array $cabeceras = [], array $consulta = []): Respuesta
    {
        // nginx siempre manda su secreto; sin sesión, sin usuario ni rol.
        $cabeceras['X-Nginx-Secreto'] = self::SECRETO;
        if ($usuario !== null) {
            $cabeceras += ['X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        }
        $cuerpo = $metodo === 'POST' && $usuario !== null ? ['_csrf' => self::CSRF, ...$cuerpo] : $cuerpo;
        $this->log = fopen('php://memory', 'w+');
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', $this->log));
        $respuesta = $aplicacion->atender(new Peticion($metodo, $ruta, $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF], []));
        // Lo que sale después de responder, como los correos.
        $respuesta->terminar();

        return $respuesta;
    }

    /**
     * El aviso del pago, como lo manda Mercado Pago: sin sesión y firmado.
     */
    private function avisar(string $pagoId = self::PAGO): Respuesta
    {
        return $this->pedir(
            'POST',
            '/pagos/aviso',
            null,
            cuerpo: ['action' => 'payment.updated', 'api_version' => 'v1', 'type' => 'payment', 'data' => ['id' => $pagoId]],
            cabeceras: self::cabecerasFirmadas($pagoId),
            consulta: ['data_id' => $pagoId, 'type' => 'payment'],
        );
    }

    /**
     * Programación avisa que se canceló la función, con su token de llamador.
     */
    private function cancelarLaFuncion(string $funcion = self::PROXIMA): Respuesta
    {
        return $this->interna('POST', "/interno/funciones/{$funcion}/cancelar-reservas", 'programacion', self::TOKEN_DE_PROGRAMACION, [
            'organizador_id' => self::ORGANIZADORA,
            'titulo' => 'Zama',
            'fecha' => null,
        ]);
    }

    /**
     * Un pedido de la API interna, con el token del subsistema que llama.
     *
     * @param array<string, mixed> $cuerpo
     */
    private function interna(string $metodo, string $ruta, string $llamador, string $token, array $cuerpo = []): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));
        $respuesta = $aplicacion->atender(new Peticion($metodo, $ruta, [], $cuerpo, [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ], [], []));
        $respuesta->terminar();

        return $respuesta;
    }

    /**
     * Lo cobrado que ve recaudación de la función (EF-02.5).
     *
     * @return array<string, mixed>
     */
    private function cobros(string $funcion = self::PROXIMA): array
    {
        $respuesta = $this->interna('GET', "/interno/funciones/{$funcion}/cobros", 'recaudacion', self::TOKEN_DE_RECAUDACION);
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());

        return json_decode($respuesta->cuerpo(), true);
    }

    /**
     * Una reserva de la función paga ya pagada y confirmada: la hace el espectador por la página y
     * la confirma el aviso del pago, como en producción. Deja el Mercado Pago de prueba sin nada
     * anotado, así lo que venga después son los reembolsos.
     *
     * @return array<string, mixed>
     */
    private function pagada(string $usuario = self::EVA, string $cantidad = '2', string $pago = self::PAGO): array
    {
        $respuesta = $this->pedir('POST', '/reservar/' . self::PROXIMA, $usuario, cuerpo: ['cantidad' => $cantidad]);
        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        $reserva = $this->ultima($usuario);
        self::pagoEnMercadoPago($pago, $reserva['id'], $reserva['total']);
        self::assertSame(200, $this->avisar($pago)->estado());
        $pagada = $this->reserva($reserva['id']);
        self::assertSame('confirmada', $pagada['estado']);
        self::assertSame($pago, $pagada['pago_id']);
        self::vaciarMercadoPago();

        return $pagada;
    }

    /**
     * La última reserva de ese espectador. Filtra por usuario porque creada_en guarda segundos: dos
     * reservas hechas en el mismo segundo empatan, y el desempate por id —un UUID al azar— devuelve
     * cualquiera de las dos. Sin el filtro, la prueba que paga una y deja otra pendiente agarraba a
     * veces la que no era.
     *
     * @return array<string, mixed>
     */
    private function ultima(string $usuario = self::EVA): array
    {
        return $this->base->fila(
            'SELECT * FROM reserva WHERE usuario_id = ? ORDER BY creada_en DESC, id LIMIT 1',
            [$usuario],
        ) ?? self::fail("No quedó ninguna reserva de {$usuario}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function reserva(string $id): array
    {
        return $this->base->fila('SELECT * FROM reserva WHERE id = ?', [$id]) ?? self::fail("No está la reserva {$id}.");
    }

    /**
     * Las claves de idempotencia de los reembolsos que se pidieron, en orden.
     *
     * @return list<?string>
     */
    private static function clavesDeLosReembolsos(): array
    {
        $claves = [];
        foreach (self::pedidosAMercadoPago() as $pedido) {
            if (str_ends_with($pedido['ruta'], '/refunds')) {
                $claves[] = $pedido['idempotencia'];
            }
        }

        return $claves;
    }

    private function leerLog(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    public function testElEspectadorQueCancelaUnaReservaPagadaRecuperaLaPlata(): void
    {
        $reserva = $this->pagada();

        $respuesta = $this->pedir('POST', "/reservas/{$reserva['id']}/cancelar", self::EVA);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        $cancelada = $this->reserva($reserva['id']);
        self::assertSame('cancelada', $cancelada['estado']);
        self::assertNotNull($cancelada['reembolso_id']);
        self::assertNotNull($cancelada['reembolsada_en']);
        self::assertSame([self::PAGO], self::reembolsosPedidos());
        // Con el pago como clave: si el pedido se repitiera, Mercado Pago no devuelve dos veces.
        self::assertSame(['reembolso-' . self::PAGO], self::clavesDeLosReembolsos());
    }

    /**
     * El reembolso no cambia el estado, así que no deja una línea en el historial: cambio_reserva
     * anota la cancelación, y el reembolso queda en la reserva y en el log (EF-06.4).
     */
    public function testElReembolsoQuedaEnLaReservaYNoEnElHistorial(): void
    {
        $reserva = $this->pagada();

        $this->pedir('POST', "/reservas/{$reserva['id']}/cancelar", self::EVA);

        self::assertSame([
            ['estado_anterior' => 'pendiente_pago', 'estado_nuevo' => 'confirmada'],
            ['estado_anterior' => 'confirmada', 'estado_nuevo' => 'cancelada'],
        ], $this->base->filas('SELECT estado_anterior, estado_nuevo FROM cambio_reserva WHERE reserva_id = ? ORDER BY id', [$reserva['id']]));
        self::assertStringContainsString('Se devolvió el pago', $this->leerLog());
    }

    public function testElAdministradorQueAnulaUnaPagadaLaDevuelveYLaFilaLoDice(): void
    {
        $reserva = $this->pagada();

        $respuesta = $this->pedir('POST', "/administracion/reservas/{$reserva['id']}/anular", self::ADMINISTRADORA, 'administrador', cabeceras: self::ACEPTA_JSON);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $fila = json_decode($respuesta->cuerpo(), true)['reserva'];
        self::assertSame(['cancelada', 'hecho', false], [$fila['estado'], $fila['reembolso'], $fila['anulable']]);
        self::assertNotNull($this->reserva($reserva['id'])['reembolso_id']);
        self::assertSame([self::PAGO], self::reembolsosPedidos());
    }

    /**
     * Se cancela la función: se devuelve lo que se había pagado y nada más. La que todavía esperaba
     * el pago se cancela igual, pero no tiene nada que devolver.
     */
    public function testAlCancelarseLaFuncionSeDevuelveSoloLoCobrado(): void
    {
        $pagada = $this->pagada();
        $sinPagar = $this->pedir('POST', '/reservar/' . self::PROXIMA, self::ELIAS, cuerpo: ['cantidad' => '1']);
        self::assertSame(303, $sinPagar->estado(), $sinPagar->cuerpo());
        $pendiente = $this->ultima(self::ELIAS);
        self::assertSame('pendiente_pago', $pendiente['estado']);
        self::vaciarMercadoPago();

        $respuesta = $this->cancelarLaFuncion();

        self::assertSame(['canceladas' => 2], json_decode($respuesta->cuerpo(), true));
        self::assertSame('cancelada', $this->reserva($pendiente['id'])['estado']);
        self::assertNull($this->reserva($pendiente['id'])['reembolso_id']);
        self::assertNotNull($this->reserva($pagada['id'])['reembolso_id']);
        self::assertSame([self::PAGO], self::reembolsosPedidos());
    }

    /**
     * Programación repite el aviso si no recibió la respuesta: la segunda vez no hay ninguna que
     * cancelar y, sobre todo, no se devuelve la plata de nuevo.
     */
    public function testCancelarLaFuncionDosVecesNoDevuelveDosVeces(): void
    {
        $reserva = $this->pagada();

        $this->cancelarLaFuncion();
        $segunda = $this->cancelarLaFuncion();

        self::assertSame(['canceladas' => 0], json_decode($segunda->cuerpo(), true));
        self::assertSame([self::PAGO], self::reembolsosPedidos());
        self::assertNotNull($this->reserva($reserva['id'])['reembolso_id']);
    }

    /**
     * Si Mercado Pago no responde, la cancelación queda igual: el espectador no se come un error
     * por algo que se arregla del otro lado, y el reembolso queda pendiente.
     */
    public function testSiMercadoPagoNoRespondeLaReservaQuedaCanceladaYElReembolsoPendiente(): void
    {
        $reserva = $this->pagada();
        self::mercadoPagoCaido();

        $respuesta = $this->pedir('POST', "/reservas/{$reserva['id']}/cancelar", self::EVA);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        $cancelada = $this->reserva($reserva['id']);
        self::assertSame('cancelada', $cancelada['estado']);
        self::assertNull($cancelada['reembolso_id']);
        self::assertNull($cancelada['reembolsada_en']);
        self::assertStringContainsString('No se pudo devolver el pago', $this->leerLog());
    }

    /**
     * Y el administrador lo ve en su panel, para reintentarlo.
     */
    public function testElPanelDelAdministradorMuestraElReembolsoPendiente(): void
    {
        $reserva = $this->pagada();
        self::mercadoPagoCaido();
        $this->pedir('POST', "/reservas/{$reserva['id']}/cancelar", self::EVA);

        $pagina = $this->pedir('GET', '/administracion/reservas', self::ADMINISTRADORA, 'administrador');

        self::assertSame(200, $pagina->estado(), $pagina->cuerpo());
        self::assertStringContainsString('Reembolso pendiente', $pagina->cuerpo());
    }

    /**
     * Una función a la gorra se confirma sin cobrar: cancelarla no le pide ningún reembolso a
     * Mercado Pago, que no tendría qué devolver.
     */
    public function testCancelarUnaReservaSinPagoNoLePideNadaAMercadoPago(): void
    {
        $respuesta = $this->pedir('POST', '/reservar/' . self::A_LA_GORRA, self::EVA, cuerpo: ['cantidad' => '2']);
        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        $reserva = $this->ultima();
        self::assertSame('confirmada', $reserva['estado']);
        self::assertNull($reserva['pago_id']);
        self::vaciarMercadoPago();

        $this->pedir('POST', "/reservas/{$reserva['id']}/cancelar", self::EVA);

        self::assertSame('cancelada', $this->reserva($reserva['id'])['estado']);
        self::assertSame([], self::pedidosAMercadoPago());
    }

    /**
     * Lo que recaudación ve de una reembolsada (EF-02.5): sigue en la lista, porque su plata entró,
     * con reembolsada en true y descontada del neto.
     */
    public function testRecaudacionVeLaReembolsadaYElNetoNoLaCuenta(): void
    {
        $reserva = $this->pagada();
        self::assertSame('7000.00', $this->cobros()['neto']);

        $this->pedir('POST', "/reservas/{$reserva['id']}/cancelar", self::EVA);

        $cobros = $this->cobros();
        self::assertSame([$reserva['id']], array_column($cobros['reservas'], 'id'));
        self::assertSame(['cancelada', '7000.00', true], [$cobros['reservas'][0]['estado'], $cobros['reservas'][0]['cobrado'], $cobros['reservas'][0]['reembolsada']]);
        self::assertSame('0.00', $cobros['neto']);
    }
}
