<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use Dom\HTMLDocument;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use IndieCinema\Programacion\Controladores\FormularioDeSolicitud;
use IndieCinema\Programacion\Pruebas\Controladores\FormularioDeSalaTest;
use IndieCinema\Programacion\Pruebas\Dobles\Imagenes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La solicitud de sala de punta a punta: la aplicación entera con las rutas de programación, las
 * plantillas del paquete front, un MySQL de verdad y un servidor SMTP de mentira, que recibe el
 * aviso al equipo. En desarrollo, para que Twig falle si la plantilla usa una variable que el
 * controlador no le pasa.
 */
final class SolicitudDeSalaTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTRO = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const EQUIPO = 'equipo@indiecinema.test';
    private const URL = '/solicitar-sala';

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
        array_map('unlink', glob("{$this->carpeta}/salas/*") ?: []);
        @rmdir("{$this->carpeta}/salas");
        @rmdir($this->carpeta);
    }

    /**
     * @param ?string $servidorDeCorreo por defecto, el de mentira
     */
    private function configuracion(?string $servidorDeCorreo = null): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            // Mandarla no llama a cuentas (eso es al aprobarla), pero el servicio tiene el cliente.
            'subsistemas' => ['cuentas' => 'http://127.0.0.1:1'],
            'interno' => ['token' => 'token-de-programacion'],
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
        $aplicacion = new Aplicacion(dirname(__DIR__), $configuracion ?? $this->configuracion(), new Log('programacion', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF], $archivos));
    }

    /**
     * @return array<string, string> lo que manda el formulario completo, sin la foto
     */
    private static function camposValidos(): array
    {
        return ['_csrf' => self::CSRF, 'habilitacion' => '1', 'derechos' => '1'] + FormularioDeSalaTest::camposValidos();
    }

    /**
     * @param array<string, string> $cambios
     * @param ?string               $imagen  de tests/Dobles/imagenes, o null para no mandar ninguna
     */
    private function enviar(array $cambios = [], ?string $rol = 'espectador', ?string $imagen = 'sala.png', string $usuario = self::ESPECTADORA, ?Configuracion $configuracion = null): Respuesta
    {
        $archivos = $imagen === null ? [] : ['imagen' => Imagenes::subida($imagen)];

        return $this->pedir('POST', $cambios + self::camposValidos(), $rol, $archivos, $usuario, configuracion: $configuracion);
    }

    /**
     * @return list<string> las imágenes guardadas en el volumen
     */
    private function imagenesGuardadas(): array
    {
        return array_map('basename', glob("{$this->carpeta}/salas/*") ?: []);
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

    public function testMuestraElFormularioConElTokenYParaSubirLaFoto(): void
    {
        $respuesta = $this->pedir();

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('<form action="/solicitar-sala" method="post" enctype="multipart/form-data">', $respuesta->cuerpo());
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
        self::assertStringContainsString('Revisá los campos marcados.', $respuesta->cuerpo());
        self::assertMatchesRegularExpression('#id="error-' . $campo . '"[^>]*>.*' . preg_quote($mensaje, '#') . '#s', $respuesta->cuerpo());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sala'));
        self::assertSame([], $this->imagenesGuardadas());
        self::assertSame([], self::$correo->correos());
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
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sala'));
        self::assertSame([], $this->imagenesGuardadas());
    }

    public function testSinElTokenCsrfOSinSesionNoSeManda(): void
    {
        self::assertSame(403, $this->enviar(['_csrf' => str_repeat('d', 64)])->estado());
        self::assertSame(401, $this->pedir(rol: null)->estado());
        self::assertSame(401, $this->enviar(rol: null)->estado());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sala'));
    }

    public function testConTodoBienGuardaLaSalaSinPublicarConSuSolicitudYAvisaAlEquipo(): void
    {
        $respuesta = $this->enviar(['habilitada' => '1', 'organizador_id' => self::OTRO]);

        // 303: recargar no la vuelve a mandar.
        self::assertSame(303, $respuesta->estado());
        self::assertSame('/solicitar-sala?enviada=1', $respuesta->cabecera('Location'));
        // La sala, sin publicar y de quien la mandó, aunque el POST diga otra cosa.
        $sala = $this->base->fila('SELECT id, organizador_id, nombre, capacidad, imagen, habilitada FROM sala');
        self::assertSame(['organizador_id' => self::ESPECTADORA, 'nombre' => 'Cine Club El Galpón', 'capacidad' => 60, 'habilitada' => 0], array_diff_key($sala ?? [], ['id' => true, 'imagen' => true]));
        // La foto, en el volumen con un nombre al azar.
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', (string) $sala['imagen']);
        self::assertSame([$sala['imagen']], $this->imagenesGuardadas());
        // La solicitud, pendiente y con el nombre de quien la mandó.
        self::assertSame(
            ['sala_id' => $sala['id'], 'usuario_id' => self::ESPECTADORA, 'solicitante_nombre' => 'Eva Prueba', 'estado' => 'pendiente'],
            $this->base->fila('SELECT sala_id, usuario_id, solicitante_nombre, estado FROM solicitud_sala'),
        );
        // Le llegó al equipo, a la dirección de la configuración.
        self::assertSame(['<' . self::EQUIPO . '>'], self::$correo->correos()[0]['para']);

        $aviso = $this->pedir(url: '/solicitar-sala?enviada=1');
        self::assertStringContainsString('Solicitud enviada', $aviso->cuerpo());
        self::assertStringContainsString('En revisión', $aviso->cuerpo());
        self::assertStringNotContainsString('action="/solicitar-sala"', $aviso->cuerpo());
    }

    public function testElCorreoAlEquipoTraeLaSala(): void
    {
        $this->enviar();

        $texto = self::textoDelCorreo();
        foreach ([
            'Eva Prueba pidió abrir una sala en IndieCinema.',
            'Sala: Cine Club El Galpón',
            'Dirección: Av. San Martín 450, Luján',
            'Capacidad: 60 lugares',
            "Un galpón con proyector.\r\nSesenta butacas.",
            '/administracion/solicitudes',
        ] as $parte) {
            self::assertStringContainsString($parte, $texto);
        }
    }

    public function testUnOrganizadorTambienLaMandaParaSumarOtraSala(): void
    {
        self::assertSame(303, $this->enviar(rol: 'organizador')->estado());
        self::assertSame(1, $this->base->valor("SELECT COUNT(*) FROM solicitud_sala WHERE estado = 'pendiente'"));
    }

    public function testNoSePuedeMandarOtraMientrasEstaPendiente(): void
    {
        $this->enviar();
        $otra = $this->enviar(['nombre' => 'Otra sala']);

        self::assertSame(409, $otra->estado());
        self::assertStringContainsString('En revisión', $otra->cuerpo());
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM sala'));
        self::assertCount(1, self::$correo->correos());
    }

    public function testSiLaRechazaronMuestraElMotivoYSeCorrigeLaMismaSala(): void
    {
        $this->enviar();
        $sala = (string) $this->base->valor('SELECT id FROM sala');
        $foto = (string) $this->base->valor('SELECT imagen FROM sala');
        $this->base->ejecutar("UPDATE solicitud_sala SET estado = 'rechazada', motivo = 'Falta <b>la habilitación</b>.', resuelta_en = NOW()");

        $pantalla = $this->pedir()->cuerpo();
        self::assertStringContainsString('Requiere corrección', $pantalla);
        self::assertStringContainsString('Falta &lt;b&gt;la habilitación&lt;/b&gt;.', $pantalla);
        // El formulario trae la sala, y la foto ya no es obligatoria.
        self::assertStringContainsString('value="Cine Club El Galpón"', $pantalla);
        self::assertStringContainsString('Volver a enviar la solicitud', $pantalla);
        self::assertDoesNotMatchRegularExpression('/name="imagen"[^>]*required/', $pantalla);

        self::$correo->vaciar();
        self::assertSame(303, $this->enviar(['nombre' => 'El Galpón Habilitado'], imagen: null)->estado());
        // La misma sala, corregida y con su foto, y una solicitud nueva.
        self::assertSame([['id' => $sala, 'nombre' => 'El Galpón Habilitado', 'imagen' => $foto]], $this->base->filas('SELECT id, nombre, imagen FROM sala'));
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM solicitud_sala WHERE sala_id = ?', [$sala]));
        self::assertStringContainsString('corrigió la sala que le rechazaron', self::textoDelCorreo());
    }

    public function testAlCorregirConOtraFotoSeBorraLaAnterior(): void
    {
        $this->enviar();
        $this->base->ejecutar("UPDATE solicitud_sala SET estado = 'rechazada', motivo = 'Otra foto, por favor.', resuelta_en = NOW()");

        $this->enviar(imagen: 'sala.webp');

        $foto = (string) $this->base->valor('SELECT imagen FROM sala');
        self::assertStringEndsWith('.webp', $foto);
        self::assertSame([$foto], $this->imagenesGuardadas());
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
