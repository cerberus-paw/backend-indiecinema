<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use Dom\HTMLDocument;
use IndieCinema\Moderacion\Controladores\FormularioDeSolicitud;
use DateTimeImmutable;
use IndieCinema\Moderacion\Pruebas\Dobles\Documentos;
use IndieCinema\Moderacion\Pruebas\Dobles\Imagenes;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\Formularios;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La solicitud de sala de punta a punta, ya en moderación (EF-07.2): la aplicación entera con las
 * rutas de moderación, las plantillas del paquete front, un MySQL de verdad y un servidor SMTP de
 * mentira, que recibe el aviso al equipo. Mandarla no toca programación: la sala recién existe allá
 * cuando se aprueba. En desarrollo, para que Twig falle si la plantilla usa una variable que el
 * controlador no le pasa.
 */
final class SolicitudDeSalaTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTRO = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const ADMINISTRADOR = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const EQUIPO = 'equipo@indiecinema.test';
    private const URL = '/solicitar-sala';
    /** Los dos PDF que pide la solicitud (EF-07.4), de tests/Dobles/documentos. */
    private const DOCUMENTOS = ['seguro' => 'poliza.pdf', 'habilitacion_pdf' => 'habilitacion.pdf'];

    private static ServidorSmtpDePrueba $correo;

    private BaseDeDatos $base;
    private string $carpeta;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        self::$correo = ServidorSmtpDePrueba::iniciar();
    }

    public static function tearDownAfterClass(): void
    {
        self::$correo->detener();
    }

    protected function setUp(): void
    {
        // Antes de saltearla: tearDown() corre igual y la usa.
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        self::$correo->vaciar();
        $this->salidaDelLog = fopen('php://memory', 'w+');
        $this->base = self::baseConTablasNuevas($this->configuracion());
    }

    protected function tearDown(): void
    {
        foreach (['solicitudes', 'documentos'] as $carpeta) {
            array_map('unlink', glob("{$this->carpeta}/{$carpeta}/*") ?: []);
            @rmdir("{$this->carpeta}/{$carpeta}");
        }
        @rmdir($this->carpeta);
    }

    /**
     * @param ?string $servidorDeCorreo por defecto, el de mentira
     */
    private function configuracion(?string $servidorDeCorreo = null): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'moderacion', 'prefijo' => '/moderacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            // Mandarla no llama a nadie (eso es al aprobarla), pero el servicio tiene el cliente.
            'subsistemas' => ['cuentas' => 'http://127.0.0.1:1', 'programacion' => 'http://127.0.0.1:1'],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => $servidorDeCorreo ?? self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
            'solicitudes' => ['destino' => self::EQUIPO],
        ]);
    }

    /**
     * Como llega de nginx: con un rol, con las cabeceras de la sesión.
     *
     * @param array<string, mixed>                $cuerpo
     * @param array<string, array<string, mixed>> $archivos
     */
    private function pedir(string $metodo = 'GET', array $cuerpo = [], ?string $rol = 'espectador', array $archivos = [], string $usuario = self::ESPECTADORA, string $url = self::URL, ?Configuracion $configuracion = null): Respuesta
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $cabeceras = $rol === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $usuario,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Eva Prueba'),
        ];
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion ?? $this->configuracion(), new Log('moderacion', $this->salidaDelLog));
        $respuesta = $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF], $archivos));

        // Las páginas, para el validador del W3C; la foto no.
        return str_contains($url, '/foto') || str_contains($url, '/documentos/') ? $respuesta : PaginasParaValidar::anotar($metodo, $url, $respuesta);
    }

    /**
     * @return array<string, string> lo que manda el formulario completo, sin la foto
     */
    private static function camposValidos(): array
    {
        return [
            '_csrf' => self::CSRF,
            'habilitacion' => '1',
            'derechos' => '1',
            'nombre' => 'Cine Club El Galpón',
            'descripcion' => "Un galpón con proyector.\nSesenta butacas.",
            'direccion' => 'Av. San Martín 450',
            'localidad' => 'Luján',
            'capacidad' => '60',
            'peliculas_por_funcion' => '2',
            'duracion_funcion' => '120',
            'tiempo_entre_funciones' => '0',
            'vence_seguro' => self::dentroDe('+1 year'),
            'vence_habilitacion' => self::dentroDe('+2 years'),
        ];
    }

    /**
     * Una fecha como la manda el campo de fecha, contada desde hoy en la hora del sitio.
     */
    private static function dentroDe(string $cuanto): string
    {
        return new DateTimeImmutable('today')->modify($cuanto)->format('Y-m-d');
    }

    /**
     * @param array<string, string>                                    $cambios
     * @param ?string                                                  $imagen     de tests/Dobles/imagenes, o null para no mandar ninguna
     * @param array<string, string|array<string, mixed>|null>          $documentos campo => de tests/Dobles/documentos, el archivo ya armado, o null para no mandarlo
     */
    private function enviar(array $cambios = [], ?string $rol = 'espectador', ?string $imagen = 'sala.png', string $usuario = self::ESPECTADORA, ?Configuracion $configuracion = null, array $documentos = []): Respuesta
    {
        $archivos = $imagen === null ? [] : ['imagen' => Imagenes::subida($imagen)];
        foreach ($documentos + self::DOCUMENTOS as $campo => $documento) {
            if ($documento !== null) {
                $archivos[$campo] = is_string($documento) ? Documentos::subida($documento) : $documento;
            }
        }

        return $this->pedir('POST', $cambios + self::camposValidos(), $rol, $archivos, $usuario, configuracion: $configuracion);
    }

    /**
     * @return list<string> los PDF guardados en el volumen
     */
    private function documentosGuardados(): array
    {
        return array_map('basename', glob("{$this->carpeta}/documentos/*") ?: []);
    }

    /**
     * @return list<string> las fotos guardadas en el volumen
     */
    private function fotosGuardadas(): array
    {
        return array_map('basename', glob("{$this->carpeta}/solicitudes/*") ?: []);
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    /**
     * El texto del aviso al equipo, ya decodificado.
     */
    private static function textoDelCorreo(): string
    {
        $correos = self::$correo->correos();
        self::assertCount(1, $correos);

        return (string) base64_decode(str_replace("\r\n", '', explode("\r\n\r\n", $correos[0]['mensaje'], 2)[1]), true);
    }

    private function rechazarLaUltima(string $motivo = 'Falta la póliza.'): void
    {
        $this->base->ejecutar("UPDATE solicitud_sala SET estado = 'rechazada', motivo = ?, resuelta_en = NOW()", [$motivo]);
        // Como la bandeja: rechazarla rechaza sus documentos (EF-07.4).
        $this->base->ejecutar("UPDATE documento SET estado = 'rechazado'");
    }

    /**
     * @return list<array<string, mixed>> los documentos de la pendiente, primero el seguro
     */
    private function documentosDeLaPendiente(): array
    {
        return $this->base->filas(
            "SELECT d.tipo, d.archivo, d.vence_en, d.estado FROM documento d JOIN solicitud_sala s ON s.id = d.solicitud_id
             WHERE s.estado = 'pendiente' ORDER BY d.tipo",
        );
    }

    /**
     * EF-07.4: al corregir una rechazada, el PDF que no se vuelve a subir es el que mandó, como la
     * foto. La solicitud nueva tiene sus propios documentos, pendientes, que apuntan al mismo
     * archivo: los de la rechazada quedan rechazados, son el historial.
     */
    public function testAlCorregirSinPdfNuevosQuedanLosQueMando(): void
    {
        $this->enviar();
        $antes = $this->base->filas('SELECT tipo, archivo, vence_en FROM documento ORDER BY tipo');
        $this->rechazarLaUltima('Falta la descripción del acceso.');

        $pantalla = $this->pedir()->cuerpo();
        // Los campos ya no son obligatorios, el vencimiento viene cargado y se ve lo que mandó.
        self::assertDoesNotMatchRegularExpression('/name="seguro"[^>]*required/', $pantalla);
        self::assertDoesNotMatchRegularExpression('/name="habilitacion_pdf"[^>]*required/', $pantalla);
        self::assertMatchesRegularExpression('/name="vence_seguro"[^>]*value="' . $antes[0]['vence_en'] . '"/', $pantalla);
        self::assertStringContainsString('Si no subís otro, queda el que mandaste.', $pantalla);
        self::assertStringContainsString('href="/moderacion/documentos/', $pantalla);

        self::assertSame(303, $this->enviar(imagen: null, documentos: ['seguro' => null, 'habilitacion_pdf' => null], cambios: [
            'vence_seguro' => (string) $antes[0]['vence_en'],
            'vence_habilitacion' => (string) $antes[1]['vence_en'],
        ])->estado());

        self::assertSame(
            array_map(static fn (array $fila): array => $fila + ['estado' => 'pendiente'], $antes),
            $this->documentosDeLaPendiente(),
        );
        self::assertSame(4, $this->base->valor('SELECT COUNT(*) FROM documento'));
        self::assertSame(2, $this->base->valor("SELECT COUNT(*) FROM documento WHERE estado = 'rechazado'"));
        self::assertCount(2, $this->documentosGuardados());
    }

    public function testAlCorregirConOtroSeguroQuedaElNuevoYLaMismaHabilitacion(): void
    {
        $this->enviar();
        $antes = array_column($this->base->filas('SELECT tipo, archivo FROM documento'), 'archivo', 'tipo');
        $this->rechazarLaUltima('La póliza no cubre el espacio.');
        $vence = self::dentroDe('+3 years');

        self::assertSame(303, $this->enviar(['vence_seguro' => $vence], imagen: null, documentos: ['habilitacion_pdf' => null])->estado());

        $despues = $this->documentosDeLaPendiente();
        self::assertNotSame($antes['seguro'], $despues[0]['archivo']);
        self::assertSame($vence, $despues[0]['vence_en']);
        self::assertSame($antes['habilitacion'], $despues[1]['archivo']);
        // Los archivos no se borran: el seguro rechazado sigue en el volumen.
        self::assertCount(3, $this->documentosGuardados());
    }

    /**
     * Una que se mandó antes de que se pidieran los documentos no tiene qué reusar: al corregirla,
     * los dos son obligatorios.
     */
    public function testAlCorregirUnaSinDocumentosLosPide(): void
    {
        $this->enviar();
        $this->base->ejecutar('DELETE FROM documento');
        $this->rechazarLaUltima();

        self::assertMatchesRegularExpression('/name="seguro"[^>]*required/', $this->pedir()->cuerpo());
        $respuesta = $this->enviar(imagen: null, documentos: ['seguro' => null]);

        self::assertSame(422, $respuesta->estado());
        self::assertSame(['#seguro' => 'Falta el PDF del seguro.'], Formularios::resumen($respuesta->cuerpo())['enlaces'] ?? null);
    }

    public function testMuestraElFormularioConElTokenYParaSubirLaFoto(): void
    {
        $respuesta = $this->pedir();

        self::assertSame(200, $respuesta->estado());
        // Sin el > del final: al formulario se le pueden agregar atributos (el enganche de la
        // validación en línea, por ejemplo) y lo que importa es que mande multipart a esta ruta.
        self::assertStringContainsString('<form action="/solicitar-sala" method="post" enctype="multipart/form-data"', $respuesta->cuerpo());
        self::assertStringContainsString('name="_csrf" value="' . self::CSRF . '"', $respuesta->cuerpo());
        self::assertStringNotContainsString('No se pudo enviar la solicitud', $respuesta->cuerpo());
    }

    /**
     * El navegador pide lo mismo que el backend: cada atributo sale de FormularioDeSolicitud::reglas()
     * y el formulario no lleva novalidate.
     */
    public function testElNavegadorValidaConLasReglasDelBackend(): void
    {
        $documento = HTMLDocument::createFromString($this->pedir()->cuerpo(), LIBXML_NOERROR);
        $formulario = $documento->querySelector('form[action="/solicitar-sala"]');
        self::assertNotNull($formulario);
        self::assertFalse($formulario->hasAttribute('novalidate'));

        $atributos = ['maximo' => 'maxlength', 'valor_minimo' => 'min', 'valor_maximo' => 'max', 'aceptar' => 'accept'];
        foreach (FormularioDeSolicitud::reglas(imagenObligatoria: true) as $campo => $reglas) {
            $control = $formulario->querySelector("[name=\"{$campo}\"]");
            self::assertNotNull($control, $campo);
            self::assertSame($reglas['requerido'], $control->hasAttribute('required'), $campo);
            foreach ($atributos as $regla => $atributo) {
                self::assertSame(isset($reglas[$regla]) ? (string) $reglas[$regla] : null, $control->getAttribute($atributo), "{$campo}: {$atributo}");
            }
        }
    }

    /**
     * La foto y los dos PDF se pueden arrastrar a su zona (EF-07.4), cada una con su texto. Sin
     * JavaScript, son campos de archivo comunes.
     */
    public function testLaFotoYLosPdfSeArrastranASuZona(): void
    {
        $documento = HTMLDocument::createFromString($this->pedir()->cuerpo(), LIBXML_NOERROR);

        foreach (['imagen' => 'Arrastrá la imagen acá, o elegila:', 'seguro' => 'Arrastrá el PDF acá, o elegilo:', 'habilitacion_pdf' => 'Arrastrá el PDF acá, o elegilo:'] as $campo => $texto) {
            $control = $documento->querySelector("input[type=\"file\"][name=\"{$campo}\"]");
            self::assertNotNull($control, $campo);
            self::assertSame($texto, $control->getAttribute('data-zona-de-archivo'), $campo);
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function camposInvalidos(): iterable
    {
        yield 'sin nombre' => [['nombre' => ''], 'nombre', 'Falta el nombre.'];
        yield 'nombre largo' => [['nombre' => str_repeat('a', 101)], 'nombre', 'El nombre puede tener hasta 100 caracteres.'];
        yield 'capacidad 0' => [['capacidad' => '0'], 'capacidad', 'La capacidad tiene que ser un número de butacas entre 1 y 2000.'];
        yield 'sin declarar la habilitación' => [['habilitacion' => ''], 'habilitacion', 'Tenés que declarar que el espacio está habilitado.'];
        yield 'sin el compromiso de los derechos' => [['derechos' => ''], 'derechos', 'Tenés que comprometerte a tener los derechos de exhibición.'];
    }

    /**
     * Con la validación del navegador apagada, o con el POST armado a mano, el servidor rechaza
     * los mismos datos, lo dice junto a cada campo y no guarda ni manda nada.
     *
     * @param array<string, string> $cambios
     */
    #[DataProvider('camposInvalidos')]
    public function testElServidorRechazaLoMismoQueElNavegador(array $cambios, string $campo, string $mensaje): void
    {
        $respuesta = $this->enviar($cambios);

        self::assertSame(422, $respuesta->estado());
        // Arriba, el resumen con un link al campo y el mismo mensaje (E4-10.5).
        self::assertSame(["#{$campo}" => $mensaje], Formularios::resumen($respuesta->cuerpo())['enlaces'] ?? null);
        self::assertMatchesRegularExpression('#id="error-' . $campo . '"[^>]*>.*' . preg_quote($mensaje, '#') . '#s', $respuesta->cuerpo());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM solicitud_sala'));
        self::assertSame([], $this->fotosGuardadas());
        self::assertSame([], self::$correo->correos());
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, mixed>, string, string}>
     */
    public static function documentosInvalidos(): iterable
    {
        yield 'sin el seguro' => [[], ['seguro' => null], 'seguro', 'Falta el PDF del seguro.'];
        yield 'sin la habilitación' => [[], ['habilitacion_pdf' => null], 'habilitacion_pdf', 'Falta el PDF de la habilitación.'];
        yield 'un ejecutable con extensión .pdf' => [[], ['seguro' => 'programa.pdf'], 'seguro', 'El seguro tiene que ser un PDF.'];
        yield 'una foto en lugar del PDF' => [[], ['habilitacion_pdf' => Imagenes::subida('sala.png', 'habilitacion.pdf')], 'habilitacion_pdf', 'La habilitación tiene que ser un PDF.'];
        yield 'uno que PHP cortó por grande' => [[], ['seguro' => Documentos::cortadoPorPhp()], 'seguro', 'El PDF del seguro puede pesar hasta 5 MB.'];
        yield 'uno de más de 5 MB' => [[], ['habilitacion_pdf' => Documentos::grande()], 'habilitacion_pdf', 'El PDF de la habilitación puede pesar hasta 5 MB.'];
        yield 'sin vencimiento' => [['vence_seguro' => ''], [], 'vence_seguro', 'Falta la fecha de vencimiento del seguro.'];
        yield 'un vencimiento que no es fecha' => [['vence_habilitacion' => '2027-02-30'], [], 'vence_habilitacion', 'Falta la fecha de vencimiento de la habilitación.'];
        yield 'que vence hoy' => [['vence_seguro' => self::dentroDe('today')], [], 'vence_seguro', 'El seguro tiene que vencer después de hoy.'];
        yield 'ya vencido' => [['vence_habilitacion' => self::dentroDe('-1 day')], [], 'vence_habilitacion', 'La habilitación tiene que vencer después de hoy.'];
        yield 'a más de 10 años' => [['vence_seguro' => self::dentroDe('+10 years +1 day')], [], 'vence_seguro', 'El vencimiento del seguro no puede pasar de 10 años: revisá el año.'];
    }

    /**
     * EF-07.4: la solicitud lleva el seguro y la habilitación en PDF, cada uno con su vencimiento.
     * El PDF se juzga por su contenido, no por la extensión ni por lo que dice el navegador.
     *
     * @param array<string, string> $cambios
     * @param array<string, mixed>  $documentos
     */
    #[DataProvider('documentosInvalidos')]
    public function testRechazaLosDocumentosQueNoVan(array $cambios, array $documentos, string $campo, string $mensaje): void
    {
        $respuesta = $this->enviar($cambios, documentos: $documentos);

        self::assertSame(422, $respuesta->estado());
        self::assertSame(["#{$campo}" => $mensaje], Formularios::resumen($respuesta->cuerpo())['enlaces'] ?? null);
        self::assertMatchesRegularExpression('#id="error-' . $campo . '"[^>]*>.*' . preg_quote($mensaje, '#') . '#s', $respuesta->cuerpo());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM solicitud_sala'));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM documento'));
        self::assertSame([], $this->documentosGuardados());
        self::assertSame([], $this->fotosGuardadas());
    }

    public function testElVencimientoVuelveAlFormularioSiOtroCampoFalla(): void
    {
        $vence = self::dentroDe('+1 year');

        $cuerpo = $this->enviar(['nombre' => '', 'vence_seguro' => $vence])->cuerpo();

        self::assertMatchesRegularExpression('/name="vence_seguro"[^>]*value="' . $vence . '"/', $cuerpo);
        self::assertSame([], $this->documentosGuardados());
    }

    public function testGuardaLosDosDocumentosPendientesConSuVencimiento(): void
    {
        self::assertSame(303, $this->enviar()->estado());

        $solicitud = $this->base->fila('SELECT id, sala_id FROM solicitud_sala') ?? [];
        $documentos = $this->base->filas('SELECT sala_id, solicitud_id, tipo, archivo, vence_en, estado FROM documento ORDER BY tipo');
        self::assertCount(2, $documentos);
        self::assertSame(
            [
                ['sala_id' => $solicitud['sala_id'], 'solicitud_id' => $solicitud['id'], 'tipo' => 'seguro', 'vence_en' => self::dentroDe('+1 year'), 'estado' => 'pendiente'],
                ['sala_id' => $solicitud['sala_id'], 'solicitud_id' => $solicitud['id'], 'tipo' => 'habilitacion', 'vence_en' => self::dentroDe('+2 years'), 'estado' => 'pendiente'],
            ],
            array_map(static fn (array $fila): array => array_diff_key($fila, ['archivo' => true]), $documentos),
        );
        // En el volumen de moderación, con un nombre al azar, el contenido tal cual y sin permisos
        // para otros.
        $guardados = $this->documentosGuardados();
        sort($guardados);
        $archivos = array_column($documentos, 'archivo');
        sort($archivos);
        self::assertSame($archivos, $guardados);
        foreach ($documentos as $documento) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', (string) $documento['archivo']);
            self::assertSame(0640, fileperms("{$this->carpeta}/documentos/{$documento['archivo']}") & 0777);
        }
        self::assertFileEquals(Documentos::ruta('poliza.pdf'), "{$this->carpeta}/documentos/{$documentos[0]['archivo']}");
    }

    /**
     * @return array<string, string> tipo => id del documento de la última solicitud
     */
    private function idsDeLosDocumentos(): array
    {
        return array_column($this->base->filas('SELECT tipo, id FROM documento ORDER BY subido_en DESC, tipo'), 'id', 'tipo');
    }

    /**
     * EF-07.4: el PDF lo bajan quien mandó la solicitud y la moderación, como adjunto y sin que el
     * navegador lo pueda tomar por otra cosa. Para los demás no existe.
     */
    public function testElDocumentoLoBajanElSolicitanteYElModerador(): void
    {
        $this->enviar();
        $url = '/documentos/' . $this->idsDeLosDocumentos()['seguro'];

        foreach ([['espectador', self::ESPECTADORA], ['moderador', self::OTRO], ['administrador', self::ADMINISTRADOR]] as [$rol, $usuario]) {
            $respuesta = $this->pedir(rol: $rol, usuario: $usuario, url: $url);
            self::assertSame(200, $respuesta->estado(), $rol);
            self::assertSame(file_get_contents(Documentos::ruta('poliza.pdf')), $respuesta->cuerpo());
            self::assertSame('application/pdf', $respuesta->cabecera('Content-Type'));
            self::assertSame('attachment; filename="seguro.pdf"', $respuesta->cabecera('Content-Disposition'));
            self::assertSame('nosniff', $respuesta->cabecera('X-Content-Type-Options'));
            self::assertSame('private, no-store', $respuesta->cabecera('Cache-Control'));
        }
        self::assertSame('attachment; filename="habilitacion.pdf"', $this->pedir(url: '/documentos/' . $this->idsDeLosDocumentos()['habilitacion'])->cabecera('Content-Disposition'));

        self::assertSame(404, $this->pedir(usuario: self::OTRO, url: $url)->estado());
        self::assertSame(404, $this->pedir(rol: 'organizador', usuario: self::OTRO, url: $url)->estado());
        self::assertSame(404, $this->pedir(url: '/documentos/0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f')->estado());
        self::assertSame(404, $this->pedir(url: '/documentos/no-es-un-id')->estado());
        self::assertSame(401, $this->pedir(rol: null, url: $url)->estado());
    }

    public function testElEstadoDeLaSolicitudMuestraLosDocumentos(): void
    {
        $this->enviar();
        $ids = $this->idsDeLosDocumentos();

        $cuerpo = $this->pedir()->cuerpo();

        self::assertStringContainsString("href=\"/moderacion/documentos/{$ids['seguro']}\"", $cuerpo);
        self::assertStringContainsString("href=\"/moderacion/documentos/{$ids['habilitacion']}\"", $cuerpo);
        self::assertStringContainsString('vence el ' . new DateTimeImmutable(self::dentroDe('+1 year'))->format('d/m/Y'), $cuerpo);
        self::assertStringContainsString('vence el ' . new DateTimeImmutable(self::dentroDe('+2 years'))->format('d/m/Y'), $cuerpo);
    }

    public function testConErroresVuelveConLoCargadoYEscapado(): void
    {
        $cuerpo = $this->enviar(['nombre' => '<script>alert(1)</script>', 'capacidad' => '0', 'derechos' => ''])->cuerpo();

        // Lo que escribió vuelve escapado: Twig no deja pasar HTML.
        self::assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;"', $cuerpo);
        self::assertStringNotContainsString('<script>alert(1)', $cuerpo);
        self::assertMatchesRegularExpression('/name="habilitacion"[^>]*checked/', $cuerpo);
        self::assertDoesNotMatchRegularExpression('/name="derechos"[^>]*checked/', $cuerpo);
    }

    public function testUnaSalaNuevaNecesitaUnaFotoDeVerdad(): void
    {
        self::assertStringContainsString('Falta la foto de la sala.', $this->enviar(imagen: null)->cuerpo());
        self::assertStringContainsString('La foto tiene que ser JPG, PNG o WebP.', $this->enviar(imagen: 'documento.jpg')->cuerpo());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM solicitud_sala'));
        self::assertSame([], $this->fotosGuardadas());
    }

    public function testSinElTokenCsrfOSinSesionNoSeManda(): void
    {
        self::assertSame(403, $this->enviar(['_csrf' => str_repeat('d', 64)])->estado());
        self::assertSame(401, $this->pedir(rol: null)->estado());
        self::assertSame(401, $this->enviar(rol: null)->estado());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM solicitud_sala'));
    }

    public function testConTodoBienGuardaLaSolicitudConLaSalaPropuestaYAvisaAlEquipo(): void
    {
        $respuesta = $this->enviar(['usuario_id' => self::OTRO, 'sala_id' => self::OTRO]);

        // 303: recargar no la vuelve a mandar. Sin el prefijo de moderación: la dirección pública
        // es la de siempre.
        self::assertSame(303, $respuesta->estado());
        self::assertSame('/solicitar-sala?enviada=1', $respuesta->cabecera('Location'));
        // La solicitud, pendiente, de quien la mandó aunque el POST diga otra cosa, con la sala
        // propuesta y un id nuevo para ella.
        $solicitud = $this->base->fila('SELECT sala_id, usuario_id, solicitante_nombre, estado, nombre, localidad, capacidad, tiempo_entre_funciones, imagen FROM solicitud_sala') ?? [];
        self::assertSame(
            ['usuario_id' => self::ESPECTADORA, 'solicitante_nombre' => 'Eva Prueba', 'estado' => 'pendiente', 'nombre' => 'Cine Club El Galpón', 'localidad' => 'Luján', 'capacidad' => 60, 'tiempo_entre_funciones' => 0],
            array_diff_key($solicitud, ['sala_id' => true, 'imagen' => true]),
        );
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $solicitud['sala_id']);
        self::assertNotSame(self::OTRO, $solicitud['sala_id']);
        // La foto, en el volumen de moderación con un nombre al azar.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', (string) $solicitud['imagen']);
        self::assertSame([$solicitud['imagen']], $this->fotosGuardadas());
        // Le llegó al equipo, a la dirección de la configuración.
        self::assertSame(['<' . self::EQUIPO . '>'], self::$correo->correos()[0]['para']);

        $aviso = $this->pedir(url: '/solicitar-sala?enviada=1');
        self::assertStringContainsString('Solicitud enviada', $aviso->cuerpo());
        self::assertStringContainsString('En revisión', $aviso->cuerpo());
        self::assertStringNotContainsString('action="/solicitar-sala"', $aviso->cuerpo());
    }

    public function testElCorreoAlEquipoTraeLaSalaYLaBandejaDeModeracion(): void
    {
        $this->enviar();

        $texto = self::textoDelCorreo();
        foreach ([
            'Eva Prueba pidió abrir una sala en IndieCinema.',
            'Sala: Cine Club El Galpón',
            'Dirección: Av. San Martín 450, Luján',
            'Capacidad: 60 lugares',
            "Un galpón con proyector.\r\nSesenta butacas.",
            '/moderacion/solicitudes',
        ] as $parte) {
            self::assertStringContainsString($parte, $texto);
        }
    }

    public function testUnOrganizadorTambienLaMandaParaSumarOtraSala(): void
    {
        $this->enviar(rol: 'organizador');
        $this->base->ejecutar("UPDATE solicitud_sala SET estado = 'aprobada', motivo = 'Bien.', resuelta_en = NOW()");

        self::assertSame(303, $this->enviar(['nombre' => 'El Microcine'], rol: 'organizador')->estado());

        // Otra sala, con su propio id.
        self::assertSame(2, $this->base->valor('SELECT COUNT(DISTINCT sala_id) FROM solicitud_sala'));
    }

    public function testNoSePuedeMandarOtraMientrasEstaPendiente(): void
    {
        $this->enviar();
        $otra = $this->enviar(['nombre' => 'Otra sala']);

        self::assertSame(409, $otra->estado());
        self::assertStringContainsString('En revisión', $otra->cuerpo());
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM solicitud_sala'));
        self::assertCount(1, self::$correo->correos());
    }

    public function testSiLaRechazaronMuestraElMotivoYSeCorrigeLaMismaSala(): void
    {
        $this->enviar();
        $sala = (string) $this->base->valor('SELECT sala_id FROM solicitud_sala');
        $foto = (string) $this->base->valor('SELECT imagen FROM solicitud_sala');
        $this->rechazarLaUltima('Falta <b>la habilitación</b>.');

        $pantalla = $this->pedir()->cuerpo();
        self::assertStringContainsString('Requiere corrección', $pantalla);
        self::assertStringContainsString('Falta &lt;b&gt;la habilitación&lt;/b&gt;.', $pantalla);
        // El formulario trae la sala, y la foto ya no es obligatoria.
        self::assertStringContainsString('value="Cine Club El Galpón"', $pantalla);
        self::assertStringContainsString('Volver a enviar la solicitud', $pantalla);
        self::assertDoesNotMatchRegularExpression('/name="imagen"[^>]*required/', $pantalla);

        self::$correo->vaciar();
        self::assertSame(303, $this->enviar(['nombre' => 'El Galpón Habilitado'], imagen: null)->estado());
        // Una solicitud nueva de la misma sala, corregida y con la foto de la anterior.
        self::assertSame(
            ['sala_id' => $sala, 'nombre' => 'El Galpón Habilitado', 'imagen' => $foto],
            $this->base->fila("SELECT sala_id, nombre, imagen FROM solicitud_sala WHERE estado = 'pendiente'"),
        );
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM solicitud_sala WHERE sala_id = ?', [$sala]));
        self::assertStringContainsString('corrigió la sala que le rechazaron', self::textoDelCorreo());
    }

    /**
     * Las fotos son el historial de lo que se pidió: la de la rechazada queda.
     */
    public function testAlCorregirConOtraFotoQuedanLasDos(): void
    {
        $this->enviar();
        $this->rechazarLaUltima('Otra foto, por favor.');

        $this->enviar(imagen: 'sala.webp');

        $foto = (string) $this->base->valor("SELECT imagen FROM solicitud_sala WHERE estado = 'pendiente'");
        self::assertStringEndsWith('.webp', $foto);
        self::assertCount(2, $this->fotosGuardadas());
        self::assertContains($foto, $this->fotosGuardadas());
    }

    /**
     * La foto la ven quien mandó la solicitud, el moderador y el administrador; para los demás no
     * existe.
     */
    public function testLaFotoLaVenElSolicitanteElModeradorYElAdministrador(): void
    {
        $this->enviar();
        $id = (string) $this->base->valor('SELECT id FROM solicitud_sala');
        $url = "/solicitudes/{$id}/foto";

        $suya = $this->pedir(url: $url);
        self::assertSame(200, $suya->estado());
        self::assertSame(file_get_contents(Imagenes::ruta('sala.png')), $suya->cuerpo());
        self::assertSame('image/png', $suya->cabecera('Content-Type'));
        self::assertSame(200, $this->pedir(rol: 'administrador', usuario: self::ADMINISTRADOR, url: $url)->estado());
        self::assertSame(200, $this->pedir(rol: 'moderador', usuario: self::OTRO, url: $url)->estado());
        self::assertSame(404, $this->pedir(usuario: self::OTRO, url: $url)->estado());
        self::assertSame(404, $this->pedir(url: '/solicitudes/no-es-un-id/foto')->estado());
        self::assertSame(401, $this->pedir(rol: null, url: $url)->estado());

        // Al corregirla, la pantalla la muestra con la dirección de moderación.
        $this->rechazarLaUltima();
        self::assertStringContainsString("src=\"/moderacion/solicitudes/{$id}/foto\"", $this->pedir()->cuerpo());
    }

    /**
     * Una solicitud que se mudó de programación no tiene foto propia: se ve la de su sala allá,
     * que su organizador puede ver aunque no esté publicada.
     */
    public function testUnaMudadaSinFotoMuestraLaDeSuSala(): void
    {
        $this->enviar();
        $sala = (string) $this->base->valor('SELECT sala_id FROM solicitud_sala');
        $this->base->ejecutar('UPDATE solicitud_sala SET imagen = NULL');
        $this->rechazarLaUltima();

        self::assertStringContainsString("src=\"/salas/{$sala}/imagen\"", $this->pedir()->cuerpo());
    }

    /**
     * El correo es el aviso: si no sale, la solicitud queda igual y el administrador la ve en su
     * bandeja.
     */
    public function testSiFallaElCorreoLaSolicitudQuedaGuardada(): void
    {
        // En el puerto 1 no escucha nadie.
        $respuesta = $this->enviar(configuracion: $this->configuracion('smtp://127.0.0.1:1'));

        self::assertSame(303, $respuesta->estado());
        self::assertSame(1, $this->base->valor("SELECT COUNT(*) FROM solicitud_sala WHERE estado = 'pendiente'"));
        self::assertStringContainsString('quedó guardada, pero no se le avisó al equipo por correo', $this->log());
    }

    /**
     * El log registra quién la mandó y qué sala por sus ids, sin el nombre de la persona.
     */
    public function testElLogNoGuardaDatosPersonales(): void
    {
        $this->enviar();

        self::assertStringContainsString('El usuario ' . self::ESPECTADORA . ' pidió abrir la sala', $this->log());
        self::assertStringNotContainsString('Eva Prueba', $this->log());
    }
}
