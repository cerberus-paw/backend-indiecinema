<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La pestaña «Obras» de la bandeja de moderación (EF-07.7), de punta a punta: las obras que cargan
 * los organizadores quedan pendientes en programación hasta que un moderador las aprueba o las
 * rechaza con un motivo. Con la aplicación entera, un MySQL de verdad y una programación y una
 * cuentas de mentira, a las que se les piden las pendientes, la resolución y el dueño, y un SMTP
 * de prueba, que recibe el correo al dueño.
 */
final class BandejaDeObrasTest extends TestCase
{
    use ConBaseDePruebas;
    use ConServidoresDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const MODERADORA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    private const ADMINISTRADOR = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    // El organizador que conoce Dobles/cuentas.php.
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private static ServidorSmtpDePrueba $correo;

    private BaseDeDatos $base;

    /** Por dónde salen los correos: el SMTP de prueba, salvo que la prueba lo tire. */
    private ?string $servidorDeCorreo = null;

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
            'app' => ['subsistema' => 'moderacion', 'prefijo' => '/moderacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => sys_get_temp_dir() . '/sin-archivos'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['cuentas' => self::$urls['cuentas'], 'programacion' => self::$urls['programacion']],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => $this->servidorDeCorreo ?? self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
        ]);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo, string $url, ?string $rol = 'moderador', array $cuerpo = [], ?string $usuario = null): Respuesta
    {
        $usuario ??= $rol === 'administrador' ? self::ADMINISTRADOR : self::MODERADORA;
        $cabeceras = $rol === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('moderacion', $this->salidaDelLog));

        return PaginasParaValidar::anotar($metodo, "/moderacion{$url}", $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF])));
    }

    /**
     * @param array<string, string> $campos
     */
    private function resolver(string $id, array $campos, ?string $rol = 'moderador', ?string $usuario = null): Respuesta
    {
        return $this->pedir('POST', "/obras/{$id}/resolver", $rol, $campos + ['_csrf' => self::CSRF], $usuario);
    }

    /**
     * Una obra pendiente en programación.
     */
    private static function obra(string $titulo, string $creada = '2026-10-01T10:00:00', string $dueno = self::ORGANIZADOR, string $estado = 'pendiente'): string
    {
        $id = Uuid::nuevo();
        self::obrasEnProgramacion(self::obrasDeProgramacion() + [$id => ['titulo' => $titulo, 'creada_en' => $creada, 'dueno_id' => $dueno, 'estado' => $estado]]);

        return $id;
    }

    /**
     * @return list<string> los títulos de la lista, en orden
     */
    private static function titulos(Respuesta $respuesta): array
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        preg_match_all('#class="bandeja-item-sala">([^<]*)</span>#', $respuesta->cuerpo(), $titulos);

        return array_map(html_entity_decode(...), $titulos[1]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function registro(): array
    {
        return $this->base->filas('SELECT obra_id, dueno_id, titulo, resolucion, motivo, moderador_id, resuelta_en IS NOT NULL AS con_fecha FROM revision_obra ORDER BY resuelta_en');
    }

    public function testLasPendientesVanPrimeroLaMasViejaConLosDatosYElDueno(): void
    {
        self::obra('La nueva', '2026-10-05T10:00:00');
        $vieja = self::obra('La vieja', '2026-10-01T10:00:00');

        $respuesta = $this->pedir('GET', '/obras');

        self::assertSame(['La vieja', 'La nueva'], self::titulos($respuesta));
        $pantalla = $respuesta->cuerpo();
        self::assertStringContainsString('Rocío Paz', $pantalla);
        self::assertStringContainsString('Un pueblo y su río.', $pantalla);
        self::assertStringContainsString('Drama', $pantalla);
        self::assertStringContainsString('Obra propia', $pantalla);
        self::assertStringContainsString("src=\"/obras/{$vieja}/afiche\"", $pantalla);
        self::assertStringContainsString('Omar Prueba', $pantalla);
        self::assertStringContainsString("/moderacion/obras/{$vieja}/resolver", $pantalla);
    }

    public function testSinPendientesLoDice(): void
    {
        self::assertStringContainsString('No hay obras esperando revisión.', $this->pedir('GET', '/obras')->cuerpo());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function resoluciones(): iterable
    {
        yield 'aprobar' => ['aprobar', 'aprobada', 'Aprobaste «Mi obra»: ya está en el catálogo.'];
        yield 'rechazar' => ['rechazar', 'rechazada', 'Rechazaste «Mi obra». Su dueño ve el motivo y la puede corregir.'];
    }

    /**
     * Programación cambia el estado y moderación guarda quién, cuándo y por qué, que se ve en el
     * historial.
     */
    #[DataProvider('resoluciones')]
    public function testResolverLaCambiaEnProgramacionYQuedaEnElHistorial(string $resolucion, string $estado, string $aviso): void
    {
        $id = self::obra('Mi obra');

        $respuesta = $this->resolver($id, ['resolucion' => $resolucion, 'motivo' => 'Revisé el título y el afiche.']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/moderacion/obras?{$estado}={$id}", $respuesta->cabecera('Location'));
        self::assertSame([['ruta' => 'revision', 'obra' => $id, 'resolucion' => $resolucion, 'motivo' => 'Revisé el título y el afiche.', 'moderador_id' => self::MODERADORA]], self::pedidosA('programacion'));
        self::assertSame($estado, self::obrasDeProgramacion()[$id]['estado']);
        self::assertSame(
            [['obra_id' => $id, 'dueno_id' => self::ORGANIZADOR, 'titulo' => 'Mi obra', 'resolucion' => $estado, 'motivo' => 'Revisé el título y el afiche.', 'moderador_id' => self::MODERADORA, 'con_fecha' => 1]],
            $this->registro(),
        );
        self::assertStringContainsString(self::MODERADORA . " resolvió la obra {$id}: {$estado}.", $this->log());

        self::assertStringContainsString($aviso, $this->pedir('GET', "/obras?{$estado}={$id}")->cuerpo());
        self::assertSame([], self::titulos($this->pedir('GET', '/obras')));
        $historial = $this->pedir('GET', '/obras?ver=historial');
        self::assertSame(['Mi obra'], self::titulos($historial));
        self::assertStringContainsString('Revisé el título y el afiche.', $historial->cuerpo());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function correos(): iterable
    {
        yield 'aprobar' => ['aprobar', 'Aprobamos tu obra «Mi obra»', 'ya está en el catálogo'];
        yield 'rechazar' => ['rechazar', 'Rechazamos tu obra «Mi obra»', 'Corregila en «Mis obras» (/organizador/obras)'];
    }

    /**
     * EF-07.7 (Q17): su dueño se entera por correo, con el motivo. La dirección la da cuentas.
     */
    #[DataProvider('correos')]
    public function testResolverLeMandaUnCorreoAlDueno(string $resolucion, string $asunto, string $texto): void
    {
        $id = self::obra('Mi obra');

        $this->resolver($id, ['resolucion' => $resolucion, 'motivo' => 'Revisé el título y el afiche.']);

        $correos = self::correosEnviados();
        self::assertCount(1, $correos);
        self::assertSame('<omar@ejemplo.com>', $correos[0]['para']);
        self::assertSame($asunto, $correos[0]['asunto']);
        self::assertStringContainsString('Revisé el título y el afiche.', $correos[0]['texto']);
        self::assertStringContainsString($texto, $correos[0]['texto']);
    }

    /**
     * El correo es el aviso: lo que decidió el moderador no depende de él.
     */
    public function testSiCuentasNoRespondeLaResolucionQuedaYElLogLoDice(): void
    {
        $id = self::obra('Mi obra');
        self::tirar('cuentas');

        self::assertSame(303, $this->resolver($id, ['resolucion' => 'rechazar', 'motivo' => 'Falta el afiche.'])->estado());

        self::assertSame('rechazada', self::obrasDeProgramacion()[$id]['estado']);
        self::assertCount(1, $this->registro());
        self::assertSame([], self::correosEnviados());
        self::assertStringContainsString("La obra {$id} quedó rechazada, pero no se le avisó a su dueño", $this->log());
    }

    public function testSiFallaElCorreoLaResolucionQueda(): void
    {
        $id = self::obra('Mi obra');
        // En el puerto 1 no escucha nadie.
        $this->servidorDeCorreo = 'smtp://127.0.0.1:1';

        self::assertSame(303, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Bien.'])->estado());

        self::assertSame('aprobada', self::obrasDeProgramacion()[$id]['estado']);
        self::assertStringContainsString("La obra {$id} quedó aprobada, pero no se le avisó a su dueño", $this->log());
    }

    /**
     * @return list<array{para: string, asunto: string, texto: string}> los correos que salieron, ya decodificados
     */
    private static function correosEnviados(): array
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

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function resolucionesIncompletas(): iterable
    {
        yield 'sin motivo' => [['resolucion' => 'aprobar', 'motivo' => '   '], 'Escribí el motivo'];
        yield 'motivo de más' => [['resolucion' => 'aprobar', 'motivo' => str_repeat('a', 1001)], 'hasta 1000 caracteres'];
        yield 'sin resolución' => [['motivo' => 'Bien.'], 'Elegí si la aprobás o la rechazás.'];
    }

    /**
     * @param array<string, string> $campos
     */
    #[DataProvider('resolucionesIncompletas')]
    public function testSinMotivoOSinResolucionNoSeResuelve(array $campos, string $error): void
    {
        $id = self::obra('Mi obra');

        $respuesta = $this->resolver($id, $campos);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString($error, $respuesta->cuerpo());
        self::assertSame([], self::pedidosA('programacion'));
        self::assertSame([], $this->registro());
    }

    /**
     * Primero programación: si no responde, no queda nada y se reintenta.
     */
    public function testSiProgramacionNoRespondeNoQuedaRegistro(): void
    {
        $id = self::obra('Mi obra');
        self::tirar('programacion');

        self::assertSame(503, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Bien.'])->estado());
        self::assertSame([], $this->registro());

        $pantalla = $this->pedir('GET', '/obras');
        self::assertSame(200, $pantalla->estado());
        self::assertStringContainsString('No pudimos consultar las obras pendientes. Probá de nuevo en un rato.', $pantalla->cuerpo());
    }

    /**
     * Una que ya no está pendiente (otro moderador, dos pestañas) no se vuelve a resolver.
     */
    public function testUnaQueYaNoEstaPendienteNoSeResuelve(): void
    {
        $id = self::obra('Mi obra');
        $this->resolver($id, ['resolucion' => 'rechazar', 'motivo' => 'Falta el afiche.']);

        $otra = $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Bien.']);

        self::assertSame("/moderacion/obras?ya_resuelta={$id}", $otra->cabecera('Location'));
        self::assertSame('rechazada', self::obrasDeProgramacion()[$id]['estado']);
        self::assertCount(1, $this->registro());
        self::assertSame(404, $this->pedir('GET', '/obras?id=' . Uuid::nuevo())->estado());
    }

    /**
     * Q16: una aprobada se rechaza desde la pestaña, por su id (lo usa la denuncia de EF-07.8).
     * Aprobarla otra vez no tiene sentido: el formulario sólo la rechaza.
     */
    public function testUnaAprobadaSeAbrePorSuIdYSeRechaza(): void
    {
        $id = self::obra('La denunciada', estado: 'aprobada');

        $pantalla = $this->pedir('GET', "/obras?id={$id}");

        self::assertSame(200, $pantalla->estado(), $pantalla->cuerpo());
        self::assertStringContainsString('La denunciada', $pantalla->cuerpo());
        self::assertStringContainsString('Aprobada', $pantalla->cuerpo());
        self::assertStringContainsString("/moderacion/obras/{$id}/resolver", $pantalla->cuerpo());
        self::assertStringContainsString('value="rechazar"', $pantalla->cuerpo());
        self::assertStringNotContainsString('value="aprobar"', $pantalla->cuerpo());

        $respuesta = $this->resolver($id, ['resolucion' => 'rechazar', 'motivo' => 'La denunciaron: no tiene los derechos.']);

        self::assertSame("/moderacion/obras?rechazada={$id}", $respuesta->cabecera('Location'));
        self::assertSame('rechazada', self::obrasDeProgramacion()[$id]['estado']);
        self::assertSame('rechazada', $this->registro()[0]['resolucion']);
        self::assertSame('Rechazamos tu obra «La denunciada»', self::correosEnviados()[0]['asunto']);
    }

    /**
     * Una rechazada vuelve a revisión cuando su dueño la corrige: hasta entonces no se resuelve.
     */
    public function testUnaRechazadaSeVeSinFormulario(): void
    {
        $id = self::obra('La rechazada', estado: 'rechazada');

        $pantalla = $this->pedir('GET', "/obras?id={$id}")->cuerpo();

        self::assertStringContainsString('La rechazada', $pantalla);
        self::assertStringContainsString('Está rechazada: vuelve a revisión cuando su dueño la corrige.', $pantalla);
        self::assertStringNotContainsString("/obras/{$id}/resolver", $pantalla);
    }

    public function testUnaAprobadaNoSeVuelveAAprobar(): void
    {
        $id = self::obra('La aprobada', estado: 'aprobada');

        self::assertSame("/moderacion/obras?ya_resuelta={$id}", $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Bien.'])->cabecera('Location'));

        self::assertSame([], self::pedidosA('programacion'));
        self::assertSame([], $this->registro());
    }

    /**
     * Q1: el moderador no resuelve sus obras; el administrador sí.
     */
    public function testElModeradorNoResuelveSuPropiaObra(): void
    {
        $id = self::obra('La mía', dueno: self::MODERADORA);

        $pantalla = $this->pedir('GET', "/obras?id={$id}")->cuerpo();
        self::assertStringNotContainsString("/obras/{$id}/resolver", $pantalla);
        self::assertStringContainsString('La cargaste vos: la resuelve otro moderador.', $pantalla);
        self::assertSame(403, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Bien.'])->estado());
        self::assertSame([], self::pedidosA('programacion'));

        self::assertSame(303, $this->resolver($id, ['resolucion' => 'aprobar', 'motivo' => 'Bien.'], 'administrador')->estado());
        self::assertSame('aprobada', self::obrasDeProgramacion()[$id]['estado']);
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function quienEntra(): iterable
    {
        yield 'sin sesión' => [null, 401];
        yield 'espectador' => ['espectador', 403];
        yield 'organizador' => ['organizador', 403];
        yield 'moderador' => ['moderador', 200];
        yield 'administrador' => ['administrador', 200];
    }

    #[DataProvider('quienEntra')]
    public function testSoloEntranElModeradorYElAdministrador(?string $rol, int $estado): void
    {
        self::assertSame($estado, $this->pedir('GET', '/obras', $rol)->estado());
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }
}
