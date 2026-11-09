<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\Formularios;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * La reserva de punta a punta (E4-01.3): las rutas de funciones con la sesión que manda nginx, el
 * token CSRF, la base de verdad y una programación de mentira que da 40 lugares a 3500. El correo
 * de la reserva confirmada (E4-04.3) llega a un servidor de correo de prueba, con la dirección que
 * da un cuentas de mentira (<id>@ejemplo.test, y nombre Eva).
 */
final class ReservasTest extends TestCase
{
    use ConBaseDePruebas;
    use ConCuentasDePrueba;
    use ConProgramacionDePrueba {
        setUpBeforeClass as iniciarProgramacion;
        tearDownAfterClass as detenerProgramacion;
    }

    private const SECRETO = 'secreto-de-nginx';
    /** El Accept que manda un fetch cuando se le pide JSON. */
    private const ACEPTA_JSON = 'application/json, text/plain, */*';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ELIAS = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const PROXIMA = '12121212-1212-4212-8212-121212121212';
    private const EMPEZADA = '34343434-3434-4434-8434-343434343434';
    private const ROTA = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private const SITIO = 'https://indiecinema.test';

    private static ServidorSmtpDePrueba $correo;

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    /** @var resource lo que escribe el log en la última petición */
    private $log;

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
        $this->configurar();
        $this->base = self::baseConTablasNuevas($this->configuracion);
        self::$correo->vaciar();
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
            ...$cambios,
        ]);
    }

    /**
     * @param array<string, string> $cuerpo
     * @param ?string               $acepta el Accept del pedido; null, el de un navegador
     */
    private function pedir(string $metodo, string $ruta, ?string $usuario = self::EVA, array $cuerpo = [], ?string $acepta = null): Respuesta
    {
        $cabeceras = $usuario === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => 'espectador'];
        if ($acepta !== null) {
            $cabeceras['Accept'] = $acepta;
        }
        $this->log = fopen('php://memory', 'w+');
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', $this->log));

        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        return PaginasParaValidar::anotar($metodo, $ruta, $aplicacion->atender(new Peticion($metodo, (string) parse_url($ruta, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF], [])));
    }

    private function reservar(string $cantidad, string $funcion = self::PROXIMA, ?string $usuario = self::EVA): Respuesta
    {
        return $this->pedir('POST', "/reservar/{$funcion}", $usuario, ['_csrf' => self::CSRF, 'cantidad' => $cantidad]);
    }

    /**
     * Una reserva hecha por la página, como la haría el espectador.
     */
    private function reservada(string $cantidad = '1', ?string $usuario = self::EVA): string
    {
        self::assertSame(303, $this->reservar($cantidad, usuario: $usuario)->estado());

        return (string) $this->base->valor('SELECT id FROM reserva WHERE usuario_id = ? ORDER BY creada_en DESC, id LIMIT 1', [$usuario]);
    }

    private function cancelar(string $id, ?string $usuario = self::EVA, string $csrf = self::CSRF): Respuesta
    {
        return $this->pedir('POST', "/reservas/{$id}/cancelar", $usuario, ['_csrf' => $csrf]);
    }

    private function estado(string $id): string
    {
        return (string) $this->base->valor('SELECT estado FROM reserva WHERE id = ?', [$id]);
    }

    /**
     * Reservar como lo hace el componente de la ficha: el mismo POST, pidiendo JSON. El Accept es
     * el que manda un fetch cuando se le pone la cabecera.
     */
    private function reservarEnJson(string $cantidad, string $funcion = self::PROXIMA, ?string $usuario = self::EVA): Respuesta
    {
        return $this->pedir('POST', "/reservar/{$funcion}", $usuario, ['_csrf' => self::CSRF, 'cantidad' => $cantidad], self::ACEPTA_JSON);
    }

    private function cancelarEnJson(string $id, ?string $usuario = self::EVA): Respuesta
    {
        return $this->pedir('POST', "/reservas/{$id}/cancelar", $usuario, ['_csrf' => self::CSRF], self::ACEPTA_JSON);
    }

    /**
     * El JSON de una respuesta.
     *
     * @return array<string, mixed>
     */
    private static function json(Respuesta $respuesta): array
    {
        return (array) json_decode($respuesta->cuerpo(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Ocupa lugares de una función sin pasar por la página, como si los hubieran reservado otros.
     */
    private function ocupar(string $funcion, int $cantidad, string $usuario = self::ELIAS): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $funcion, $usuario, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), $cantidad, '0.00', '2026-10-19 10:00:00'],
        );

        return $id;
    }

    private function reservas(): int
    {
        return (int) $this->base->valor('SELECT COUNT(*) FROM reserva');
    }

    public function testSinSesionNoSeReserva(): void
    {
        self::assertSame(401, $this->pedir('GET', '/reservar/' . self::PROXIMA, null)->estado());
        self::assertSame(401, $this->reservar('1', usuario: null)->estado());
        self::assertSame(0, $this->reservas());
    }

    public function testLaPaginaDeReservaTieneLaFuncionYElFormulario(): void
    {
        $respuesta = $this->pedir('GET', '/reservar/' . self::PROXIMA);

        self::assertSame(200, $respuesta->estado());
        $cuerpo = $respuesta->cuerpo();
        PaginasParaValidar::guardar('reserva', $cuerpo);
        self::assertStringContainsString('Reservá tus lugares', $cuerpo);
        self::assertStringContainsString('Cine Club El Galpón · Luján', $cuerpo);
        self::assertStringContainsString('$ 3.500 por lugar', $cuerpo);
        self::assertStringContainsString('href="/cartelera/' . self::PROXIMA . '"', $cuerpo);
        self::assertStringContainsString('action="/funcion/reservar/' . self::PROXIMA . '" method="POST"', $cuerpo);
        self::assertStringContainsString('value="' . self::CSRF . '"', $cuerpo);
        self::assertMatchesRegularExpression('/name="cantidad"[^>]*min="1"[^>]*max="40"/s', $cuerpo);
    }

    public function testReservarLlevaALaConfirmacionConElCodigo(): void
    {
        $respuesta = $this->reservar('2');

        self::assertSame(303, $respuesta->estado());
        $id = (string) $this->base->valor('SELECT id FROM reserva');
        self::assertSame("/funcion/reservas/{$id}", $respuesta->cabecera('Location'));
        self::assertSame(2, $this->base->valor('SELECT cantidad FROM reserva WHERE id = ?', [$id]));
        self::assertSame(self::EVA, $this->base->valor('SELECT usuario_id FROM reserva WHERE id = ?', [$id]));

        $confirmacion = $this->pedir('GET', "/reservas/{$id}");
        self::assertSame(200, $confirmacion->estado());
        $codigo = (string) $this->base->valor('SELECT codigo FROM reserva WHERE id = ?', [$id]);
        $cuerpo = $confirmacion->cuerpo();
        self::assertStringContainsString('Reserva confirmada', $cuerpo);
        self::assertStringContainsString(substr($codigo, 0, 4) . '-' . substr($codigo, 4), $cuerpo);
        self::assertStringContainsString('$ 7.000', $cuerpo);
        self::assertStringContainsString('Cine Club El Galpón · Luján', $cuerpo);
    }

    public function testLaReservaDeOtroEsUn404(): void
    {
        $this->reservar('1');
        $id = (string) $this->base->valor('SELECT id FROM reserva');

        self::assertSame(404, $this->pedir('GET', "/reservas/{$id}", self::ELIAS)->estado());
        self::assertSame(404, $this->pedir('GET', '/reservas/' . Uuid::nuevo())->estado());
        self::assertSame(404, $this->pedir('GET', '/reservas/no-es-un-id')->estado());
    }

    public function testSinElTokenCsrfNoSeReserva(): void
    {
        $respuesta = $this->pedir('POST', '/reservar/' . self::PROXIMA, self::EVA, ['_csrf' => 'otro', 'cantidad' => '1']);

        self::assertSame(403, $respuesta->estado());
        self::assertSame(0, $this->reservas());
    }

    /**
     * Sin token, el 403 del formulario de la ficha (E4-05.3) va en JSON con el mensaje para el
     * espectador: el formulario lo muestra debajo del campo, y le dice que recargue en lugar de un
     * «probá de nuevo» que volvería a fallar. Que se rechace y que el token pueda ir en la cabecera
     * ya lo prueba el núcleo (AplicacionTest).
     */
    public function testEnJsonSinTokenElErrorVaEnJsonYNoSeReserva(): void
    {
        $respuesta = $this->pedir('POST', '/reservar/' . self::PROXIMA, self::EVA, ['cantidad' => '1'], self::ACEPTA_JSON);

        self::assertSame(403, $respuesta->estado());
        self::assertSame(['error' => 'La página venció. Volvé a cargarla y probá de nuevo.'], self::json($respuesta));
        self::assertSame(0, $this->reservas());
    }

    public function testUnaCantidadInvalidaSeRechazaEnElServidor(): void
    {
        foreach (['', 'dos', '0', '-1', '1.5', '41', "1' OR 1=1"] as $cantidad) {
            $respuesta = $this->reservar($cantidad);

            self::assertSame(422, $respuesta->estado(), "Aceptó «{$cantidad}».");
            self::assertStringContainsString('Ingresá entre 1 y 40 lugares.', $respuesta->cuerpo());
            // Arriba, el resumen con el link a la cantidad (E4-10.5).
            self::assertSame(['#cantidad' => 'Ingresá entre 1 y 40 lugares.'], Formularios::resumen($respuesta->cuerpo())['enlaces'] ?? null);
        }
        self::assertSame(0, $this->reservas());
    }

    public function testSinLugaresSuficientesLoDiceYNoReserva(): void
    {
        $this->base->ejecutar(
            "INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, creada_en) VALUES (?, ?, ?, 'ZZZZZZZZ', 39, '0.00', '2026-10-19 10:00:00')",
            [Uuid::nuevo(), self::PROXIMA, self::ELIAS],
        );

        $respuesta = $this->reservar('2');

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Queda 1 lugar.', $respuesta->cuerpo());
        // No es de un campo: va en el resumen sin link, y el resumen recibe el foco (E4-10.5).
        $resumen = Formularios::resumen($respuesta->cuerpo());
        self::assertNotNull($resumen);
        self::assertSame(['Queda 1 lugar.'], $resumen['textos']);
        self::assertSame([], $resumen['enlaces']);
        self::assertTrue($resumen['anunciado']);
        // Vuelve con lo que había puesto.
        self::assertMatchesRegularExpression('/name="cantidad"[^>]*value="2"/s', $respuesta->cuerpo());
        self::assertSame(1, $this->reservas());
    }

    public function testUnaFuncionQueYaEmpezoNoMuestraElFormulario(): void
    {
        $pagina = $this->pedir('GET', '/reservar/' . self::EMPEZADA);
        self::assertSame(200, $pagina->estado());
        self::assertStringContainsString('La función ya empezó: ya no se puede reservar.', $pagina->cuerpo());
        self::assertStringNotContainsString('name="cantidad"', $pagina->cuerpo());

        $respuesta = $this->reservar('1', self::EMPEZADA);
        self::assertSame(422, $respuesta->estado());
        self::assertSame(0, $this->reservas());
    }

    public function testUnaFuncionQueNoExisteEsUn404(): void
    {
        self::assertSame(404, $this->pedir('GET', '/reservar/' . Uuid::nuevo())->estado());
        self::assertSame(404, $this->reservar('1', Uuid::nuevo())->estado());
    }

    public function testElComprobanteOfreceCancelarYLaPantallaLoConfirma(): void
    {
        $id = $this->reservada('2');

        self::assertStringContainsString('href="/funcion/reservas/' . $id . '/cancelar"', $this->pedir('GET', "/reservas/{$id}")->cuerpo());
        $pantalla = $this->pedir('GET', "/reservas/{$id}/cancelar");
        self::assertSame(200, $pantalla->estado());
        self::assertStringContainsString('¿Querés cancelar esta reserva?', $pantalla->cuerpo());
        self::assertStringContainsString('action="/funcion/reservas/' . $id . '/cancelar" method="POST"', $pantalla->cuerpo());
        // Mirarla no la cancela.
        self::assertSame('confirmada', $this->estado($id));
    }

    public function testCancelarLaDejaCanceladaYLoAvisa(): void
    {
        $id = $this->reservada('2');

        $respuesta = $this->cancelar($id);

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/funcion/reservas/{$id}?cancelada=1", $respuesta->cabecera('Location'));
        self::assertSame('cancelada', $this->estado($id));
        $comprobante = $this->pedir('GET', "/reservas/{$id}?cancelada=1")->cuerpo();
        self::assertStringContainsString('Cancelaste la reserva', $comprobante);
        self::assertStringContainsString('Reserva cancelada', $comprobante);
        self::assertStringNotContainsString('/cancelar"', $comprobante);
    }

    /**
     * El criterio de la subtarea: con la función completa, cancelar deja el lugar para otro.
     */
    public function testElLugarCanceladoVuelveAQuedarLibre(): void
    {
        $id = $this->reservada('1');
        $this->ocupar(self::PROXIMA, 39);
        self::assertStringContainsString('La función está agotada.', $this->reservar('1', usuario: self::ELIAS)->cuerpo());

        $this->cancelar($id);

        self::assertSame(303, $this->reservar('1', usuario: self::ELIAS)->estado());
    }

    public function testLaReservaDeOtroNoSeCancela(): void
    {
        $id = $this->reservada();

        self::assertSame(404, $this->pedir('GET', "/reservas/{$id}/cancelar", self::ELIAS)->estado());
        self::assertSame(404, $this->cancelar($id, self::ELIAS)->estado());
        self::assertSame('confirmada', $this->estado($id));
    }

    public function testSinElTokenCsrfNoSeCancela(): void
    {
        $id = $this->reservada();

        self::assertSame(403, $this->cancelar($id, csrf: 'otro')->estado());
        self::assertSame('confirmada', $this->estado($id));
    }

    public function testUnaCanceladaOConAsistenciaNoSeVuelveACancelar(): void
    {
        $cancelada = $this->reservada();
        $this->cancelar($cancelada);
        $conAsistencia = $this->reservada();
        $this->base->ejecutar("UPDATE reserva SET estado = 'asistio' WHERE id = ?", [$conAsistencia]);

        foreach ([$cancelada => 'La reserva ya está cancelada.', $conAsistencia => 'Ya registraron tu asistencia: la reserva no se puede cancelar.'] as $id => $mensaje) {
            self::assertSame(404, $this->pedir('GET', "/reservas/{$id}/cancelar")->estado());
            $respuesta = $this->cancelar($id);
            self::assertSame(422, $respuesta->estado());
            self::assertStringContainsString($mensaje, $respuesta->cuerpo());
            self::assertStringNotContainsString('action="/funcion/reservas/' . $id . '/cancelar"', $respuesta->cuerpo());
        }
        self::assertSame('asistio', $this->estado($conAsistencia));
    }

    public function testCuandoLaFuncionEmpezoYaNoSeCancela(): void
    {
        $id = $this->ocupar(self::EMPEZADA, 2, self::EVA);

        self::assertStringNotContainsString('/cancelar"', $this->pedir('GET', "/reservas/{$id}")->cuerpo());
        self::assertSame(404, $this->pedir('GET', "/reservas/{$id}/cancelar")->estado());
        $respuesta = $this->cancelar($id);
        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('La función ya empezó: la reserva ya no se puede cancelar.', $respuesta->cuerpo());
        self::assertSame('confirmada', $this->estado($id));
    }

    // ---- El correo de la reserva confirmada (E4-04.3) ---------------------------------------

    /**
     * Los correos que llegaron, con el asunto y el texto ya decodificados.
     *
     * @return list<array{de: string, para: list<string>, asunto: string, texto: string}>
     */
    private function correos(): array
    {
        return array_map(static function (array $correo): array {
            [$cabeceras, $cuerpo] = explode("\r\n\r\n", $correo['mensaje'], 2);
            preg_match('/^Subject: (.+(?:\r\n .+)*)/m', $cabeceras, $asunto);

            return [
                'de' => $correo['de'],
                'para' => $correo['para'],
                'asunto' => mb_decode_mimeheader(str_replace("\r\n", '', $asunto[1] ?? '')),
                'texto' => str_replace("\r\n", "\n", (string) base64_decode(str_replace("\r\n", '', $cuerpo), true)),
            ];
        }, self::$correo->correos());
    }

    private function logEscrito(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    public function testAlReservarLlegaElCorreoConElCodigoLaFuncionYElEnlace(): void
    {
        $id = $this->reservada('2');
        $codigo = (string) $this->base->valor('SELECT codigo FROM reserva WHERE id = ?', [$id]);

        $correos = $this->correos();
        self::assertCount(1, $correos);
        self::assertSame(['<' . self::EVA . '@ejemplo.test>'], $correos[0]['para']);
        self::assertSame('<no-responder@indiecinema.test>', $correos[0]['de']);
        self::assertSame('Reserva confirmada: «Zama»', $correos[0]['asunto']);
        $texto = $correos[0]['texto'];
        self::assertStringContainsString('Hola, Eva:', $texto);
        self::assertStringContainsString(substr($codigo, 0, 4) . '-' . substr($codigo, 4), $texto);
        self::assertStringContainsString('Zama', $texto);
        self::assertStringContainsString('Cine Club El Galpón · Luján', $texto);
        self::assertStringContainsString('2 lugares', $texto);
        self::assertStringContainsString('$ 7.000', $texto);
        self::assertStringContainsString(self::SITIO . "/funcion/reservas/{$id}", $texto);
    }

    /**
     * Cuentas ya no tiene al usuario (BORRADO en el cuentas de mentira): la reserva queda hecha y
     * el log lo cuenta sin la dirección ni el nombre.
     */
    public function testSinLaDireccionDelEspectadorLaReservaQuedaIgual(): void
    {
        $borrado = '00000000-0000-4000-8000-000000000000';

        $respuesta = $this->reservar('1', usuario: $borrado);

        self::assertSame(303, $respuesta->estado());
        $id = (string) $this->base->valor('SELECT id FROM reserva WHERE usuario_id = ?', [$borrado]);
        self::assertSame('confirmada', $this->estado($id));
        self::assertSame([], $this->correos());
        self::assertStringContainsString("No salió el correo de la reserva confirmada {$id}", $this->logEscrito());
        self::assertStringNotContainsString('@ejemplo.test', $this->logEscrito());
    }

    public function testSinServidorDeCorreoLaReservaQuedaIgual(): void
    {
        $this->configurar(['correo' => ['servidor' => 'smtp://127.0.0.1:1', 'remitente' => 'no-responder@indiecinema.test']]);

        $respuesta = $this->reservar('1');

        self::assertSame(303, $respuesta->estado());
        $id = (string) $this->base->valor('SELECT id FROM reserva');
        self::assertSame('confirmada', $this->estado($id));
        self::assertStringContainsString("No salió el correo de la reserva confirmada {$id}", $this->logEscrito());
        self::assertStringNotContainsString(self::EVA . '@ejemplo.test', $this->logEscrito());
    }

    public function testSinCuentasLaReservaQuedaIgual(): void
    {
        $this->configurar(['subsistemas' => ['programacion' => self::$url, 'cuentas' => 'http://127.0.0.1:1']]);

        self::assertSame(303, $this->reservar('1')->estado());
        self::assertSame(1, $this->reservas());
        self::assertSame([], $this->correos());
    }

    /**
     * Sin la URL del sitio el enlace al comprobante saldría roto: no es un aviso en el log sino un
     * error, para verlo enseguida. Y antes de reservar: si no, el espectador vería un error con la
     * reserva ya hecha, y reservaría de nuevo.
     */
    public function testSinUnaUrlDelSitioValidaEsUnErrorDeConfiguracionYNoReserva(): void
    {
        foreach ([[], ['url' => 'ftp://indiecinema.test'], ['url' => 'indiecinema.test']] as $sitio) {
            $this->configurar(['sitio' => $sitio]);

            self::assertSame(500, $this->reservar('1')->estado());
            self::assertStringContainsString('sitio.url', $this->logEscrito());
        }
        self::assertSame(0, $this->reservas());
        self::assertSame([], $this->correos());
    }

    public function testUnaUrlDelSitioConBarraFinalNoDuplicaLaBarra(): void
    {
        $this->configurar(['sitio' => ['url' => self::SITIO . '/']]);

        $id = $this->reservada();

        self::assertStringContainsString(self::SITIO . "/funcion/reservas/{$id}\n", $this->correos()[0]['texto']);
    }

    /**
     * F-01.2: mientras se votan los títulos la función no tiene fecha, y no se reserva, tampoco
     * con un POST directo: vuelve el motivo, no se guarda nada y no sale ningún correo.
     */
    public function testSinFechaNoSeReservaNiPorLaApi(): void
    {
        $respuesta = $this->reservar('1', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('La función todavía no tiene fecha confirmada', $respuesta->cuerpo());
        self::assertSame(0, (int) $this->base->valor("SELECT COUNT(*) FROM reserva WHERE funcion_id = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'"));
        self::assertSame([], $this->correos());
    }

    // ---- «Mis reservas» (E4-04.1) -----------------------------------------------------------

    /**
     * Una reserva hecha en otro momento, sin pasar por la página.
     *
     * @return string su código
     */
    private function hecha(string $funcion, string $creada, string $estado = 'confirmada', string $usuario = self::EVA, int $cantidad = 1): string
    {
        $codigo = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [Uuid::nuevo(), $funcion, $usuario, $codigo, $cantidad, '3500.00', $estado, $creada],
        );

        return $codigo;
    }

    private static function partido(string $codigo): string
    {
        return substr($codigo, 0, 4) . '-' . substr($codigo, 4);
    }

    public function testMisReservasListaTodasLasMiasDeLaMasNuevaALaMasVieja(): void
    {
        $vieja = $this->hecha(self::PROXIMA, '2026-10-01 10:00:00', 'asistio');
        $cancelada = $this->hecha(self::PROXIMA, '2026-10-05 10:00:00', 'cancelada');
        $nueva = $this->hecha('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '2026-10-10 10:00:00', cantidad: 3);

        $respuesta = $this->pedir('GET', '/reservas');

        self::assertSame(200, $respuesta->estado());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('Mis reservas', $cuerpo);
        $posiciones = array_map(static fn (string $codigo): int|false => strpos($cuerpo, self::partido($codigo)), [$nueva, $cancelada, $vieja]);
        self::assertNotContains(false, $posiciones);
        self::assertSame($posiciones, array_values(array_unique($posiciones)));
        self::assertTrue($posiciones[0] < $posiciones[1] && $posiciones[1] < $posiciones[2], 'De la más nueva a la más vieja.');
        // La función, la sala, la fecha o «A confirmar», los lugares y el estado.
        self::assertStringContainsString('La ciénaga + Zama', $cuerpo);
        self::assertStringContainsString('Cine Club El Galpón', $cuerpo);
        self::assertStringContainsString('A confirmar', $cuerpo);
        self::assertStringContainsString('con asistencia', $cuerpo);
        self::assertStringContainsString('cancelada', $cuerpo);
        self::assertMatchesRegularExpression('#<td[^>]*>\s*3\s*</td>#', $cuerpo);
        $id = (string) $this->base->valor('SELECT id FROM reserva WHERE codigo = ?', [$nueva]);
        self::assertStringContainsString("href=\"/funcion/reservas/{$id}\"", $cuerpo);
    }

    public function testMisReservasSinNingunaLlevaALaCartelera(): void
    {
        $respuesta = $this->pedir('GET', '/reservas');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('Todavía no reservaste lugares', $respuesta->cuerpo());
        self::assertStringContainsString('href="/cartelera"', $respuesta->cuerpo());
        self::assertStringNotContainsString('<table', $respuesta->cuerpo());
    }

    /**
     * La misma barra lateral que «Mi cuenta» (E4-10.13), con «Mis reservas» marcada.
     */
    public function testMisReservasTieneLaBarraDeMiCuentaConLaSeccionMarcada(): void
    {
        $respuesta = $this->pedir('GET', '/reservas');

        self::assertSame(200, $respuesta->estado());
        preg_match('#<nav[^>]*aria-label="Mi cuenta".*?</nav>#s', $respuesta->cuerpo(), $barra);
        self::assertMatchesRegularExpression('#<a href="/funcion/reservas"[^>]*aria-current="page"#', $barra[0] ?? '');
        self::assertStringContainsString('href="/cuenta/"', $barra[0] ?? '');
        self::assertDoesNotMatchRegularExpression('#<a href="/cuenta/"[^>]*aria-current#', $barra[0] ?? '');
    }

    public function testMisReservasPideSesion(): void
    {
        self::assertSame(401, $this->pedir('GET', '/reservas', null)->estado());
    }

    public function testMisReservasPaginaDeAVeinte(): void
    {
        $codigos = [];
        for ($i = 1; $i <= 21; ++$i) {
            $codigos[$i] = $this->hecha(self::PROXIMA, sprintf('2026-10-%02d 10:00:00', $i));
        }

        $primera = $this->pedir('GET', '/reservas')->cuerpo();
        $segunda = $this->pedir('GET', '/reservas?pagina=2');

        self::assertSame(20, substr_count($primera, 'data-reserva='));
        self::assertStringContainsString(self::partido($codigos[21]), $primera);
        self::assertStringNotContainsString(self::partido($codigos[1]), $primera);
        self::assertStringContainsString('href="?pagina=2"', $primera);
        self::assertSame(200, $segunda->estado());
        self::assertSame(1, substr_count($segunda->cuerpo(), 'data-reserva='));
        self::assertStringContainsString(self::partido($codigos[1]), $segunda->cuerpo());
        self::assertSame(404, $this->pedir('GET', '/reservas?pagina=3')->estado());
        self::assertSame(404, $this->pedir('GET', '/reservas?pagina=0')->estado());
        self::assertSame(404, $this->pedir('GET', '/reservas?pagina=dos')->estado());
    }

    /**
     * Si programación no responde, las reservas y sus códigos se ven igual, con un aviso.
     */
    public function testMisReservasSinProgramacionMuestraLosCodigosYUnAviso(): void
    {
        $codigo = $this->hecha(self::PROXIMA, '2026-10-01 10:00:00');
        $this->configurar(['subsistemas' => ['programacion' => 'http://127.0.0.1:1', 'cuentas' => self::$urlCuentas]]);

        $respuesta = $this->pedir('GET', '/reservas');

        self::assertSame(200, $respuesta->estado());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString(self::partido($codigo), $cuerpo);
        self::assertStringContainsString('no podemos mostrar los datos de las funciones', $cuerpo);
        self::assertStringNotContainsString('Zama', $cuerpo);
        self::assertStringNotContainsString('Ya no está en la cartelera', $cuerpo);
        // Sin saber si empezó, el botón para cancelar queda en el comprobante.
        self::assertStringNotContainsString('/cancelar"', $cuerpo);
    }

    /**
     * Si programación responde algo que no son funciones, la lista sale igual que si no
     * respondiera, y el log lo cuenta: el contrato se rompió.
     */
    public function testMisReservasConUnaRespuestaRotaDeProgramacionMuestraLosCodigosYUnAviso(): void
    {
        $codigo = $this->hecha(self::ROTA, '2026-10-01 10:00:00');

        $respuesta = $this->pedir('GET', '/reservas');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString(self::partido($codigo), $respuesta->cuerpo());
        self::assertStringContainsString('no podemos mostrar los datos de las funciones', $respuesta->cuerpo());
        self::assertStringContainsString('Sin las funciones de «Mis reservas»', $this->logEscrito());
    }

    public function testMisReservasConUnaFuncionQueYaNoEstaLoDice(): void
    {
        $codigo = $this->hecha('eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', '2026-10-01 10:00:00');

        $cuerpo = $this->pedir('GET', '/reservas')->cuerpo();

        self::assertStringContainsString(self::partido($codigo), $cuerpo);
        self::assertStringContainsString('Ya no está en la cartelera', $cuerpo);
    }

    /**
     * Cancelar aparece sólo donde todavía se puede: confirmada y antes de que empiece.
     */
    public function testMisReservasOfreceCancelarSoloLasQueSePueden(): void
    {
        $this->hecha(self::PROXIMA, '2026-10-03 10:00:00');
        $this->hecha(self::EMPEZADA, '2026-10-02 10:00:00');
        $this->hecha(self::PROXIMA, '2026-10-01 10:00:00', 'cancelada');

        $cuerpo = $this->pedir('GET', '/reservas')->cuerpo();

        self::assertSame(1, substr_count($cuerpo, '/cancelar"'));
        self::assertSame(3, substr_count($cuerpo, 'Ver comprobante'));
    }

    /**
     * Cada espectador ve sólo las suyas, aunque haya reservado la misma función que otro (E4-04.4).
     */
    public function testMisReservasNoMuestraLasDeOtro(): void
    {
        $deEva = $this->hecha(self::PROXIMA, '2026-10-01 10:00:00');
        $deElias = [];
        for ($i = 1; $i <= 20; ++$i) {
            $deElias[] = $this->hecha($i % 2 === 0 ? self::PROXIMA : self::EMPEZADA, sprintf('2026-10-%02d 12:00:00', $i), usuario: self::ELIAS);
        }

        $deEvaVista = $this->pedir('GET', '/reservas')->cuerpo();
        $deEliasVista = $this->pedir('GET', '/reservas', self::ELIAS)->cuerpo();

        self::assertSame(1, substr_count($deEvaVista, 'data-reserva='));
        self::assertStringContainsString(self::partido($deEva), $deEvaVista);
        foreach ($deElias as $codigo) {
            self::assertStringNotContainsString(self::partido($codigo), $deEvaVista);
            self::assertStringNotContainsString($codigo, $deEvaVista);
        }
        // El total del paginado cuenta sólo las suyas: con 21 en la tabla, Eva tendría dos páginas.
        self::assertStringNotContainsString('?pagina=2', $deEvaVista);
        self::assertStringContainsString('<p class="sr-only" role="status">1 reserva</p>', $deEvaVista);

        self::assertSame(20, substr_count($deEliasVista, 'data-reserva='));
        self::assertStringNotContainsString(self::partido($deEva), $deEliasVista);
    }

    public function testReservarEnJsonDevuelveLaReservaYLosLugaresQueQuedan(): void
    {
        $respuesta = $this->reservarEnJson('2');
        $json = self::json($respuesta);

        // 201: se creó la reserva. El link es el mismo al que redirige la página.
        self::assertSame(201, $respuesta->estado());
        $id = (string) $this->base->valor('SELECT id FROM reserva');
        self::assertSame($id, $json['reserva']['id']);
        self::assertSame("/funcion/reservas/{$id}", $json['url']);
        self::assertSame(2, $json['reserva']['cantidad']);
        self::assertSame('confirmada', $json['reserva']['estado']);
        self::assertTrue($json['reserva']['cancelable']);
        self::assertSame('$ 7.000', $json['reserva']['total']);
        // Lo que la ficha necesita para actualizar el número sin volver a preguntar.
        self::assertSame(38, $json['lugares_libres']);
    }

    /**
     * El correo es el mismo que manda la página, pero sale después de la respuesta: quien reserva
     * con fetch está mirando el número de lugares de la ficha, no esperando una página nueva.
     */
    public function testReservarEnJsonTambienMandaElCorreoDeConfirmada(): void
    {
        $respuesta = $this->reservarEnJson('2');

        self::assertSame(201, $respuesta->estado());
        // Todavía no salió: primero se suelta la respuesta.
        self::assertCount(0, $this->correos());

        $respuesta->terminar();

        $correos = $this->correos();
        self::assertCount(1, $correos);
        self::assertSame('Reserva confirmada: «Zama»', $correos[0]['asunto']);
        $id = self::json($respuesta)['reserva']['id'];
        self::assertStringContainsString(self::SITIO . "/funcion/reservas/{$id}", $correos[0]['texto']);
    }

    public function testUnaCantidadInvalidaEnJsonVuelveConElErrorDelCampo(): void
    {
        foreach (['', 'dos', '0', '-1', '1.5', '41', "1' OR 1=1"] as $cantidad) {
            $respuesta = $this->reservarEnJson($cantidad);

            self::assertSame(422, $respuesta->estado(), "Aceptó «{$cantidad}».");
            self::assertSame('Ingresá entre 1 y 40 lugares.', self::json($respuesta)['errores']['cantidad']);
        }
        self::assertSame(0, $this->reservas());
    }

    public function testSinLugaresSuficientesEnJsonLoDiceYNoReserva(): void
    {
        $this->ocupar(self::PROXIMA, 39);

        $respuesta = $this->reservarEnJson('2');
        $json = self::json($respuesta);

        self::assertSame(422, $respuesta->estado());
        self::assertSame('Queda 1 lugar.', $json['error']);
        // Con el número de verdad, así la ficha corrige el máximo del campo.
        self::assertSame(1, $json['lugares_libres']);
        self::assertSame(1, $this->reservas());
    }

    public function testCancelarEnJsonDevuelveLaCanceladaYElLugarQueVolvio(): void
    {
        $id = $this->reservada('2');

        $respuesta = $this->cancelarEnJson($id);
        $json = self::json($respuesta);

        self::assertSame(200, $respuesta->estado());
        self::assertSame('cancelada', $json['reserva']['estado']);
        self::assertFalse($json['reserva']['cancelable']);
        self::assertSame("/funcion/reservas/{$id}?cancelada=1", $json['url']);
        self::assertSame('cancelada', $this->estado($id));
        // Que el lugar vuelva a quedar libre ya lo cubre testElLugarCanceladoVuelveAQuedarLibre:
        // la respuesta de la cancelación no trae el número, porque el comprobante no lo muestra.
        self::assertArrayNotHasKey('lugares_libres', $json);
    }

    public function testUnaCanceladaNoSeVuelveACancelarEnJson(): void
    {
        $id = $this->reservada();
        $cancelada = $this->cancelarEnJson($id);
        self::assertSame(200, $cancelada->estado());

        $respuesta = $this->cancelarEnJson($id);

        self::assertSame(422, $respuesta->estado());
        self::assertSame('La reserva ya está cancelada.', self::json($respuesta)['error']);
        // La reserva viene igual cuando sale bien y cuando no: el front la lee de un solo modo.
        self::assertSame(self::json($cancelada)['reserva'], self::json($respuesta)['reserva']);
    }

    public function testLosErroresEnJsonNoCuentanNadaDeAdentro(): void
    {
        // Sin sesión, de otro y una que no existe: el error va en JSON, sin rastro ni clases.
        $ajena = $this->ocupar(self::PROXIMA, 1, self::ELIAS);
        $respuestas = [
            $this->reservarEnJson('1', usuario: null),
            $this->cancelarEnJson($ajena),
            $this->cancelarEnJson(Uuid::nuevo()),
        ];

        foreach ($respuestas as $respuesta) {
            self::assertContains($respuesta->estado(), [401, 404]);
            self::assertArrayHasKey('error', self::json($respuesta));
            self::assertArrayNotHasKey('detalle', self::json($respuesta));
            foreach (['Exception', '.php', 'IndieCinema\\', '#0 '] as $interno) {
                self::assertStringNotContainsString($interno, $respuesta->cuerpo());
            }
        }
    }
}
