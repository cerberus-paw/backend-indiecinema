<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use DateTimeImmutable;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use PHPUnit\Framework\TestCase;

/**
 * Cancelar una función le pide a funciones que cancele sus reservas y les avise a los
 * espectadores (E4-03.4), de punta a punta: la aplicación entera, un MySQL de verdad y un funciones
 * de mentira (Dobles/cancelacion.php) que anota lo que le llega.
 */
final class CancelacionConReservasTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /** @var resource|null */
    private static $servidor = null;

    private static string $urlFunciones;
    private static string $cancelaciones;

    private BaseDeDatos $base;
    private RepositorioDeFunciones $funciones;
    private Sala $sala;

    /** @var list<string> */
    private array $obras = [];

    public static function setUpBeforeClass(): void
    {
        self::$cancelaciones = (string) tempnam(sys_get_temp_dir(), 'cancelaciones-');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$urlFunciones = "http://127.0.0.1:{$puerto}";
        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . '/Dobles/cancelacion.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
            null,
            [...getenv(), 'CANCELACIONES' => self::$cancelaciones],
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
        self::fail('No arrancó el funciones de prueba.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$servidor !== null) {
            proc_terminate(self::$servidor);
            proc_close(self::$servidor);
        }
        @unlink(self::$cancelaciones);
    }

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas($this->configuracion());
        $this->funciones = new RepositorioDeFunciones($this->base);
        file_put_contents(self::$cancelaciones, '');

        $salas = new RepositorioDeSalas($this->base);
        $sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('Cine Club El Galpón', 'Un galpón.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15), 'sala.jpg');
        $salas->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);
        $this->sala = $salas->buscar($sala->id) ?? self::fail('No se guardó la sala.');

        $generoId = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$generoId, 'Drama', 'Obras de drama.']);
        $obras = new RepositorioDeObras($this->base);
        foreach (['Zama', 'La ciénaga'] as $titulo) {
            $obra = Obra::nueva(self::ORGANIZADORA, $titulo, 'Sinopsis.', 2001, 'Lucrecia Martel', 103, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$generoId]);
            $obras->agregar($obra);
            $this->obras[$titulo] = $obra->id;
        }
    }

    private function configuracion(?string $funciones = null): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => ['funciones' => $funciones ?? self::$urlFunciones],
            'interno' => ['token' => 'token-de-programacion'],
        ]);
    }

    private function cancelar(string $id, ?string $funciones = null): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion($funciones), new Log('programacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('POST', "/organizador/funciones/{$id}/cancelar", [], ['_csrf' => self::CSRF], [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => self::ORGANIZADORA,
            'X-Rol' => 'organizador',
        ], ['csrf' => self::CSRF]));
    }

    private function enVotacion(): Funcion
    {
        $funcion = $this->sala->crearFuncion(array_values($this->obras), 40, 3500.0);
        $this->funciones->agregar($funcion);

        return $funcion;
    }

    private function programada(): Funcion
    {
        $funcion = $this->enVotacion();
        $funcion->cerrarVotacionTitulos([$this->obras['Zama']]);
        $this->funciones->guardar($funcion);
        $funcion->fijarFechaHora(new DateTimeImmutable('2030-11-21 20:30:00'), new DateTimeImmutable());
        $this->funciones->guardar($funcion);

        return $funcion;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pedidosAFunciones(): array
    {
        return array_map(
            static fn (string $linea): array => json_decode($linea, true),
            array_values(array_filter(explode("\n", (string) file_get_contents(self::$cancelaciones)))),
        );
    }

    public function testCancelarUnaProgramadaLePideAFuncionesQueCanceleSusReservas(): void
    {
        $funcion = $this->programada();

        $respuesta = $this->cancelar($funcion->id);

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/organizador/funciones?cancelada=1', $respuesta->cabecera('Location'));
        self::assertSame([[
            'funcion' => $funcion->id,
            'organizador_id' => self::ORGANIZADORA,
            'titulo' => 'Zama',
            'fecha' => 'Jue 21 nov · 20:30',
        ]], self::pedidosAFunciones());
    }

    public function testUnaEnVotacionNoTieneReservasYNoSeLePideNada(): void
    {
        $funcion = $this->enVotacion();

        self::assertSame(303, $this->cancelar($funcion->id)->estado());
        self::assertSame([], self::pedidosAFunciones());
    }

    public function testSiFuncionesNoRespondeLaFuncionQuedaCanceladaYSeAvisaAlOrganizador(): void
    {
        $funcion = $this->programada();

        // Un puerto en el que no escucha nadie: como funciones apagado.
        $respuesta = $this->cancelar($funcion->id, 'http://127.0.0.1:9');

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/organizador/funciones?cancelada=1&reservas=pendientes', $respuesta->cabecera('Location'));
        self::assertSame('cancelada', $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$funcion->id]));

        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion(), new Log('programacion', fopen('php://memory', 'w')));
        $panel = $aplicacion->atender(new Peticion('GET', '/organizador/funciones', ['cancelada' => '1', 'reservas' => 'pendientes'], [], [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => self::ORGANIZADORA,
            'X-Rol' => 'organizador',
        ], []));
        self::assertStringContainsString('Las reservas de la función no se cancelaron', $panel->cuerpo());
    }
}
