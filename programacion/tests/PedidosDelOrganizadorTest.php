<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
use IndieCinema\Nucleo\Pruebas\Dobles\ServidorSmtpDePrueba;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Pruebas\Dobles\Imagenes;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use PHPUnit\Framework\TestCase;

/**
 * «Pedidos para programar» de punta a punta (EF-11.3 / IC-578): prioridad de 48 horas para las
 * salas con promedio >= 4, toma del pedido con una obra existente o desde el alta precargada y
 * aviso por correo a quienes se sumaron.
 */
final class PedidosDelOrganizadorTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTRO_ESPECTADOR = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const OTRO_ORGANIZADOR = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

    /** @var resource|null */
    private static $servidorCuentas = null;

    /** @var resource|null */
    private static $servidorCalificaciones = null;

    private static string $urlCuentas = '';
    private static string $urlCalificaciones = '';
    private static string $archivoCalificaciones;
    private static ServidorSmtpDePrueba $correo;

    private BaseDeDatos $base;
    private string $carpeta;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        self::$correo = ServidorSmtpDePrueba::iniciar();

        self::$archivoCalificaciones = (string) tempnam(sys_get_temp_dir(), 'calif');
        file_put_contents(self::$archivoCalificaciones, '{}');

        self::$servidorCuentas = self::levantarServidor(
            __DIR__ . '/Dobles/cuentas.php',
            ['REGISTRO_CONSULTAS' => ''],
            self::$urlCuentas,
        );
        self::$servidorCalificaciones = self::levantarServidor(
            __DIR__ . '/Dobles/calificaciones.php',
            ['CALIFICACIONES' => self::$archivoCalificaciones],
            self::$urlCalificaciones,
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::$correo->detener();
        foreach ([self::$servidorCuentas, self::$servidorCalificaciones] as $proceso) {
            if ($proceso !== null) {
                proc_terminate($proceso);
                proc_close($proceso);
            }
        }
        @unlink(self::$archivoCalificaciones);
    }

    /**
     * @param array<string, string> $entorno
     *
     * @return resource|null
     */
    private static function levantarServidor(string $script, array $entorno, string &$url): mixed
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $url = "http://127.0.0.1:{$puerto}";

        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", $script],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
            null,
            $entorno + getenv(),
        );

        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return $proceso === false ? null : $proceso;
            }
            usleep(20_000);
        }
        self::fail("No arrancó {$script}.");
    }

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $this->salidaDelLog = fopen('php://memory', 'w+');
        $this->base = self::baseConTablasNuevas($this->configuracion());
        file_put_contents(self::$archivoCalificaciones, '{}');
        self::$correo->vaciar();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->carpeta}/afiches/*") ?: []);
        @rmdir("{$this->carpeta}/afiches");
        @rmdir($this->carpeta);
    }

    private function configuracion(): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => [
                'cuentas' => self::$urlCuentas,
                'funciones' => self::$urlCalificaciones,
            ],
            'interno' => ['token' => 'token-de-programacion'],
            'correo' => ['servidor' => self::$correo->url(), 'remitente' => 'no-responder@indiecinema.test'],
            'sitio' => ['url' => 'https://indiecinema.test'],
            'pedidos' => ['umbral' => 2, 'por_pagina' => 12],
        ]);
    }

    /**
     * @param array<string, mixed>                $cuerpo
     * @param array<string, array<string, mixed>> $archivos
     */
    private function pedir(
        string $metodo,
        string $url,
        string $usuario = self::ORGANIZADOR,
        string $rol = 'organizador',
        array $cuerpo = [],
        array $archivos = [],
    ): Respuesta {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $cabeceras = [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $usuario,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Omar Prueba'),
        ];
        $campos = $metodo === 'POST' ? ['_csrf' => self::CSRF, ...$cuerpo] : $cuerpo;
        $aplicacion = new Aplicacion(
            dirname(__DIR__),
            $this->configuracion(),
            new Log('programacion', $this->salidaDelLog),
        );

        return PaginasParaValidar::anotar($metodo, $url, $aplicacion->atender(
            new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $campos, $cabeceras, ['csrf' => self::CSRF], $archivos),
        ));
    }

    private function crearSala(string $organizador, string $nombre): Sala
    {
        $sala = Sala::nueva($organizador, new DatosDeSala(
            $nombre,
            'Sala independiente para programar pedidos de la comunidad.',
            'Av. Rivadavia 1200',
            'Luján',
            50,
            2,
            120,
            15,
        ), 'sala.jpg');
        $sala->cambiarHabilitacion(true);
        (new RepositorioDeSalas($this->base))->agregar($sala);

        return $sala;
    }

    private function crearGenero(string $nombre = 'Drama'): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$id, $nombre, "Género {$nombre}."]);

        return $id;
    }

    private function crearObra(string $organizador, string $titulo = 'Obra Propia', int $anio = 2020): Obra
    {
        $genero = $this->crearGenero('Drama ' . bin2hex(random_bytes(2)));
        $obra = Obra::nueva($organizador, $titulo, 'Sinopsis de la obra.', $anio, 'Directora', 95, TipoObra::Pelicula, OrigenCopia::ObraPropia, [$genero]);
        (new RepositorioDeObras($this->base))->agregar($obra);

        return $obra;
    }

    private function crearPedidoActivo(string $titulo, int $anio, string $director, string $activadoHace = '-2 hours'): string
    {
        $pedidoId = Uuid::nuevo();
        $horas = abs((int) filter_var($activadoHace, FILTER_SANITIZE_NUMBER_INT));
        $this->base->ejecutar(
            "INSERT INTO pedido_titulo (id, titulo, anio, director, creado_por, estado, activado_en, creado_en)
             VALUES (?, ?, ?, ?, ?, 'activo', DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 72 HOUR))",
            [$pedidoId, $titulo, $anio, $director, self::ESPECTADORA, $horas],
        );
        $this->base->ejecutar(
            'INSERT INTO adhesion_pedido (pedido_id, usuario_id, creada_en) VALUES (?, ?, UTC_TIMESTAMP()), (?, ?, UTC_TIMESTAMP())',
            [$pedidoId, self::ESPECTADORA, $pedidoId, self::OTRO_ESPECTADOR],
        );

        return $pedidoId;
    }

    private static function textoDelCorreo(int $indice): string
    {
        $correos = self::$correo->correos();
        self::assertArrayHasKey($indice, $correos);

        return (string) base64_decode(str_replace("\r\n", '', explode("\r\n\r\n", $correos[$indice]['mensaje'], 2)[1]), true);
    }

    public function testPrioridadDe48HorasParaSalasMejorCalificadas(): void
    {
        $salaBuena = $this->crearSala(self::ORGANIZADOR, 'Sala Estrella');
        $salaSinNotas = $this->crearSala(self::OTRO_ORGANIZADOR, 'Sala Nueva');
        $obraAjenaSinPrioridad = $this->crearObra(self::OTRO_ORGANIZADOR, 'Obra de Sala Nueva', 2021);

        file_put_contents(self::$archivoCalificaciones, json_encode([
            'salas' => [
                $salaBuena->id => ['promedio' => 4.3, 'cantidad' => 6],
                $salaSinNotas->id => ['promedio' => 3.5, 'cantidad' => 2],
            ],
        ]));

        // Pedido activado hace 2 horas: todavía dentro de las 48h de prioridad.
        $reciente = $this->crearPedidoActivo('Silvia Prieto', 1999, 'Martín Rejtman', '-2 hours');
        // Pedido activado hace 49 horas: superó la ventana, lo ven todos.
        $viejo = $this->crearPedidoActivo('Los guantes mágicos', 2003, 'Martín Rejtman', '-49 hours');

        // Organizador sin promedio >= 4 sólo ve el pedido con > 48 h y no puede tomar el reciente.
        $panelSinPrioridad = $this->pedir('GET', '/organizador/pedidos', self::OTRO_ORGANIZADOR);
        self::assertSame(200, $panelSinPrioridad->estado());
        self::assertStringNotContainsString('Silvia Prieto', $panelSinPrioridad->cuerpo());
        self::assertStringContainsString('Los guantes mágicos', $panelSinPrioridad->cuerpo());

        $intentoPrematuro = $this->pedir('POST', "/organizador/pedidos/{$reciente}/tomar", self::OTRO_ORGANIZADOR, cuerpo: [
            'obra_id' => $obraAjenaSinPrioridad->id,
        ]);
        self::assertSame(422, $intentoPrematuro->estado());
        self::assertStringContainsString('prioridad para las salas mejor calificadas', $intentoPrematuro->cuerpo());
        self::assertSame('activo', (string) $this->base->valor('SELECT estado FROM pedido_titulo WHERE id = ?', [$reciente]));

        // Organizador con promedio >= 4 ve ambos pedidos inmediatamente.
        $panelConPrioridad = $this->pedir('GET', '/organizador/pedidos', self::ORGANIZADOR);
        self::assertSame(200, $panelConPrioridad->estado());
        self::assertStringContainsString('Silvia Prieto', $panelConPrioridad->cuerpo());
        self::assertStringContainsString('Los guantes mágicos', $panelConPrioridad->cuerpo());
        self::assertStringContainsString('prioridad de 48 horas', $panelConPrioridad->cuerpo());
        self::assertStringContainsString($viejo, $panelConPrioridad->cuerpo());
    }

    public function testTomaPedidoConObraExistenteYAvisaPorCorreoALosAdherentes(): void
    {
        $sala = $this->crearSala(self::ORGANIZADOR, 'Sala Luz');
        file_put_contents(self::$archivoCalificaciones, json_encode([
            'salas' => [$sala->id => ['promedio' => 4.5, 'cantidad' => 10]],
        ]));
        $obra = $this->crearObra(self::ORGANIZADOR, 'Trenque Lauquen', 2022);
        $pedidoId = $this->crearPedidoActivo('Trenque Lauquen', 2022, 'Laura Citarella', '-1 hours');

        $toma = $this->pedir('POST', "/organizador/pedidos/{$pedidoId}/tomar", self::ORGANIZADOR, cuerpo: [
            'obra_id' => $obra->id,
        ]);

        self::assertSame(303, $toma->estado());
        self::assertSame('/organizador/pedidos?tomado=1', $toma->cabecera('Location'));

        $fila = $this->base->fila('SELECT estado, obra_id, tomado_por FROM pedido_titulo WHERE id = ?', [$pedidoId]);
        self::assertSame(['estado' => 'tomado', 'obra_id' => $obra->id, 'tomado_por' => self::ORGANIZADOR], $fila);

        // El pedido salió de «Pedidos para programar» y en el ranking público enlaza al catálogo.
        $panel = $this->pedir('GET', '/organizador/pedidos?tomado=1', self::ORGANIZADOR);
        self::assertSame(200, $panel->estado());
        self::assertStringContainsString('Tomaste el pedido con tu obra', $panel->cuerpo());

        // Avisó por correo a ambos adherentes (eva@ejemplo.com y clara@ejemplo.com) con el enlace a la obra.
        $correos = self::$correo->correos();
        self::assertCount(2, $correos);
        self::assertSame(['<eva@ejemplo.com>'], $correos[0]['para']);
        self::assertSame(['<clara@ejemplo.com>'], $correos[1]['para']);
        self::assertStringContainsString("https://indiecinema.test/obras/{$obra->id}", self::textoDelCorreo(0));
        self::assertStringContainsString("https://indiecinema.test/obras/{$obra->id}", self::textoDelCorreo(1));

        // No permite tomar con obra ajena (404) ni sin obra (422).
        $otraObra = $this->crearObra(self::OTRO_ORGANIZADOR, 'Obra Ajena', 2021);
        $segundoPedido = $this->crearPedidoActivo('La flor', 2018, 'Mariano Llinás', '-1 hours');
        self::assertSame(404, $this->pedir('POST', "/organizador/pedidos/{$segundoPedido}/tomar", self::ORGANIZADOR, cuerpo: ['obra_id' => $otraObra->id])->estado());
        self::assertSame(422, $this->pedir('POST', "/organizador/pedidos/{$segundoPedido}/tomar", self::ORGANIZADOR, cuerpo: ['obra_id' => ''])->estado());
    }

    public function testTomaPedidoCargandoUnaObraNuevaPrecargada(): void
    {
        $sala = $this->crearSala(self::ORGANIZADOR, 'Sala Atlas');
        file_put_contents(self::$archivoCalificaciones, json_encode([
            'salas' => [$sala->id => ['promedio' => 4.2, 'cantidad' => 4]],
        ]));
        $generoId = $this->crearGenero('Documental');
        $pedidoId = $this->crearPedidoActivo('El escarabajo de oro', 2014, 'Alejo Moguillansky', '-1 hours');

        $formulario = $this->pedir('GET', "/organizador/obras/nueva?pedido={$pedidoId}", self::ORGANIZADOR);
        self::assertSame(200, $formulario->estado());
        self::assertStringContainsString('value="El escarabajo de oro"', $formulario->cuerpo());
        self::assertStringContainsString('value="2014"', $formulario->cuerpo());
        self::assertStringContainsString('value="Alejo Moguillansky"', $formulario->cuerpo());
        self::assertStringContainsString("name=\"pedido_id\" value=\"{$pedidoId}\"", $formulario->cuerpo());

        // Si el envío del alta falla (por ejemplo, sin sinopsis), conserva pedido_id para no perder el vínculo.
        $invalido = $this->pedir('POST', '/organizador/obras/nueva', self::ORGANIZADOR, cuerpo: [
            'pedido_id' => $pedidoId,
            'titulo' => 'El escarabajo de oro',
            'anio' => '2014',
            'director' => 'Alejo Moguillansky',
            'duracion' => '100',
            'tipo' => 'pelicula',
            'origen_copia' => 'obra_propia',
            'generos' => [$generoId],
            'sinopsis' => '',
        ]);
        self::assertSame(422, $invalido->estado());
        self::assertStringContainsString("name=\"pedido_id\" value=\"{$pedidoId}\"", $invalido->cuerpo());

        // Al completar y guardar la obra, el pedido pasa a «tomado» y salen los avisos por correo.
        $guardada = $this->pedir('POST', '/organizador/obras/nueva', self::ORGANIZADOR, cuerpo: [
            'pedido_id' => $pedidoId,
            'titulo' => 'El escarabajo de oro',
            'anio' => '2014',
            'director' => 'Alejo Moguillansky',
            'duracion' => '100',
            'tipo' => 'pelicula',
            'origen_copia' => 'obra_propia',
            'generos' => [$generoId],
            'sinopsis' => 'Una comedia aventurera filmada en Misiones.',
        ], archivos: ['afiche' => Imagenes::subida('sala.png')]);

        self::assertSame(303, $guardada->estado());
        self::assertSame('/organizador/obras?creada=1', $guardada->cabecera('Location'));

        $obraId = (string) $this->base->valor("SELECT id FROM obra WHERE titulo = 'El escarabajo de oro'");
        $fila = $this->base->fila('SELECT estado, obra_id, tomado_por FROM pedido_titulo WHERE id = ?', [$pedidoId]);
        self::assertSame(['estado' => 'tomado', 'obra_id' => $obraId, 'tomado_por' => self::ORGANIZADOR], $fila);
        self::assertCount(2, self::$correo->correos());
    }
}
