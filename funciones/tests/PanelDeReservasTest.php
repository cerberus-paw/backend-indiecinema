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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El seguimiento de las reservas de punta a punta (E4-03): el panel del organizador con la
 * asistencia y el CSV, el del administrador con la anulación, la cancelación de las reservas de
 * una función cancelada con el aviso por correo, y quién entra a cada cosa (E4-03.6). Con la
 * sesión que manda nginx, el token CSRF, la base de verdad, una programación de mentira (la
 * función PROXIMA es de ORGANIZADORA, con 40 lugares a 3500), un cuentas de mentira y un servidor
 * de correo de prueba.
 */
final class PanelDeReservasTest extends TestCase
{
    use ConBaseDePruebas;
    use ConCuentasDePrueba;
    use ConProgramacionDePrueba {
        setUpBeforeClass as iniciarProgramacion;
        tearDownAfterClass as detenerProgramacion;
    }

    /** La cabecera con la que el panel pide la respuesta en JSON, como la manda fetch (E4-05.5). */
    private const ACEPTA_JSON = ['Accept' => 'application/json'];

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ORGANIZADORA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    private const OTRO_ORGANIZADOR = '77777777-7777-4777-8777-777777777777';
    private const ADMINISTRADORA = '88888888-8888-4888-8888-888888888888';
    private const EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ELIAS = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const PROXIMA = '12121212-1212-4212-8212-121212121212';
    private const EMPEZADA = '34343434-3434-4434-8434-343434343434';
    private const CANCELADA = '56565656-5656-4656-8656-565656565656';
    private const TOKEN_DE_PROGRAMACION = 'token-de-programacion';

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
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['programacion' => self::$url, 'cuentas' => self::$urlCuentas],
            'interno' => ['token' => 'token-de-funciones'],
            'llamadores' => ['programacion' => hash('sha256', self::TOKEN_DE_PROGRAMACION)],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
            'sitio' => ['url' => 'http://localhost:8080'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        self::$correo->vaciar();
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo, string $ruta, ?string $usuario = self::ORGANIZADORA, string $rol = 'organizador', array $cuerpo = [], array $extra = []): Respuesta
    {
        $cabeceras = $usuario === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        $cabeceras = [...$cabeceras, ...$extra];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);
        $cuerpo = $metodo === 'POST' ? ['_csrf' => self::CSRF, ...$cuerpo] : $cuerpo;

        return $aplicacion->atender(new Peticion($metodo, (string) parse_url($ruta, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF], []));
    }

    private function deProgramacion(string $funcion, array $cuerpo, string $token = self::TOKEN_DE_PROGRAMACION): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('POST', "/interno/funciones/{$funcion}/cancelar-reservas", [], $cuerpo, [
            'X-Llamador' => 'programacion',
            'Authorization' => "Bearer {$token}",
        ], [], []));
    }

    /**
     * Una reserva hecha por otros, sin pasar por la página.
     *
     * @return string su código
     */
    private function reserva(string $funcion, int $cantidad, string $estado = 'confirmada', string $usuario = self::EVA, string $creada = '2026-10-19 13:00:00'): string
    {
        $codigo = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [Uuid::nuevo(), $funcion, $usuario, $codigo, $cantidad, number_format($cantidad * 3500, 2, '.', ''), $estado, $creada],
        );

        return $codigo;
    }

    private function estadoDe(string $codigo): string
    {
        return (string) $this->base->valor('SELECT estado FROM reserva WHERE codigo = ?', [$codigo]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historialDe(string $codigo): array
    {
        return $this->base->filas(
            'SELECT c.estado_anterior, c.estado_nuevo, c.autor_id, c.autor_rol, c.motivo FROM cambio_reserva c JOIN reserva r ON r.id = c.reserva_id WHERE r.codigo = ?',
            [$codigo],
        );
    }

    // ---- El panel del organizador (E4-03.1) -------------------------------------------------

    public function testElOrganizadorVeLasReservasYLosLugaresReservadosYLibres(): void
    {
        $confirmada = $this->reserva(self::PROXIMA, 2);
        $conAsistencia = $this->reserva(self::PROXIMA, 3, 'asistio');
        $cancelada = $this->reserva(self::PROXIMA, 4, 'cancelada');
        $deOtra = $this->reserva(self::EMPEZADA, 1);

        $respuesta = $this->pedir('GET', '/organizador/funciones/' . self::PROXIMA . '/reservas');

        self::assertSame(200, $respuesta->estado());
        $cuerpo = $respuesta->cuerpo();
        foreach ([$confirmada, $conAsistencia, $cancelada] as $codigo) {
            self::assertStringContainsString($codigo, $cuerpo);
        }
        self::assertStringNotContainsString($deOtra, $cuerpo);
        // 2 + 3 reservados de 40: la cancelada soltó los suyos.
        self::assertMatchesRegularExpression('#Lugares reservados</dt>\s*<dd class="panel-admin-dato-valor"[^>]*>5</dd>#', $cuerpo);
        self::assertMatchesRegularExpression('#Lugares libres</dt>\s*<dd class="panel-admin-dato-valor"[^>]*>35</dd>#', $cuerpo);
        self::assertMatchesRegularExpression('#Asistentes registrados</dt>\s*<dd class="panel-admin-dato-valor"[^>]*>3</dd>#', $cuerpo);
        self::assertStringContainsString('$ 17.500', $cuerpo);
    }

    /**
     * La pantalla es parte del panel del organizador (E4-10.12): la barra lateral de programación,
     * con Mis funciones marcada, en lugar del encabezado del sitio. Las otras pantallas del panel
     * lo prueban en programación (BarraDelOrganizadorTest).
     */
    public function testLasReservasDeLaFuncionTienenLaBarraDelPanelConMisFuncionesMarcada(): void
    {
        $pagina = $this->pedir('GET', '/organizador/funciones/' . self::PROXIMA . '/reservas', extra: ['X-Usuario-Nombre' => rawurlencode('Ana Prueba')])->cuerpo();

        preg_match('#<aside[^>]*aria-label="Panel del organizador"[^>]*>.*?</aside>#s', $pagina, $barra);
        $barra = $barra[0] ?? '';
        preg_match_all('#href="([^"]*)"#', $barra, $enlaces);
        $enlaces = array_values(array_unique($enlaces[1]));
        sort($enlaces);
        preg_match_all('#<a href="([^"]*)"[^>]*aria-current="page"#', $barra, $actuales);

        // Los enlaces van sin el prefijo de funciones: las pantallas del panel son de programación.
        self::assertSame(['/', '/organizador/funciones', '/organizador/funciones/nueva', '/organizador/salas', '/solicitar-sala'], $enlaces);
        self::assertSame(['/organizador/funciones'], $actuales[1]);
        self::assertStringContainsString('Ana Prueba', $barra);
        self::assertStringContainsString('action="/cuenta/salir"', $barra);
        self::assertStringNotContainsString('aria-label="Navegación principal"', $pagina);
    }

    // ---- La asistencia (E4-03.2) ------------------------------------------------------------

    public function testRegistraLaAsistenciaUnaSolaVezConElCodigoComoLoDicten(): void
    {
        $codigo = $this->reserva(self::PROXIMA, 2);

        $respuesta = $this->pedir('POST', '/organizador/funciones/' . self::PROXIMA . '/asistencia', cuerpo: ['codigo' => strtolower(substr($codigo, 0, 4) . ' ' . substr($codigo, 4))]);

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/funcion/organizador/funciones/' . self::PROXIMA . "/reservas?asistencia={$codigo}", $respuesta->cabecera('Location'));
        self::assertSame('asistio', $this->estadoDe($codigo));
        self::assertStringContainsString("Se registró la asistencia de la reserva {$codigo}", $this->pedir('GET', '/organizador/funciones/' . self::PROXIMA . "/reservas?asistencia={$codigo}")->cuerpo());

        $otraVez = $this->pedir('POST', '/organizador/funciones/' . self::PROXIMA . '/asistencia', cuerpo: ['codigo' => $codigo]);
        self::assertSame(422, $otraVez->estado());
        self::assertStringContainsString('ya tiene la asistencia registrada', $otraVez->cuerpo());
        self::assertSame([[
            'estado_anterior' => 'confirmada',
            'estado_nuevo' => 'asistio',
            'autor_id' => self::ORGANIZADORA,
            'autor_rol' => 'organizador',
            'motivo' => null,
        ]], $this->historialDe($codigo));
    }

    public function testLaAsistenciaEnJsonDevuelveLaFilaComoQuedoYElResumenDeArriba(): void
    {
        $codigo = $this->reserva(self::PROXIMA, 2);
        $ruta = '/organizador/funciones/' . self::PROXIMA . '/asistencia';

        $respuesta = $this->pedir('POST', $ruta, cuerpo: ['codigo' => $codigo], extra: self::ACEPTA_JSON);

        self::assertSame(200, $respuesta->estado());
        $datos = json_decode($respuesta->cuerpo(), true);
        self::assertSame($codigo, $datos['reserva']['codigo']);
        self::assertSame('asistio', $datos['reserva']['estado']);
        self::assertSame('con asistencia', $datos['reserva']['estado_etiqueta']);
        // Ya no se registra de nuevo: la fila deja de tener botón.
        self::assertFalse($datos['reserva']['registrable']);
        // El resumen viaja porque «Asistentes registrados» se mueve con cada asistencia.
        self::assertSame(2, $datos['resumen']['asistentes']);
        self::assertSame('asistio', $this->estadoDe($codigo));

        // Y la segunda vez, el mismo mensaje que la pantalla pero en JSON.
        $otraVez = $this->pedir('POST', $ruta, cuerpo: ['codigo' => $codigo], extra: self::ACEPTA_JSON);
        self::assertSame(422, $otraVez->estado());
        self::assertStringContainsString('ya tiene la asistencia registrada', json_decode($otraVez->cuerpo(), true)['error']);
    }

    public function testNoRegistraUnCodigoDeOtraFuncionNiUnaCanceladaNiEnUnaFuncionCancelada(): void
    {
        $deOtra = $this->reserva(self::EMPEZADA, 1);
        $cancelada = $this->reserva(self::PROXIMA, 1, 'cancelada');
        $deLaCancelada = $this->reserva(self::CANCELADA, 1);

        $otraFuncion = $this->pedir('POST', '/organizador/funciones/' . self::PROXIMA . '/asistencia', cuerpo: ['codigo' => $deOtra]);
        self::assertSame(422, $otraFuncion->estado());
        self::assertStringContainsString("No hay ninguna reserva con el código {$deOtra} para esta función", $otraFuncion->cuerpo());

        self::assertStringContainsString('está cancelada', $this->pedir('POST', '/organizador/funciones/' . self::PROXIMA . '/asistencia', cuerpo: ['codigo' => $cancelada])->cuerpo());
        self::assertSame(422, $this->pedir('POST', '/organizador/funciones/' . self::CANCELADA . '/asistencia', cuerpo: ['codigo' => $deLaCancelada])->estado());
        self::assertSame(422, $this->pedir('POST', '/organizador/funciones/' . self::PROXIMA . '/asistencia', cuerpo: ['codigo' => '  '])->estado());
        self::assertSame(['confirmada', 'cancelada', 'confirmada'], [$this->estadoDe($deOtra), $this->estadoDe($cancelada), $this->estadoDe($deLaCancelada)]);
    }

    // ---- El CSV (E4-03.5) -------------------------------------------------------------------

    public function testElCsvTieneLasReservasDeLaFuncionParaUnaPlanilla(): void
    {
        $primera = $this->reserva(self::PROXIMA, 2, creada: '2026-10-19 13:00:00');
        $segunda = $this->reserva(self::PROXIMA, 1, 'asistio', creada: '2026-10-20 23:30:00');

        $respuesta = $this->pedir('GET', '/organizador/funciones/' . self::PROXIMA . '/reservas.csv');

        self::assertSame(200, $respuesta->estado());
        self::assertSame('text/csv; charset=utf-8', $respuesta->cabecera('Content-Type'));
        self::assertStringContainsString('attachment; filename="reservas-' . self::PROXIMA . '.csv"', (string) $respuesta->cabecera('Content-Disposition'));
        self::assertSame(
            "\u{FEFF}Código;Lugares;Total;Estado;\"Reservada el\";Hora\r\n"
            . "{$primera};2;7000,00;confirmada;2026-10-19;10:00\r\n"
            . "{$segunda};1;3500,00;\"con asistencia\";2026-10-20;20:30\r\n",
            $respuesta->cuerpo(),
        );
    }

    // ---- El panel del administrador (E4-03.3) -----------------------------------------------

    public function testElAdministradorVeTodasYFiltraPorEstado(): void
    {
        $confirmada = $this->reserva(self::PROXIMA, 2);
        $cancelada = $this->reserva(self::EMPEZADA, 1, 'cancelada');

        $todas = $this->pedir('GET', '/administracion/reservas', self::ADMINISTRADORA, 'administrador');
        self::assertSame(200, $todas->estado());
        self::assertStringContainsString($confirmada, $todas->cuerpo());
        self::assertStringContainsString($cancelada, $todas->cuerpo());
        // Con el título de la función, pedido a programación.
        self::assertStringContainsString('Zama', $todas->cuerpo());

        $canceladas = $this->pedir('GET', '/administracion/reservas?estado=cancelada', self::ADMINISTRADORA, 'administrador')->cuerpo();
        self::assertStringContainsString($cancelada, $canceladas);
        self::assertStringNotContainsString($confirmada, $canceladas);
        // Un estado que no existe se ignora; una página que no existe es 404.
        self::assertStringContainsString($confirmada, $this->pedir('GET', '/administracion/reservas?estado=otra', self::ADMINISTRADORA, 'administrador')->cuerpo());
        self::assertSame(404, $this->pedir('GET', '/administracion/reservas?pagina=2', self::ADMINISTRADORA, 'administrador')->estado());
    }

    public function testElAdministradorAnulaUnaReservaYSeLiberaElLugar(): void
    {
        $codigo = $this->reserva(self::PROXIMA, 40);
        $id = (string) $this->base->valor('SELECT id FROM reserva WHERE codigo = ?', [$codigo]);

        $respuesta = $this->pedir('POST', "/administracion/reservas/{$id}/anular", self::ADMINISTRADORA, 'administrador');

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/funcion/administracion/reservas?anulada={$codigo}", $respuesta->cabecera('Location'));
        self::assertSame('cancelada', $this->estadoDe($codigo));
        // La función estaba agotada y ahora Elías reserva un lugar.
        $reservaDeElias = $this->pedir('POST', '/reservar/' . self::PROXIMA, self::ELIAS, 'espectador', ['cantidad' => '1']);
        self::assertSame(303, $reservaDeElias->estado());
        self::assertSame('administrador', $this->historialDe($codigo)[0]['autor_rol']);
        self::assertSame(422, $this->pedir('POST', "/administracion/reservas/{$id}/anular", self::ADMINISTRADORA, 'administrador')->estado());
    }

    public function testLaAnulacionEnJsonDevuelveLaFilaComoQuedo(): void
    {
        $codigo = $this->reserva(self::PROXIMA, 3);
        $id = (string) $this->base->valor('SELECT id FROM reserva WHERE codigo = ?', [$codigo]);
        $ruta = "/administracion/reservas/{$id}/anular";

        $respuesta = $this->pedir('POST', $ruta, self::ADMINISTRADORA, 'administrador', extra: self::ACEPTA_JSON);

        self::assertSame(200, $respuesta->estado());
        $datos = json_decode($respuesta->cuerpo(), true);
        self::assertSame($codigo, $datos['reserva']['codigo']);
        self::assertSame('cancelada', $datos['reserva']['estado']);
        self::assertSame('cancelada', $datos['reserva']['estado_etiqueta']);
        // Ya no se anula de nuevo: la fila deja de tener botón.
        self::assertFalse($datos['reserva']['anulable']);
        self::assertSame('cancelada', $this->estadoDe($codigo));

        // Y la segunda vez, el mismo mensaje que la pantalla pero en JSON.
        $otraVez = $this->pedir('POST', $ruta, self::ADMINISTRADORA, 'administrador', extra: self::ACEPTA_JSON);
        self::assertSame(422, $otraVez->estado());
        self::assertArrayHasKey('error', json_decode($otraVez->cuerpo(), true));
    }

    // ---- Cancelar la función cancela sus reservas (E4-03.4) ---------------------------------

    public function testCancelarLaFuncionCancelaLasConfirmadasYAvisaPorCorreo(): void
    {
        $deEva = $this->reserva(self::PROXIMA, 2, usuario: self::EVA);
        $deElias = $this->reserva(self::PROXIMA, 1, usuario: self::ELIAS);
        $conAsistencia = $this->reserva(self::PROXIMA, 1, 'asistio');
        $deOtraFuncion = $this->reserva(self::EMPEZADA, 1);

        $respuesta = $this->deProgramacion(self::PROXIMA, ['organizador_id' => self::ORGANIZADORA, 'titulo' => 'Zama', 'fecha' => 'Sáb 21 nov · 20:30']);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(['canceladas' => 2], json_decode($respuesta->cuerpo(), true));
        self::assertSame(['cancelada', 'cancelada', 'asistio', 'confirmada'], array_map($this->estadoDe(...), [$deEva, $deElias, $conAsistencia, $deOtraFuncion]));
        self::assertSame('Se canceló la función.', $this->historialDe($deEva)[0]['motivo']);
        self::assertSame(self::ORGANIZADORA, $this->historialDe($deEva)[0]['autor_id']);

        // Los avisos salen después de responder, para que programación no los espere.
        self::assertCount(0, self::$correo->correos());
        $respuesta->terminar();
        $correos = self::$correo->correos();
        self::assertCount(2, $correos);
        self::assertEqualsCanonicalizing(['<' . self::EVA . '@ejemplo.test>', '<' . self::ELIAS . '@ejemplo.test>'], array_merge(...array_column($correos, 'para')));

        // La segunda vez no queda ninguna confirmada.
        self::assertSame(['canceladas' => 0], json_decode($this->deProgramacion(self::PROXIMA, ['organizador_id' => self::ORGANIZADORA, 'titulo' => 'Zama', 'fecha' => null])->cuerpo(), true));
    }

    public function testSiNoSeEncuentraAlEspectadorLaReservaSeCancelaIgual(): void
    {
        $codigo = $this->reserva(self::PROXIMA, 1, usuario: '00000000-0000-4000-8000-000000000000');

        $respuesta = $this->deProgramacion(self::PROXIMA, ['organizador_id' => self::ORGANIZADORA, 'titulo' => 'Zama', 'fecha' => null]);

        self::assertSame(['canceladas' => 1], json_decode($respuesta->cuerpo(), true));
        self::assertSame('cancelada', $this->estadoDe($codigo));
        $respuesta->terminar();
        self::assertCount(0, self::$correo->correos());
    }

    public function testLaCancelacionPideQuienYElTituloYSoloLaLlamaProgramacion(): void
    {
        $codigo = $this->reserva(self::PROXIMA, 1);

        self::assertSame(400, $this->deProgramacion(self::PROXIMA, ['titulo' => 'Zama'])->estado());
        self::assertSame(400, $this->deProgramacion(self::PROXIMA, ['organizador_id' => self::ORGANIZADORA])->estado());
        self::assertSame(403, $this->deProgramacion(self::PROXIMA, ['organizador_id' => self::ORGANIZADORA, 'titulo' => 'Zama'], 'otro-token')->estado());
        self::assertSame('confirmada', $this->estadoDe($codigo));
    }

    // ---- Quién entra (E4-03.6) --------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rutasDelOrganizador(): iterable
    {
        yield 'la lista' => ['GET', '/organizador/funciones/' . self::PROXIMA . '/reservas'];
        yield 'la asistencia' => ['POST', '/organizador/funciones/' . self::PROXIMA . '/asistencia'];
        yield 'el CSV' => ['GET', '/organizador/funciones/' . self::PROXIMA . '/reservas.csv'];
    }

    #[DataProvider('rutasDelOrganizador')]
    public function testOtroOrganizadorNoVeNiTocaLasReservasAjenas(string $metodo, string $ruta): void
    {
        $codigo = $this->reserva(self::PROXIMA, 1);

        $respuesta = $this->pedir($metodo, $ruta, self::OTRO_ORGANIZADOR, cuerpo: ['codigo' => $codigo]);

        self::assertSame(404, $respuesta->estado());
        self::assertStringNotContainsString($codigo, $respuesta->cuerpo());
        self::assertSame('confirmada', $this->estadoDe($codigo));
    }

    #[DataProvider('rutasDelOrganizador')]
    public function testElPanelDelOrganizadorPideSesionYRolDeOrganizador(string $metodo, string $ruta): void
    {
        self::assertSame(401, $this->pedir($metodo, $ruta, null)->estado());
        self::assertSame(403, $this->pedir($metodo, $ruta, self::EVA, 'espectador')->estado());
    }

    public function testElPanelDelAdministradorEsSoloDelAdministrador(): void
    {
        $id = (string) $this->base->valor('SELECT id FROM reserva WHERE codigo = ?', [$this->reserva(self::PROXIMA, 1)]);

        foreach ([['GET', '/administracion/reservas'], ['POST', "/administracion/reservas/{$id}/anular"]] as [$metodo, $ruta]) {
            self::assertSame(401, $this->pedir($metodo, $ruta, null)->estado());
            self::assertSame(403, $this->pedir($metodo, $ruta, self::EVA, 'espectador')->estado());
            self::assertSame(403, $this->pedir($metodo, $ruta, self::ORGANIZADORA, 'organizador')->estado());
        }
        self::assertSame(404, $this->pedir('POST', '/administracion/reservas/' . Uuid::nuevo() . '/anular', self::ADMINISTRADORA, 'administrador')->estado());
    }

    public function testSinTokenCsrfNoSeRegistraNiSeAnula(): void
    {
        $codigo = $this->reserva(self::PROXIMA, 1);
        $id = (string) $this->base->valor('SELECT id FROM reserva WHERE codigo = ?', [$codigo]);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));

        $sinToken = static fn (string $ruta, string $usuario, string $rol): int => $aplicacion->atender(new Peticion(
            'POST', $ruta, [], ['codigo' => $codigo], ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol], [], [],
        ))->estado();

        self::assertSame(403, $sinToken('/organizador/funciones/' . self::PROXIMA . '/asistencia', self::ORGANIZADORA, 'organizador'));
        self::assertSame(403, $sinToken("/administracion/reservas/{$id}/anular", self::ADMINISTRADORA, 'administrador'));
        self::assertSame('confirmada', $this->estadoDe($codigo));
    }
}
