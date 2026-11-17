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
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * «Usuarios bloqueados» del organizador de punta a punta (EF-12.1 / IC-580): la aplicación de
 * programación con sus plantillas Twig, un MySQL de verdad y la cuentas de mentira (Dobles/cuentas.php)
 * para comprobar que los nombres de todos los bloqueados se piden en un solo llamado.
 */
final class BloqueosDelOrganizadorTest extends TestCase
{
    use ConBaseDePruebas;
    use ConSaltoAlContenido;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const OTRO_ORGANIZADOR = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
    private const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTRO_ESPECTADOR = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    /** @var resource|null */
    private static $servidor = null;

    private static string $cuentas;
    private static string $consultas;

    private BaseDeDatos $base;

    /** @var resource */
    private $salidaDelLog;

    public static function setUpBeforeClass(): void
    {
        self::$consultas = (string) tempnam(sys_get_temp_dir(), 'consultas');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$cuentas = "http://127.0.0.1:{$puerto}";

        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . '/Dobles/cuentas.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
            null,
            ['REGISTRO_CONSULTAS' => self::$consultas] + getenv(),
        );
        self::$servidor = $proceso === false ? null : $proceso;

        for ($intento = 0; $intento < 100; ++$intento) {
            $conexion = @fsockopen('127.0.0.1', $puerto, $codigo, $mensaje, 0.05);
            if ($conexion !== false) {
                fclose($conexion);

                return;
            }
            usleep(20_000);
        }
        self::fail('No arrancó la cuentas de prueba.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$servidor !== null) {
            proc_terminate(self::$servidor);
            proc_close(self::$servidor);
        }
        @unlink(self::$consultas);
    }

    protected function setUp(): void
    {
        $this->salidaDelLog = fopen('php://memory', 'w+');
        $this->base = self::baseConTablasNuevas($this->configuracion());
        file_put_contents(self::$consultas, '');
    }

    private function configuracion(?string $urlCuentas = null): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['cuentas' => $urlCuentas ?? self::$cuentas],
            'interno' => ['token' => 'token-de-programacion'],
            'correo' => ['servidor' => 'smtp://127.0.0.1:1', 'remitente' => 'no-responder@indiecinema.test'],
            'solicitudes' => ['destino' => 'equipo@indiecinema.test'],
        ]);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(
        string $metodo,
        string $url,
        string $organizador = self::ORGANIZADOR,
        array $cuerpo = [],
        bool $conCsrf = true,
        ?string $urlCuentas = null,
    ): Respuesta {
        $cabeceras = [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $organizador,
            'X-Rol' => 'organizador',
            'X-Usuario-Nombre' => rawurlencode('Omar Prueba'),
        ];
        $campos = $metodo === 'POST' && $conCsrf ? ['_csrf' => self::CSRF, ...$cuerpo] : $cuerpo;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(
            dirname(__DIR__),
            $this->configuracion($urlCuentas),
            new Log('programacion', $this->salidaDelLog),
        );

        return PaginasParaValidar::anotar($metodo, $url, $aplicacion->atender(
            new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $campos, $cabeceras, ['csrf' => self::CSRF]),
        ));
    }

    private function crearSala(string $organizador, string $nombre): Sala
    {
        $sala = Sala::nueva($organizador, new DatosDeSala(
            $nombre,
            'Sala independiente de cine de autor con programación semanal.',
            'Calle 50 Nº 420',
            'La Plata',
            45,
            2,
            120,
            15,
        ), 'sala-prueba.jpg');
        $sala->cambiarHabilitacion(true);
        (new RepositorioDeSalas($this->base))->agregar($sala);

        return $sala;
    }

    public function testSinSalasAvisaQueTodaviaNoTieneSalas(): void
    {
        $respuesta = $this->pedir('GET', '/organizador/bloqueados');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('Todavía no tenés ninguna sala para administrar bloqueos.', $respuesta->cuerpo());
    }

    public function testBloqueaConMotivoListaConNombresEnUnSoloLlamadoYDesbloquea(): void
    {
        $salaUno = $this->crearSala(self::ORGANIZADOR, 'Sala Lumière');
        $salaDos = $this->crearSala(self::ORGANIZADOR, 'Cine Club Cosmos');

        // Preselección al llegar desde las reservas de una función (?sala= & ?usuario=).
        $inicial = $this->pedir('GET', "/organizador/bloqueados?sala={$salaDos->id}&usuario=" . self::ESPECTADORA);
        self::assertSame(200, $inicial->estado());
        self::assertStringContainsString('Eva Prueba', $inicial->cuerpo());
        self::assertStringContainsString('value="' . self::ESPECTADORA . '"', $inicial->cuerpo());
        self::assertStringContainsString('Todavía no hay espectadores bloqueados en tus salas.', $inicial->cuerpo());

        // Bloquea dos espectadores distintos en dos salas propias.
        $primero = $this->pedir('POST', "/organizador/salas/{$salaUno->id}/bloqueos", cuerpo: [
            'usuario_id' => self::ESPECTADORA,
            'motivo' => 'Interrumpió la proyección en reiteradas ocasiones.',
        ]);
        self::assertSame(303, $primero->estado());
        self::assertSame("/organizador/bloqueados?bloqueado=1&sala={$salaUno->id}", $primero->cabecera('Location'));

        $segundo = $this->pedir('POST', "/organizador/salas/{$salaDos->id}/bloqueos", cuerpo: [
            'usuario_id' => self::OTRO_ESPECTADOR,
            'motivo' => 'Daños en butaca.',
        ]);
        self::assertSame(303, $segundo->estado());

        // Volver a bloquear el mismo par sala/usuario actualiza el motivo sin duplicar filas.
        $actualizado = $this->pedir('POST', "/organizador/salas/{$salaUno->id}/bloqueos", cuerpo: [
            'usuario_id' => self::ESPECTADORA,
            'motivo' => 'Reincidencia en la sala.',
        ]);
        self::assertSame(303, $actualizado->estado());
        self::assertSame(2, (int) $this->base->valor('SELECT COUNT(*) FROM bloqueo'));

        // Lista completa con aviso de éxito y todos los nombres resueltos en un único pedido a cuentas.
        file_put_contents(self::$consultas, '');
        $listado = $this->pedir('GET', "/organizador/bloqueados?bloqueado=1&sala={$salaUno->id}");
        self::assertSame(200, $listado->estado());
        $html = $listado->cuerpo();
        self::assertStringContainsString('El espectador quedó bloqueado en la sala.', $html);
        self::assertStringContainsString('Eva Prueba', $html);
        self::assertStringContainsString('Clara Prueba', $html);
        self::assertStringContainsString('Sala Lumière', $html);
        self::assertStringContainsString('Cine Club Cosmos', $html);
        self::assertStringContainsString('Reincidencia en la sala.', $html);
        self::assertStringContainsString('Daños en butaca.', $html);

        $consultas = file(self::$consultas, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        self::assertCount(1, $consultas);
        $idsPedidos = explode(',', $consultas[0]);
        self::assertEqualsCanonicalizing([self::ESPECTADORA, self::OTRO_ESPECTADOR], $idsPedidos);

        // Desbloquea a uno y verifica que el otro sigue vigente.
        $desbloqueo = $this->pedir('POST', "/organizador/salas/{$salaUno->id}/bloqueos/" . self::ESPECTADORA . '/quitar');
        self::assertSame(303, $desbloqueo->estado());
        self::assertSame("/organizador/bloqueados?desbloqueado=1&sala={$salaUno->id}", $desbloqueo->cabecera('Location'));
        self::assertSame(1, (int) $this->base->valor('SELECT COUNT(*) FROM bloqueo'));

        $despues = $this->pedir('GET', "/organizador/bloqueados?desbloqueado=1&sala={$salaUno->id}");
        self::assertSame(200, $despues->estado());
        self::assertStringContainsString('El espectador quedó desbloqueado.', $despues->cuerpo());
        self::assertStringNotContainsString(self::ESPECTADORA, $despues->cuerpo());
        self::assertStringContainsString('Clara Prueba', $despues->cuerpo());
    }

    public function testNoPermiteBloquearNiDesbloquearEnSalaAjenaOInexistente(): void
    {
        $salaAjena = $this->crearSala(self::OTRO_ORGANIZADOR, 'Sala Ajena');
        $this->base->ejecutar(
            'INSERT INTO bloqueo (sala_id, usuario_id, motivo, bloqueado_por) VALUES (?, ?, ?, ?)',
            [$salaAjena->id, self::ESPECTADORA, 'Bloqueo existente', self::OTRO_ORGANIZADOR],
        );

        $intentoBloquear = $this->pedir('POST', "/organizador/salas/{$salaAjena->id}/bloqueos", cuerpo: [
            'usuario_id' => self::OTRO_ESPECTADOR,
            'motivo' => 'Intento desde otro organizador',
        ]);
        self::assertSame(404, $intentoBloquear->estado());

        $intentoQuitar = $this->pedir('POST', "/organizador/salas/{$salaAjena->id}/bloqueos/" . self::ESPECTADORA . '/quitar');
        self::assertSame(404, $intentoQuitar->estado());

        $inexistente = $this->pedir('POST', '/organizador/salas/' . Uuid::nuevo() . '/bloqueos', cuerpo: [
            'usuario_id' => self::OTRO_ESPECTADOR,
            'motivo' => 'Sala que no existe',
        ]);
        self::assertSame(404, $inexistente->estado());

        // El bloqueo ajeno sigue intacto y no se agregó el intruso.
        self::assertSame(1, (int) $this->base->valor('SELECT COUNT(*) FROM bloqueo'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function enviosInvalidos(): iterable
    {
        yield 'sin usuario' => [['usuario_id' => '', 'motivo' => 'Motivo válido'], 'Indicá el identificador del espectador a bloquear.'];
        yield 'usuario mal formado' => [['usuario_id' => 'no-es-uuid', 'motivo' => 'Motivo válido'], 'El identificador del espectador no tiene un formato válido.'];
        yield 'organizador a sí mismo' => [['usuario_id' => self::ORGANIZADOR, 'motivo' => 'Auto bloqueo'], 'No podés bloquearte a vos mismo en tu sala.'];
        yield 'motivo vacío' => [['usuario_id' => self::ESPECTADORA, 'motivo' => "   \n "], 'Escribí el motivo del bloqueo.'];
        yield 'motivo mayor a 500' => [['usuario_id' => self::ESPECTADORA, 'motivo' => str_repeat('a', 501)], 'El motivo no puede superar los 500 caracteres.'];
    }

    /**
     * @param array<string, string> $campos
     */
    #[DataProvider('enviosInvalidos')]
    public function testDevuelve422AnteDatosInvalidosYSinGuardar(array $campos, string $errorEsperado): void
    {
        $sala = $this->crearSala(self::ORGANIZADOR, 'Sala Propia');

        $respuesta = $this->pedir('POST', "/organizador/salas/{$sala->id}/bloqueos", cuerpo: $campos);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString($errorEsperado, $respuesta->cuerpo());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM bloqueo'));
    }

    public function testMuestraElListadoConIdsYLogueaSiCuentasNoResponde(): void
    {
        $sala = $this->crearSala(self::ORGANIZADOR, 'Sala Resiliente');
        $this->base->ejecutar(
            'INSERT INTO bloqueo (sala_id, usuario_id, motivo, bloqueado_por) VALUES (?, ?, ?, ?)',
            [$sala->id, self::ESPECTADORA, 'Motivo visible', self::ORGANIZADOR],
        );

        $respuesta = $this->pedir('GET', '/organizador/bloqueados', urlCuentas: 'http://127.0.0.1:1');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString(self::ESPECTADORA, $respuesta->cuerpo());
        self::assertStringContainsString('Motivo visible', $respuesta->cuerpo());

        rewind($this->salidaDelLog);
        $log = (string) stream_get_contents($this->salidaDelLog);
        self::assertStringContainsString('No se pudieron pedir los nombres de los usuarios bloqueados a cuentas.', $log);
    }

    public function testUnPostSinTokenCsrfDa403(): void
    {
        $sala = $this->crearSala(self::ORGANIZADOR, 'Sala Protegida');

        $respuesta = $this->pedir(
            'POST',
            "/organizador/salas/{$sala->id}/bloqueos",
            cuerpo: ['usuario_id' => self::ESPECTADORA, 'motivo' => 'Sin token'],
            conCsrf: false,
        );

        self::assertSame(403, $respuesta->estado());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM bloqueo'));
    }
}
