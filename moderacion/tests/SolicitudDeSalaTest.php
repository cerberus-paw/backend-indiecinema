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
 * controlador no le pasa. El pedido de cambio de dirección (EF-07.6) lee la sala de una
 * programación de mentira (ConServidoresDePrueba).
 */
final class SolicitudDeSalaTest extends TestCase
{
    use ConBaseDePruebas;
    use ConServidoresDePrueba;

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
        self::iniciarServidores();
    }

    public static function tearDownAfterClass(): void
    {
        self::$correo->detener();
        self::detenerServidores();
    }

    protected function setUp(): void
    {
        // Antes de saltearla: tearDown() corre igual y la usa.
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        self::$correo->vaciar();
        self::reiniciarServidores();
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
            // Mandarla no llama a nadie (eso es al aprobarla); el pedido de cambio de dirección lee
            // la sala de programación.
            'subsistemas' => ['cuentas' => self::$urls['cuentas'], 'programacion' => self::$urls['programacion']],
            'interno' => ['token' => 'token-de-moderacion'],
            'correo' => ['servidor' => $servidorDeCorreo ?? self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
            'solicitudes' => ['destino' => self::EQUIPO, 'plazo_devolucion_dias' => 15, 'recordatorio_devolucion_dias' => 3],
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
    private function enviar(array $cambios = [], ?string $rol = 'espectador', ?string $imagen = 'sala.png', string $usuario = self::ESPECTADORA, ?Configuracion $configuracion = null, array $documentos = [], string $url = self::URL): Respuesta
    {
        $archivos = $imagen === null ? [] : ['imagen' => Imagenes::subida($imagen)];
        foreach ($documentos + self::DOCUMENTOS as $campo => $documento) {
            if ($documento !== null) {
                $archivos[$campo] = is_string($documento) ? Documentos::subida($documento) : $documento;
            }
        }

        return $this->pedir('POST', $cambios + self::camposValidos(), $rol, $archivos, $usuario, $url, $configuracion);
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

    /**
     * EF-07.6: en revisión manual ve por qué, y sigue sin poder mandar otra.
     */
    public function testEnRevisionManualVeElMotivoYNoMandaOtra(): void
    {
        $this->enviar();
        $this->base->ejecutar(
            "UPDATE solicitud_sala SET estado = 'en_revision_manual', revision_motivo = 'Coordinamos una <b>visita</b>.',
                revision_por = ?, revision_en = UTC_TIMESTAMP()",
            [self::ADMINISTRADOR],
        );

        $pantalla = $this->pedir()->cuerpo();
        self::assertStringContainsString('En revisión manual', $pantalla);
        self::assertStringContainsString('Coordinamos una &lt;b&gt;visita&lt;/b&gt;.', $pantalla);
        self::assertStringNotContainsString('name="nombre"', $pantalla);

        $otra = $this->enviar(['nombre' => 'Otra sala']);
        self::assertSame(409, $otra->estado());
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM solicitud_sala'));
    }

    /**
     * EF-07.6: una sala publicada de la espectadora (organizadora, para programación), con todos sus
     * datos.
     */
    private const SALA = '99999999-9999-4999-8999-999999999999';

    private static function salaPublicada(bool $habilitada = true, string $organizador = self::ESPECTADORA): void
    {
        self::salasEnProgramacion([self::SALA => [
            'nombre' => 'El Galpón',
            'localidad' => 'Luján',
            'organizador_id' => $organizador,
            'habilitada' => $habilitada,
            'descripcion' => 'Un galpón con proyector.',
            'direccion' => 'Av. San Martín 450',
            'capacidad' => 60,
            'peliculas_por_funcion' => 2,
            'duracion_funcion' => 120,
            'tiempo_entre_funciones' => 15,
        ]]);
    }

    public function testElPedidoDeCambioDeDireccionVieneConLosDatosDeLaSala(): void
    {
        self::salaPublicada();

        $respuesta = $this->pedir(rol: 'organizador', url: self::URL . '?sala=' . self::SALA);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $pantalla = $respuesta->cuerpo();
        self::assertStringContainsString('Pedir el cambio de dirección de «El Galpón»', $pantalla);
        self::assertStringContainsString('action="/solicitar-sala?sala=' . self::SALA . '"', $pantalla);
        self::assertStringContainsString('value="Av. San Martín 450"', $pantalla);
        self::assertStringContainsString('value="60"', $pantalla);
        // La foto es opcional; el seguro y la habilitación del lugar nuevo, no.
        self::assertDoesNotMatchRegularExpression('/name="imagen"[^>]*required/', $pantalla);
        self::assertMatchesRegularExpression('/name="seguro"[^>]*required/', $pantalla);
        self::assertMatchesRegularExpression('/name="habilitacion_pdf"[^>]*required/', $pantalla);
        self::assertStringContainsString('Enviar el pedido de cambio', $pantalla);
    }

    /**
     * @return iterable<string, array{?callable(): void, string, string}>
     */
    public static function pedidosDeCambioQueNoVan(): iterable
    {
        yield 'de otro organizador' => [static fn () => self::salaPublicada(organizador: self::OTRO), self::ESPECTADORA, self::SALA];
        yield 'sin publicar' => [static fn () => self::salaPublicada(habilitada: false), self::ESPECTADORA, self::SALA];
        yield 'que no existe' => [null, self::ESPECTADORA, self::SALA];
        yield 'sin forma de id' => [null, self::ESPECTADORA, 'no-es-un-id'];
    }

    /**
     * Para los demás, la sala no existe: ni el formulario ni el envío.
     *
     * @param ?callable(): void $preparar
     */
    #[DataProvider('pedidosDeCambioQueNoVan')]
    public function testSoloSuOrganizadorPideElCambioDeUnaSalaPublicada(?callable $preparar, string $usuario, string $sala): void
    {
        if ($preparar !== null) {
            $preparar();
        }

        self::assertSame(404, $this->pedir(rol: 'organizador', usuario: $usuario, url: self::URL . "?sala={$sala}")->estado());
        self::assertSame(404, $this->enviar(['direccion' => 'Mitre 1200'], 'organizador', null, $usuario, url: self::URL . "?sala={$sala}")->estado());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM solicitud_sala'));
    }

    /**
     * Mandarlo deja una solicitud de la misma sala marcada como cambio, con los dos PDF del lugar
     * nuevo; la sala sigue publicada (programación no se entera). Mientras está abierta, no se pide
     * otro.
     */
    public function testElCambioQuedaMarcadoYLaSalaSiguePublicada(): void
    {
        self::salaPublicada();
        $url = self::URL . '?sala=' . self::SALA;

        $respuesta = $this->enviar(['nombre' => 'El Galpón', 'direccion' => 'Mitre 1200', 'localidad' => 'Mercedes'], 'organizador', null, url: $url);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(
            ['sala_id' => self::SALA, 'cambio' => 1, 'estado' => 'pendiente', 'direccion' => 'Mitre 1200', 'localidad' => 'Mercedes', 'imagen' => null],
            $this->base->fila('SELECT sala_id, cambio, estado, direccion, localidad, imagen FROM solicitud_sala'),
        );
        self::assertSame(2, $this->base->valor("SELECT COUNT(*) FROM documento WHERE sala_id = ? AND estado = 'pendiente'", [self::SALA]));
        self::assertSame([], self::pedidosA('programacion'));
        self::assertTrue(self::salasDeProgramacion()[self::SALA]['habilitada']);
        self::assertStringContainsString('pidió cambiar la dirección de la sala', self::textoDelCorreo());
        self::assertStringContainsString('En revisión', $this->pedir(rol: 'organizador')->cuerpo());

        self::assertSame(404, $this->pedir(rol: 'organizador', url: $url)->estado());
    }

    /**
     * Si se lo rechazan, lo corrige en /solicitar-sala como cualquier solicitud, y sigue siendo un
     * pedido de cambio de la misma sala.
     */
    public function testCorregirUnCambioRechazadoSigueSiendoUnCambio(): void
    {
        self::salaPublicada();
        $this->enviar(['direccion' => 'Mitre 1200'], 'organizador', null, url: self::URL . '?sala=' . self::SALA);
        $this->rechazarLaUltima('Falta el número de la calle.');

        self::assertSame(303, $this->enviar(['direccion' => 'Mitre 1250'], 'organizador', null)->estado());

        self::assertSame(
            ['sala_id' => self::SALA, 'cambio' => 1, 'direccion' => 'Mitre 1250'],
            $this->base->fila("SELECT sala_id, cambio, direccion FROM solicitud_sala WHERE estado = 'pendiente'"),
        );
    }

    public function testElCambioPideLosDosPdf(): void
    {
        self::salaPublicada();

        $respuesta = $this->enviar(['direccion' => 'Mitre 1200'], 'organizador', null, documentos: ['seguro' => null], url: self::URL . '?sala=' . self::SALA);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('action="/solicitar-sala?sala=' . self::SALA . '"', $respuesta->cuerpo());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM solicitud_sala'));
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
        // Ni con el nombre con que quedó en el volumen en lugar del id, ni con una ruta armada a
        // mano (EF-18.2).
        $imagen = (string) $this->base->valor('SELECT imagen FROM solicitud_sala WHERE id = ?', [$id]);
        foreach (["/solicitudes/{$imagen}/foto", '/solicitudes/' . rawurlencode("../{$imagen}") . '/foto', '/solicitudes/..%2F..%2Fetc%2Fpasswd/foto'] as $armada) {
            self::assertSame(404, $this->pedir(rol: 'moderador', usuario: self::OTRO, url: $armada)->estado(), $armada);
        }

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

    /** EF-22.10: quien devuelve las solicitudes en estas pruebas, que cuentas conoce. */
    private const MODERADORA = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

    /**
     * EF-22.10: la moderadora le devuelve la última solicitud, por la bandeja.
     *
     * @param list<string> $faltantes
     */
    private function devolverLaUltima(array $faltantes = ['seguro'], string $motivo = 'La póliza es de otra dirección.'): string
    {
        $id = (string) $this->base->valor('SELECT id FROM solicitud_sala ORDER BY enviada_en DESC LIMIT 1');
        $respuesta = $this->pedir('POST', ['_csrf' => self::CSRF, 'resolucion' => 'devolver', 'faltantes' => $faltantes, 'motivo' => $motivo], 'moderador', usuario: self::MODERADORA, url: "/solicitudes/{$id}/resolver");
        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::$correo->vaciar();

        return $id;
    }

    /**
     * EF-22.10: completar la devuelta manda sólo los documentos que faltan.
     *
     * @param array<string, string>                           $campos
     * @param array<string, string|array<string, mixed>|null> $documentos campo => de tests/Dobles/documentos, el archivo ya armado, o null para no mandarlo
     */
    private function completar(array $campos = ['vence_seguro' => '+18 months'], array $documentos = ['seguro' => 'poliza.pdf'], ?Configuracion $configuracion = null): Respuesta
    {
        $archivos = [];
        foreach ($documentos as $campo => $documento) {
            if ($documento !== null) {
                $archivos[$campo] = is_string($documento) ? Documentos::subida($documento) : $documento;
            }
        }
        $fechas = array_map(static fn (string $valor): string => str_starts_with($valor, '+') || str_starts_with($valor, '-') ? self::dentroDe($valor) : $valor, $campos);

        return $this->pedir('POST', ['_csrf' => self::CSRF] + $fechas, archivos: $archivos, configuracion: $configuracion);
    }

    private static function plazo(): string
    {
        return new DateTimeImmutable('today', new \DateTimeZone('America/Argentina/Buenos_Aires'))->modify('+15 days')->format('d/m/Y');
    }

    /**
     * EF-22.10: con la última devuelta, ve el motivo, hasta cuándo tiene y un formulario sólo con
     * los documentos que le pidieron, obligatorios.
     */
    public function testConLaUltimaDevueltaVeElMotivoLaFechaYSoloLoQueFalta(): void
    {
        $this->enviar();
        $this->devolverLaUltima(['seguro']);

        $respuesta = $this->pedir();

        self::assertSame(200, $respuesta->estado());
        $pantalla = $respuesta->cuerpo();
        self::assertStringContainsString('La póliza es de otra dirección.', $pantalla);
        self::assertStringContainsString('Tenés hasta el ' . self::plazo(), $pantalla);
        self::assertStringContainsString('vuelvas a subir el seguro', $pantalla);
        self::assertMatchesRegularExpression('/<input[^>]*name="seguro"[^>]*required/', $pantalla);
        self::assertMatchesRegularExpression('/<input[^>]*name="vence_seguro"[^>]*required/', $pantalla);
        self::assertStringNotContainsString('name="habilitacion_pdf"', $pantalla);
        self::assertStringNotContainsString('name="nombre"', $pantalla);
        self::assertStringNotContainsString('name="imagen"', $pantalla);
        self::assertStringContainsString('Mandar la documentación', $pantalla);
    }

    /**
     * EF-22.10: completarla deja la misma solicitud pendiente, con el seguro nuevo pendiente y el
     * rechazado como historial, y le avisa al equipo.
     */
    public function testCompletarlaVuelveLaMismaSolicitudAPendiente(): void
    {
        $this->enviar();
        $id = $this->devolverLaUltima(['seguro']);

        $respuesta = $this->completar();

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(self::URL . '?enviada=1', $respuesta->cabecera('Location'));
        self::assertSame([['id' => $id, 'estado' => 'pendiente']], $this->base->filas('SELECT id, estado FROM solicitud_sala'));
        self::assertSame(
            [
                ['tipo' => 'seguro', 'vence_en' => self::dentroDe('+1 year'), 'estado' => 'rechazado'],
                ['tipo' => 'seguro', 'vence_en' => self::dentroDe('+18 months'), 'estado' => 'pendiente'],
                ['tipo' => 'habilitacion', 'vence_en' => self::dentroDe('+2 years'), 'estado' => 'pendiente'],
            ],
            $this->base->filas('SELECT tipo, vence_en, estado FROM documento WHERE solicitud_id = ? ORDER BY tipo, vence_en', [$id]),
        );
        self::assertCount(3, $this->documentosGuardados());
        $correos = self::$correo->correos();
        self::assertSame(['<' . self::EQUIPO . '>'], $correos[0]['para']);
        self::assertStringContainsString('completó la documentación', self::textoDelCorreo());
        self::assertStringContainsString("El usuario " . self::ESPECTADORA . " completó la solicitud {$id}", $this->log());
        self::assertStringContainsString('Tu solicitud para «Cine Club El Galpón» está en manos del equipo', $this->pedir(url: self::URL . '?enviada=1')->cuerpo());
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, string|null>, string, string}>
     */
    public static function completadasConErrores(): iterable
    {
        yield 'sin el PDF' => [['vence_seguro' => '+18 months'], ['seguro' => null], 'seguro', 'Falta el PDF del seguro.'];
        yield 'con otro archivo' => [['vence_seguro' => '+18 months'], ['seguro' => 'programa.pdf'], 'seguro', 'El seguro tiene que ser un PDF.'];
        yield 'vencido' => [['vence_seguro' => '-1 day'], ['seguro' => 'poliza.pdf'], 'vence_seguro', 'El seguro tiene que vencer después de hoy.'];
        yield 'sin fecha' => [['vence_seguro' => ''], ['seguro' => 'poliza.pdf'], 'vence_seguro', 'Falta la fecha de vencimiento del seguro.'];
    }

    /**
     * @param array<string, string>      $campos
     * @param array<string, string|null> $documentos
     */
    #[DataProvider('completadasConErrores')]
    public function testCompletarlaConErroresNoCambiaNadaYConservaLoCargado(array $campos, array $documentos, string $campo, string $mensaje): void
    {
        $this->enviar();
        $id = $this->devolverLaUltima(['seguro']);

        $respuesta = $this->completar($campos, $documentos);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString($mensaje, $respuesta->cuerpo());
        self::assertStringContainsString("href=\"#{$campo}\"", $respuesta->cuerpo());
        if ($campo === 'seguro') {
            self::assertMatchesRegularExpression('/name="vence_seguro"[^>]*value="' . self::dentroDe('+18 months') . '"/', $respuesta->cuerpo());
        }
        self::assertSame('devuelta', $this->base->valor('SELECT estado FROM solicitud_sala WHERE id = ?', [$id]));
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM documento'));
        self::assertCount(2, $this->documentosGuardados());
        self::assertSame([], self::$correo->correos());
    }

    /**
     * EF-22.10: los dos documentos que se piden son obligatorios.
     */
    public function testSiFaltanLosDosLosPideALosDos(): void
    {
        $this->enviar();
        $this->devolverLaUltima(['seguro', 'habilitacion']);

        $respuesta = $this->completar(['vence_seguro' => '+1 year', 'vence_habilitacion' => '+1 year'], ['seguro' => 'poliza.pdf']);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Falta el PDF de la habilitación.', $respuesta->cuerpo());

        self::assertSame(303, $this->completar(['vence_seguro' => '+1 year', 'vence_habilitacion' => '+1 year'], ['seguro' => 'poliza.pdf', 'habilitacion_pdf' => 'habilitacion.pdf'])->estado());
        self::assertSame(2, $this->base->valor("SELECT COUNT(*) FROM documento WHERE estado = 'pendiente'"));
    }

    /**
     * EF-22.10: pasada la fecha límite no se completa, aunque la tarea todavía no la haya cerrado.
     */
    public function testPasadaLaFechaLimiteNoSeCompleta(): void
    {
        $this->enviar();
        $id = $this->devolverLaUltima(['seguro']);
        $this->base->ejecutar('UPDATE solicitud_sala SET devolucion_vence = ? WHERE id = ?', [self::dentroDe('-1 day'), $id]);

        $respuesta = $this->completar();

        self::assertSame(409, $respuesta->estado());
        self::assertStringContainsString('Venció el plazo para completarla', $respuesta->cuerpo());
        self::assertSame('devuelta', $this->base->valor('SELECT estado FROM solicitud_sala WHERE id = ?', [$id]));
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM documento'));
        self::assertCount(2, $this->documentosGuardados());
        self::assertSame([], self::$correo->correos());
    }

    public function testElUltimoDiaTodaviaSeCompleta(): void
    {
        $this->enviar();
        $id = $this->devolverLaUltima(['seguro']);
        $hoy = new DateTimeImmutable('today', new \DateTimeZone('America/Argentina/Buenos_Aires'))->format('Y-m-d');
        $this->base->ejecutar('UPDATE solicitud_sala SET devolucion_vence = ? WHERE id = ?', [$hoy, $id]);

        self::assertSame(303, $this->completar()->estado());
    }

    public function testSiFallaElCorreoLaCompletadaQuedaPendiente(): void
    {
        $this->enviar();
        $id = $this->devolverLaUltima(['seguro']);

        $respuesta = $this->completar(configuracion: $this->configuracion('smtp://127.0.0.1:1'));

        self::assertSame(303, $respuesta->estado());
        self::assertSame('pendiente', $this->base->valor('SELECT estado FROM solicitud_sala WHERE id = ?', [$id]));
        self::assertStringContainsString('quedó guardada, pero no se le avisó al equipo por correo', $this->log());
    }

    /**
     * EF-22.10 de punta a punta: la devuelven por el seguro, la completa y la aprueban. La sala se
     * publica con el seguro nuevo aceptado; el rechazado queda como estaba.
     */
    public function testDevolverCompletarYAprobarPublicaLaSalaConElSeguroNuevo(): void
    {
        $this->enviar();
        $id = $this->devolverLaUltima(['seguro']);
        $this->completar();

        $respuesta = $this->pedir('POST', [
            '_csrf' => self::CSRF, 'resolucion' => 'aprobar', 'motivo' => 'Ahora sí.',
            'requisitos' => ['seguro_vigente', 'habilitacion', 'capacidad', 'condiciones'],
        ], 'moderador', usuario: self::MODERADORA, url: "/solicitudes/{$id}/resolver");

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('aprobada', $this->base->valor('SELECT estado FROM solicitud_sala WHERE id = ?', [$id]));
        self::assertSame([(string) $this->base->valor('SELECT sala_id FROM solicitud_sala WHERE id = ?', [$id])], array_keys(self::salasDeProgramacion()));
        self::assertSame(
            [
                ['tipo' => 'seguro', 'vence_en' => self::dentroDe('+1 year'), 'estado' => 'rechazado'],
                ['tipo' => 'seguro', 'vence_en' => self::dentroDe('+18 months'), 'estado' => 'aceptado'],
                ['tipo' => 'habilitacion', 'vence_en' => self::dentroDe('+2 years'), 'estado' => 'aceptado'],
            ],
            $this->base->filas('SELECT tipo, vence_en, estado FROM documento WHERE solicitud_id = ? ORDER BY tipo, vence_en', [$id]),
        );
    }

    /**
     * EF-22.10: como la deja la tarea vencer-devueltas.
     */
    private function vencerLaUltima(): void
    {
        $this->base->ejecutar("UPDATE solicitud_sala SET estado = 'vencida', motivo = 'Venció el plazo para completar la documentación.', resuelta_en = NOW() WHERE estado = 'devuelta'");
    }

    /**
     * EF-22.10: con la última vencida ve el motivo y el formulario vacío. El pedido nuevo empieza
     * de cero: otra sala, sin la foto ni los PDF de la vencida.
     */
    public function testConLaUltimaVencidaVeElMotivoYEmpiezaDeCero(): void
    {
        $this->enviar();
        $vieja = (array) $this->base->fila('SELECT id, sala_id, imagen FROM solicitud_sala');
        $this->devolverLaUltima(['seguro']);
        $this->vencerLaUltima();

        $pantalla = $this->pedir()->cuerpo();

        self::assertStringContainsString('Venció la solicitud de «Cine Club El Galpón»', $pantalla);
        self::assertStringContainsString('Venció el plazo para completar la documentación.', $pantalla);
        self::assertStringContainsString('Podés mandar una solicitud nueva', $pantalla);
        self::assertMatchesRegularExpression('/<input[^>]*name="nombre"[^>]*value=""/', $pantalla);
        self::assertMatchesRegularExpression('/<input[^>]*name="imagen"[^>]*required/', $pantalla);
        self::assertMatchesRegularExpression('/<input[^>]*name="seguro"[^>]*required/', $pantalla);
        self::assertStringNotContainsString('/organizador/salas', $pantalla);

        self::assertSame(422, $this->enviar(imagen: null)->estado(), 'La foto vuelve a ser obligatoria.');
        self::assertSame(303, $this->enviar()->estado());

        $nueva = (array) $this->base->fila("SELECT id, sala_id, imagen FROM solicitud_sala WHERE estado = 'pendiente'");
        self::assertNotSame($vieja['sala_id'], $nueva['sala_id']);
        self::assertNotSame($vieja['imagen'], $nueva['imagen']);
        $archivosViejos = array_column($this->base->filas('SELECT archivo FROM documento WHERE solicitud_id = ?', [$vieja['id']]), 'archivo');
        $archivosNuevos = array_column($this->base->filas('SELECT archivo FROM documento WHERE solicitud_id = ?', [$nueva['id']]), 'archivo');
        self::assertCount(2, $archivosNuevos);
        self::assertSame([], array_intersect($archivosViejos, $archivosNuevos));
    }

    /**
     * EF-22.10: si el vencido era un pedido de cambio de dirección, se pide de nuevo desde «Mis
     * salas».
     */
    public function testConUnCambioVencidoOfreceMisSalas(): void
    {
        $this->enviar();
        $this->base->ejecutar('UPDATE solicitud_sala SET cambio = TRUE');
        $this->devolverLaUltima(['habilitacion']);
        $this->vencerLaUltima();

        $pantalla = $this->pedir()->cuerpo();

        self::assertStringContainsString('Venció el pedido de cambio de dirección de «Cine Club El Galpón»', $pantalla);
        self::assertStringContainsString('href="/organizador/salas"', $pantalla);
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
