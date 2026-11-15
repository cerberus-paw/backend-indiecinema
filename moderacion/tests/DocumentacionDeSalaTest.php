<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use DateTimeImmutable;
use IndieCinema\Moderacion\Pruebas\Dobles\Documentos;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\Formularios;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La documentación de una sala para su organizador (EF-07.5), de punta a punta: las suspensiones
 * con su causa y su motivo, el seguro y la habilitación vigentes, y la renovación que sube. Con la
 * aplicación entera, las plantillas del paquete front, un MySQL de verdad y una programación de
 * mentira (ConServidoresDePrueba), que dice de quién es la sala.
 */
final class DocumentacionDeSalaTest extends TestCase
{
    use ConBaseDePruebas;
    use ConServidoresDePrueba;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const OTRO = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private BaseDeDatos $base;
    private string $carpeta;
    private string $sala;

    public static function setUpBeforeClass(): void
    {
        self::iniciarServidores();
    }

    public static function tearDownAfterClass(): void
    {
        self::detenerServidores();
    }

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        self::reiniciarServidores();
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->sala = Uuid::nuevo();
        self::salasEnProgramacion([$this->sala => ['nombre' => 'El Galpón', 'localidad' => 'Luján', 'organizador_id' => self::ORGANIZADOR, 'habilitada' => false]]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->carpeta}/documentos/*") ?: []);
        @rmdir("{$this->carpeta}/documentos");
        @rmdir($this->carpeta);
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'moderacion', 'prefijo' => '/moderacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['cuentas' => self::$urls['cuentas'], 'programacion' => self::$urls['programacion']],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => 'smtp://127.0.0.1:1', 'remitente' => 'no-responder@indiecinema.test'],
        ]);
    }

    /**
     * @param array<string, mixed>                $cuerpo
     * @param array<string, array<string, mixed>> $archivos
     */
    private function pedir(string $metodo = 'GET', ?string $url = null, ?string $rol = 'organizador', string $usuario = self::ORGANIZADOR, array $cuerpo = [], array $archivos = []): Respuesta
    {
        $url ??= "/salas/{$this->sala}/documentacion";
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $cabeceras = $rol === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol, 'X-Usuario-Nombre' => rawurlencode('Omar Prueba')];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('moderacion', fopen('php://memory', 'w')));
        $respuesta = $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF], $archivos));

        return str_contains($url, '/documentos/') ? $respuesta : PaginasParaValidar::anotar($metodo, "/moderacion{$url}", $respuesta);
    }

    /**
     * @param array<string, string>     $cambios
     * @param array<string, mixed>|null $documento
     */
    private function subir(array $cambios = [], ?array $documento = null, string $usuario = self::ORGANIZADOR): Respuesta
    {
        return $this->pedir('POST', usuario: $usuario, cuerpo: $cambios + [
            '_csrf' => self::CSRF,
            'tipo' => 'seguro',
            'vence' => self::dentroDe('+1 year'),
        ], archivos: ['documento' => $documento ?? Documentos::subida('poliza.pdf')]);
    }

    private static function dentroDe(string $cuanto): string
    {
        return new DateTimeImmutable('today')->modify($cuanto)->format('Y-m-d');
    }

    private function documento(string $tipo, string $vence, string $estado = 'aceptado'): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            "INSERT INTO documento (id, sala_id, tipo, archivo, vence_en, subido_en, estado)
             VALUES (?, ?, ?, '0123456789abcdef0123456789abcdef.pdf', ?, UTC_TIMESTAMP(), ?)",
            [$id, $this->sala, $tipo, self::dentroDe($vence), $estado],
        );

        return $id;
    }

    private function suspension(string $causa, string $motivo, ?string $tipo = null): void
    {
        $this->base->ejecutar(
            'INSERT INTO suspension (id, sala_id, causa, tipo_documento, motivo, desde) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [Uuid::nuevo(), $this->sala, $causa, $tipo, $motivo],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function renovaciones(): array
    {
        return $this->base->filas('SELECT tipo, vence_en, estado, motivo, solicitud_id FROM documento WHERE sala_id = ? ORDER BY subido_en, estado', [$this->sala]);
    }

    public function testElOrganizadorVeSusSuspensionesYSusDocumentos(): void
    {
        $this->documento('seguro', '-1 month');
        $vigente = $this->documento('seguro', '+1 year');
        $pendiente = $this->documento('habilitacion', '+2 years', 'pendiente');
        $this->suspension('retiro', 'Reclamos por el estado de las butacas.');
        $this->suspension('vencimiento', 'Venció la habilitación.', 'habilitacion');
        $this->suspension('denuncia', 'Se proyectó una obra sin los derechos.');

        $respuesta = $this->pedir();

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('El Galpón', $cuerpo);
        self::assertStringContainsString('Reclamos por el estado de las butacas.', $cuerpo);
        self::assertStringContainsString('Venció la habilitación.', $cuerpo);
        self::assertStringContainsString('Se proyectó una obra sin los derechos.', $cuerpo);
        self::assertStringContainsString('Por una denuncia', $cuerpo);
        // El vigente es el que vence más lejos; el pendiente, con su aviso.
        self::assertStringContainsString("href=\"/moderacion/documentos/{$vigente}\"", $cuerpo);
        self::assertStringContainsString('vence el ' . new DateTimeImmutable(self::dentroDe('+1 year'))->format('d/m/Y'), $cuerpo);
        self::assertStringContainsString("href=\"/moderacion/documentos/{$pendiente}\"", $cuerpo);
        self::assertStringContainsString('Pendiente de revisión', $cuerpo);
        self::assertStringContainsString('action="/moderacion/salas/' . $this->sala . '/documentacion"', $cuerpo);
    }

    public function testSinSuspensionesNiDocumentosLoDice(): void
    {
        $cuerpo = $this->pedir()->cuerpo();

        self::assertStringContainsString('No está suspendida.', $cuerpo);
        self::assertStringContainsString('Todavía no tiene el seguro aceptado.', $cuerpo);
    }

    public function testSoloLaVeSuOrganizador(): void
    {
        self::assertSame(404, $this->pedir(usuario: self::OTRO)->estado());
        self::assertSame(404, $this->pedir(url: '/salas/' . Uuid::nuevo() . '/documentacion')->estado());
        self::assertSame(404, $this->pedir(url: '/salas/no-es-un-id/documentacion')->estado());
        self::assertSame(403, $this->pedir(rol: 'espectador')->estado());
        self::assertSame(401, $this->pedir(rol: null)->estado());
        self::assertSame(404, $this->subir(usuario: self::OTRO)->estado());
        self::assertSame([], $this->renovaciones());
    }

    public function testSubirUnaRenovacionLaDejaPendienteSinSolicitud(): void
    {
        $respuesta = $this->subir();

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/moderacion/salas/{$this->sala}/documentacion?subido=1", $respuesta->cabecera('Location'));
        self::assertSame([['tipo' => 'seguro', 'vence_en' => self::dentroDe('+1 year'), 'estado' => 'pendiente', 'motivo' => null, 'solicitud_id' => null]], $this->renovaciones());
        self::assertCount(1, glob("{$this->carpeta}/documentos/*.pdf") ?: []);
        self::assertStringContainsString('Lo va a revisar la moderación', $this->pedir(url: "/salas/{$this->sala}/documentacion?subido=1")->cuerpo());
    }

    /**
     * Con una pendiente del mismo tipo, la nueva la reemplaza: la moderación revisa una sola.
     */
    public function testUnaNuevaReemplazaALaPendienteDelMismoTipo(): void
    {
        $this->subir(['vence' => self::dentroDe('+6 months')]);
        $this->subir(['tipo' => 'habilitacion']);

        $this->subir(['vence' => self::dentroDe('+1 year')]);

        // Suben en el mismo segundo: el orden no dice nada.
        self::assertEqualsCanonicalizing(
            [
                ['tipo' => 'seguro', 'vence_en' => self::dentroDe('+6 months'), 'estado' => 'rechazado', 'motivo' => 'Lo reemplazó otro.'],
                ['tipo' => 'habilitacion', 'vence_en' => self::dentroDe('+1 year'), 'estado' => 'pendiente', 'motivo' => null],
                ['tipo' => 'seguro', 'vence_en' => self::dentroDe('+1 year'), 'estado' => 'pendiente', 'motivo' => null],
            ],
            array_map(static fn (array $fila): array => array_diff_key($fila, ['solicitud_id' => true]), $this->renovaciones()),
        );
    }

    /**
     * @return iterable<string, array{array<string, string>, ?string, string, string}>
     */
    public static function renovacionesInvalidas(): iterable
    {
        yield 'un ejecutable' => [[], 'programa.pdf', 'documento', 'El seguro tiene que ser un PDF.'];
        yield 'otro tipo' => [['tipo' => 'poliza'], null, 'tipo', 'Elegí si es el seguro o la habilitación.'];
        yield 'ya vencido' => [['tipo' => 'habilitacion', 'vence' => self::dentroDe('-1 day')], null, 'vence', 'La habilitación tiene que vencer después de hoy.'];
        yield 'sin vencimiento' => [['vence' => ''], null, 'vence', 'Falta la fecha de vencimiento del seguro.'];
    }

    /**
     * @param array<string, string> $cambios
     */
    #[DataProvider('renovacionesInvalidas')]
    public function testRechazaLoQueNoVaComoEnLaSolicitud(array $cambios, ?string $archivo, string $campo, string $mensaje): void
    {
        $respuesta = $this->subir($cambios, $archivo === null ? null : Documentos::subida($archivo));

        self::assertSame(422, $respuesta->estado());
        self::assertSame(["#{$campo}" => $mensaje], Formularios::resumen($respuesta->cuerpo())['enlaces'] ?? null);
        self::assertSame([], $this->renovaciones());
        self::assertSame([], glob("{$this->carpeta}/documentos/*") ?: []);
    }

    public function testSinElPdfNoSeSube(): void
    {
        $respuesta = $this->pedir('POST', cuerpo: ['_csrf' => self::CSRF, 'tipo' => 'seguro', 'vence' => self::dentroDe('+1 year')]);

        self::assertSame(422, $respuesta->estado());
        self::assertSame(['#documento' => 'Falta el PDF del seguro.'], Formularios::resumen($respuesta->cuerpo())['enlaces'] ?? null);
    }

    public function testElOrganizadorBajaSuRenovacionYOtroNo(): void
    {
        $this->subir();
        $id = (string) $this->base->valor('SELECT id FROM documento');

        $suya = $this->pedir(url: "/documentos/{$id}");
        self::assertSame(200, $suya->estado());
        self::assertSame(file_get_contents(Documentos::ruta('poliza.pdf')), $suya->cuerpo());
        self::assertSame(404, $this->pedir(url: "/documentos/{$id}", usuario: self::OTRO)->estado());
        self::assertSame(200, $this->pedir(url: "/documentos/{$id}", rol: 'moderador', usuario: self::OTRO)->estado());
    }
}
