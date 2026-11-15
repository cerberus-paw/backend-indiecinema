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
 * Las denuncias (EF-07.8), de punta a punta: cualquiera con sesión denuncia una sala, una función,
 * una obra o un comentario, y la pestaña «Denuncias» de la bandeja de moderación las junta por lo
 * denunciado para que un moderador las desestime o actúe. Con la aplicación entera, un MySQL de
 * verdad, los subsistemas dueños de lo denunciado de mentira y un SMTP de prueba.
 */
final class DenunciasTest extends TestCase
{
    use ConBaseDePruebas;
    use ConServidoresDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    // Los que conoce Dobles/cuentas.php.
    private const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const MODERADORA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
    private const ADMINISTRADOR = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

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

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'moderacion', 'prefijo' => '/moderacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => sys_get_temp_dir() . '/sin-archivos'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['cuentas' => self::$urls['cuentas'], 'programacion' => self::$urls['programacion']],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
        ]);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo, string $url, ?string $rol = 'espectador', array $cuerpo = [], ?string $usuario = null): Respuesta
    {
        $usuario ??= match ($rol) {
            'administrador' => self::ADMINISTRADOR,
            'moderador' => self::MODERADORA,
            default => self::ESPECTADORA,
        };
        $cabeceras = $rol === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('moderacion', $this->salidaDelLog));

        return PaginasParaValidar::anotar($metodo, "/moderacion{$url}", $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF])));
    }

    /**
     * @param array<string, string> $campos
     */
    private function denunciar(string $tipo, string $id, array $campos, ?string $rol = 'espectador', ?string $usuario = null): Respuesta
    {
        return $this->pedir('POST', '/denuncias/nueva?' . http_build_query(['tipo' => $tipo, 'id' => $id]), $rol, $campos + ['_csrf' => self::CSRF], $usuario);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function denuncias(): array
    {
        return $this->base->filas('SELECT tipo, objeto_id, titulo, usuario_id, categoria, detalle, estado FROM denuncia ORDER BY creada_en, id');
    }

    /**
     * Una denuncia hecha en ese momento (UTC), por el formulario.
     */
    private function denunciada(string $tipo, string $id, string $usuario, string $cuando, string $categoria = 'ofensivo', string $detalle = ''): void
    {
        self::assertSame(303, $this->denunciar($tipo, $id, ['categoria' => $categoria, 'detalle' => $detalle], usuario: $usuario)->estado());
        $this->base->ejecutar('UPDATE denuncia SET creada_en = ? WHERE objeto_id = ? AND usuario_id = ?', [$cuando, $id, $usuario]);
    }

    /**
     * @param array<string, string> $campos
     */
    private function resolver(string $tipo, string $id, array $campos, string $rol = 'moderador', ?string $usuario = null): Respuesta
    {
        return $this->pedir('POST', "/denuncias/{$tipo}/{$id}/resolver", $rol, $campos + ['_csrf' => self::CSRF], $usuario);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cerradas(): array
    {
        return $this->base->filas('SELECT objeto_id, estado, accion, motivo, resuelta_por, resuelta_en IS NOT NULL AS con_fecha FROM denuncia WHERE estado <> \'abierta\' ORDER BY creada_en, id');
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
     * Una obra aprobada en programación, del organizador salvo que se diga otro dueño.
     */
    private static function obra(string $titulo, string $dueno = self::ORGANIZADOR, string $estado = 'aprobada'): string
    {
        $id = Uuid::nuevo();
        self::obrasEnProgramacion(self::obrasDeProgramacion() + [$id => ['titulo' => $titulo, 'dueno_id' => $dueno, 'estado' => $estado]]);

        return $id;
    }

    public function testElFormularioDeUnaObraMuestraQueSeDenunciaYSusCategorias(): void
    {
        $id = self::obra('La flor');

        $respuesta = $this->pedir('GET', "/denuncias/nueva?tipo=obra&id={$id}");

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $pantalla = $respuesta->cuerpo();
        self::assertStringContainsString('La flor', $pantalla);
        foreach (['Contenido ofensivo o inapropiado', 'Derechos de autor', 'Información falsa o engañosa', 'Otro'] as $categoria) {
            self::assertStringContainsString($categoria, $pantalla);
        }
        self::assertStringNotContainsString('Seguridad del lugar', $pantalla);
        self::assertStringContainsString('name="detalle"', $pantalla);
    }

    public function testNoSeDenunciaLoPropioNiLoQueNoEstaPublicado(): void
    {
        $propia = self::obra('La mía', self::ESPECTADORA);
        $pendiente = self::obra('Pendiente', estado: 'pendiente');
        $rechazada = self::obra('Rechazada', estado: 'rechazada');

        foreach (["tipo=obra&id={$propia}", "tipo=obra&id={$pendiente}", "tipo=obra&id={$rechazada}", 'tipo=obra&id=' . Uuid::nuevo(), 'tipo=obra&id=nada', 'tipo=persona&id=' . Uuid::nuevo(), 'id=' . Uuid::nuevo()] as $consulta) {
            self::assertSame(404, $this->pedir('GET', "/denuncias/nueva?{$consulta}")->estado(), $consulta);
        }
    }

    /**
     * Sin sesión, ingresa y vuelve al formulario (el ticket 07).
     */
    public function testSinSesionLlevaAIngresarYVuelve(): void
    {
        $id = self::obra('La flor');

        $respuesta = $this->pedir('GET', "/denuncias/nueva?tipo=obra&id={$id}", null);

        self::assertSame(401, $respuesta->estado());
        self::assertStringContainsString('href="/cuenta/ingresar?volver=' . rawurlencode("/moderacion/denuncias/nueva?tipo=obra&id={$id}") . '"', $respuesta->cuerpo());
    }

    public function testLaDenunciaQuedaAbiertaYSeConfirmaConElLinkALaFicha(): void
    {
        $id = self::obra('La flor');

        $respuesta = $this->denunciar('obra', $id, ['categoria' => 'derechos', 'detalle' => '  La subieron sin permiso.  ']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/moderacion/denuncias/nueva?tipo=obra&id={$id}&enviada=1", $respuesta->cabecera('Location'));
        self::assertSame(
            [['tipo' => 'obra', 'objeto_id' => $id, 'titulo' => 'La flor', 'usuario_id' => self::ESPECTADORA, 'categoria' => 'derechos', 'detalle' => 'La subieron sin permiso.', 'estado' => 'abierta']],
            $this->denuncias(),
        );
        $confirmacion = $this->pedir('GET', "/denuncias/nueva?tipo=obra&id={$id}&enviada=1")->cuerpo();
        self::assertStringContainsString('Recibimos tu denuncia. La revisa la moderación.', $confirmacion);
        self::assertStringContainsString("href=\"/obras/{$id}\"", $confirmacion);
        self::assertStringNotContainsString('name="categoria"', $confirmacion);
    }

    /**
     * Una abierta por usuario y objeto: repetirla no suma otra.
     */
    public function testUnaRepetidaNoSumaOtra(): void
    {
        $id = self::obra('La flor');
        $this->denunciar('obra', $id, ['categoria' => 'derechos', 'detalle' => '']);

        self::assertStringContainsString('Ya denunciaste esto: la está revisando la moderación.', $this->pedir('GET', "/denuncias/nueva?tipo=obra&id={$id}")->cuerpo());
        self::assertSame(303, $this->denunciar('obra', $id, ['categoria' => 'ofensivo', 'detalle' => ''])->estado());
        self::assertCount(1, $this->denuncias());

        $this->denunciar('obra', $id, ['categoria' => 'ofensivo', 'detalle' => ''], usuario: Uuid::nuevo());
        self::assertCount(2, $this->denuncias());
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function denunciasIncompletas(): iterable
    {
        yield 'sin categoría' => [['detalle' => 'Algo.'], 'categoria', 'Elegí de qué se trata.'];
        yield 'otra categoría' => [['categoria' => 'seguridad', 'detalle' => 'Algo.'], 'categoria', 'Elegí de qué se trata.'];
        yield 'otro sin detalle' => [['categoria' => 'otro', 'detalle' => '  '], 'detalle', 'Contanos qué pasa: en «Otro» el detalle es obligatorio.'];
        yield 'detalle de más' => [['categoria' => 'ofensivo', 'detalle' => str_repeat('a', 1001)], 'detalle', 'El detalle puede tener hasta 1000 caracteres.'];
    }

    /**
     * @param array<string, string> $campos
     */
    #[DataProvider('denunciasIncompletas')]
    public function testUnaDenunciaIncompletaNoSeGuarda(array $campos, string $campo, string $error): void
    {
        $id = self::obra('La flor');

        $respuesta = $this->denunciar('obra', $id, $campos);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString($error, $respuesta->cuerpo());
        self::assertStringContainsString("id=\"error-{$campo}\"", $respuesta->cuerpo());
        self::assertSame([], $this->denuncias());
    }

    /**
     * Un ítem por lo denunciado, primero el que lleva más tiempo; la elegida, con cada denuncia y
     * quién la hizo (cuentas da el nombre y el correo).
     */
    public function testLaBandejaAgrupaPorLoDenunciadoConCadaDenunciaYQuienLaHizo(): void
    {
        $nueva = self::obra('La nueva');
        $vieja = self::obra('La vieja');
        $this->denunciada('obra', $nueva, self::ESPECTADORA, '2026-11-02 10:00:00');
        $this->denunciada('obra', $vieja, self::ESPECTADORA, '2026-11-01 10:00:00', 'derechos', 'No tienen los derechos.');
        $this->denunciada('obra', $vieja, self::ADMINISTRADOR, '2026-11-03 10:00:00', 'falsa');

        $respuesta = $this->pedir('GET', '/denuncias', 'moderador');

        self::assertSame(['La vieja', 'La nueva'], self::titulos($respuesta));
        $pantalla = $respuesta->cuerpo();
        self::assertStringContainsString('2 denuncias', $pantalla);
        self::assertStringContainsString('Rocío Paz', $pantalla);
        self::assertStringContainsString('Derechos de autor', $pantalla);
        self::assertStringContainsString('No tienen los derechos.', $pantalla);
        self::assertStringContainsString('Información falsa o engañosa', $pantalla);
        self::assertStringContainsString('Eva Prueba', $pantalla);
        self::assertStringContainsString('eva@ejemplo.com', $pantalla);
        self::assertStringContainsString("/moderacion/denuncias/obra/{$vieja}/resolver", $pantalla);

        $otra = $this->pedir('GET', "/denuncias?tipo=obra&id={$nueva}", 'moderador')->cuerpo();
        self::assertStringContainsString("/moderacion/denuncias/obra/{$nueva}/resolver", $otra);
        self::assertSame(404, $this->pedir('GET', '/denuncias?tipo=obra&id=' . Uuid::nuevo(), 'moderador')->estado());
    }

    public function testSinDenunciasLoDice(): void
    {
        self::assertStringContainsString('No hay denuncias abiertas.', $this->pedir('GET', '/denuncias', 'moderador')->cuerpo());
    }

    public function testSiCuentasNoRespondeSeVeSinQuienDenuncio(): void
    {
        $this->denunciada('obra', self::obra('La flor'), self::ESPECTADORA, '2026-11-01 10:00:00');
        self::tirar('cuentas');

        $pantalla = $this->pedir('GET', '/denuncias', 'moderador');

        self::assertSame(['La flor'], self::titulos($pantalla));
        self::assertStringNotContainsString('Eva Prueba', $pantalla->cuerpo());
    }

    /**
     * Desestimar cierra todas las abiertas de lo elegido, sin tocar lo denunciado, y quedan en el
     * historial con el motivo.
     */
    public function testDesestimarCierraTodasYQuedanEnElHistorial(): void
    {
        $id = self::obra('La flor');
        $otra = self::obra('Otra');
        $this->denunciada('obra', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        $this->denunciada('obra', $id, self::ADMINISTRADOR, '2026-11-02 10:00:00');
        $this->denunciada('obra', $otra, self::ESPECTADORA, '2026-11-03 10:00:00');

        $respuesta = $this->resolver('obra', $id, ['accion' => 'desestimar', 'motivo' => 'Tiene los derechos: lo mostró el organizador.']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/moderacion/denuncias?desestimadas={$id}", $respuesta->cabecera('Location'));
        $cerrada = ['objeto_id' => $id, 'estado' => 'desestimada', 'accion' => null, 'motivo' => 'Tiene los derechos: lo mostró el organizador.', 'resuelta_por' => self::MODERADORA, 'con_fecha' => 1];
        self::assertSame([$cerrada, $cerrada], $this->cerradas());
        self::assertSame([], self::pedidosA('programacion'));
        self::assertStringContainsString('Desestimaste las denuncias de «La flor».', $this->pedir('GET', "/denuncias?desestimadas={$id}", 'moderador')->cuerpo());
        self::assertSame(['Otra'], self::titulos($this->pedir('GET', '/denuncias', 'moderador')));

        $historial = $this->pedir('GET', '/denuncias?ver=historial', 'moderador');
        self::assertSame(['La flor', 'La flor'], self::titulos($historial));
        self::assertStringContainsString('Desestimada', $historial->cuerpo());
        self::assertStringContainsString('Tiene los derechos: lo mostró el organizador.', $historial->cuerpo());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function resolucionesIncompletas(): iterable
    {
        yield 'sin motivo' => [['accion' => 'desestimar', 'motivo' => ' '], 'motivo'];
        yield 'motivo de más' => [['accion' => 'desestimar', 'motivo' => str_repeat('a', 1001)], 'motivo'];
        yield 'sin acción' => [['motivo' => 'Bien.'], 'accion'];
        yield 'otra acción' => [['accion' => 'ocultar', 'motivo' => 'Bien.'], 'accion'];
    }

    /**
     * @param array<string, string> $campos
     */
    #[DataProvider('resolucionesIncompletas')]
    public function testUnaResolucionIncompletaNoCierraNada(array $campos, string $campo): void
    {
        $id = self::obra('La flor');
        $this->denunciada('obra', $id, self::ESPECTADORA, '2026-11-01 10:00:00');

        $respuesta = $this->resolver('obra', $id, $campos);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString("id=\"error-{$campo}\"", $respuesta->cuerpo());
        self::assertSame([], $this->cerradas());
    }

    /**
     * Dos pestañas: la segunda vez ya no hay abiertas.
     */
    public function testResolverLoQueYaSeCerroNoCambiaNada(): void
    {
        $id = self::obra('La flor');
        $this->denunciada('obra', $id, self::ESPECTADORA, '2026-11-01 10:00:00');
        $this->resolver('obra', $id, ['accion' => 'desestimar', 'motivo' => 'Nada.']);

        $otra = $this->resolver('obra', $id, ['accion' => 'desestimar', 'motivo' => 'Otro motivo.']);

        self::assertSame("/moderacion/denuncias?ya_resueltas={$id}", $otra->cabecera('Location'));
        self::assertSame('Nada.', $this->cerradas()[0]['motivo']);
    }

    /**
     * Q1 de EF-07: el moderador no resuelve las denuncias sobre lo suyo ni las que hizo él; el
     * administrador sí.
     */
    public function testElModeradorNoResuelveLoSuyoNiLoQueDenuncio(): void
    {
        $suya = self::obra('La de Mora', self::MODERADORA);
        $denunciadaPorElla = self::obra('La flor');
        $this->denunciada('obra', $suya, self::ESPECTADORA, '2026-11-01 10:00:00');
        $this->denunciada('obra', $denunciadaPorElla, self::MODERADORA, '2026-11-02 10:00:00');

        foreach ([$suya, $denunciadaPorElla] as $id) {
            $pantalla = $this->pedir('GET', "/denuncias?tipo=obra&id={$id}", 'moderador')->cuerpo();
            self::assertStringNotContainsString("/denuncias/obra/{$id}/resolver", $pantalla);
            self::assertStringContainsString('Es tuyo o lo denunciaste vos: lo resuelve otro moderador.', $pantalla);
            self::assertSame(403, $this->resolver('obra', $id, ['accion' => 'desestimar', 'motivo' => 'Nada.'])->estado());
        }
        self::assertSame([], $this->cerradas());

        self::assertSame(303, $this->resolver('obra', $suya, ['accion' => 'desestimar', 'motivo' => 'Nada.'], 'administrador')->estado());
        self::assertCount(1, $this->cerradas());
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
        self::assertSame($estado, $this->pedir('GET', '/denuncias', $rol)->estado());
    }
}
