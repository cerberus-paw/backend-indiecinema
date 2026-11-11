<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Pruebas\ConBaseDePruebas;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Servicios\FinalizacionDeFunciones;
use IndieCinema\Programacion\Servicios\ReservasDeFunciones;
use PHPUnit\Framework\TestCase;

/**
 * La tarea que finaliza las funciones terminadas (EF-04.1), con un MySQL de verdad y un funciones
 * de mentira (Dobles/cancelacion.php) que anota los avisos. La sala de las pruebas tiene funciones
 * de 120 minutos: una que empezó hace tres horas terminó, y una que empezó hace una hora no.
 */
final class FinalizacionDeFuncionesTest extends TestCase
{
    use ConBaseDePruebas;

    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /** @var resource|null */
    private static $servidor = null;

    private static string $urlFunciones;
    private static string $avisos;

    private BaseDeDatos $base;
    private Sala $sala;
    private string $obraId;

    public static function setUpBeforeClass(): void
    {
        self::$avisos = (string) tempnam(sys_get_temp_dir(), 'finalizadas-');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$urlFunciones = "http://127.0.0.1:{$puerto}";
        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", dirname(__DIR__) . '/Dobles/cancelacion.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
            null,
            [...getenv(), 'CANCELACIONES' => self::$avisos],
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
        @unlink(self::$avisos);
    }

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas($this->configuracion());
        file_put_contents(self::$avisos, '');

        $salas = new RepositorioDeSalas($this->base);
        $this->sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('Cine Club El Galpón', 'Un galpón.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15), 'sala.jpg');
        $salas->agregar($this->sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$this->sala->id]);

        $generoId = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$generoId, 'Drama', 'Obras de drama.']);
        $obra = Obra::nueva(self::ORGANIZADORA, 'Zama', 'Sinopsis.', 2017, 'Lucrecia Martel', 115, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$generoId]);
        (new RepositorioDeObras($this->base))->agregar($obra);
        $this->obraId = $obra->id;
    }

    private function configuracion(?string $funciones = null): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'subsistemas' => ['funciones' => $funciones ?? self::$urlFunciones],
            'interno' => ['token' => 'token-de-programacion'],
        ]);
    }

    private function finalizacion(?string $funciones = null): FinalizacionDeFunciones
    {
        return new FinalizacionDeFunciones(
            new RepositorioDeFunciones($this->base),
            new ReservasDeFunciones($this->configuracion($funciones), new Log('programacion', fopen('php://memory', 'w'))),
        );
    }

    /**
     * Una función programada a esa hora. Se crea en el futuro y se le cambia la fecha por SQL:
     * el modelo no deja programar en el pasado.
     */
    private function funcion(string $cuando, string $estado = 'programada'): string
    {
        $funcion = Funcion::programada($this->sala->id, [$this->obraId], 40, 3500.0, new DateTimeImmutable('+10 days'));
        (new RepositorioDeFunciones($this->base))->agregar($funcion);
        $this->base->ejecutar('UPDATE funcion SET fecha_hora = ?, estado = ? WHERE id = ?', [new DateTimeImmutable($cuando), $estado, $funcion->id]);

        return $funcion->id;
    }

    private function estadoDe(string $id): string
    {
        return (string) $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function avisos(): array
    {
        return array_map(
            static fn (string $linea): array => json_decode($linea, true),
            array_values(array_filter(explode("\n", (string) file_get_contents(self::$avisos)))),
        );
    }

    public function testFinalizaLasQueTerminaronYLeAvisaAFunciones(): void
    {
        $terminada = $this->funcion('-3 hours');
        $enCurso = $this->funcion('-1 hour');
        $futura = $this->funcion('+2 days');
        $cancelada = $this->funcion('-3 hours', 'cancelada');

        self::assertSame(1, $this->finalizacion()->finalizar());

        self::assertSame(['finalizada', 'programada', 'programada', 'cancelada'], array_map($this->estadoDe(...), [$terminada, $enCurso, $futura, $cancelada]));
        self::assertSame([['aviso' => 'finalizada', 'funcion' => $terminada, 'organizador_id' => self::ORGANIZADORA]], self::avisos());
    }

    public function testTerminaALaHoraMasLaDuracionDeLasFuncionesDeLaSala(): void
    {
        $justo = $this->funcion('-120 minutes');
        $unMinutoAntes = $this->funcion('-119 minutes');

        self::assertSame(1, $this->finalizacion()->finalizar());

        self::assertSame(['finalizada', 'programada'], array_map($this->estadoDe(...), [$justo, $unMinutoAntes]));
    }

    public function testCorrerlaDeMasNoHaceNada(): void
    {
        $this->funcion('-3 hours');
        $this->finalizacion()->finalizar();
        file_put_contents(self::$avisos, '');

        self::assertSame(0, $this->finalizacion()->finalizar());
        self::assertSame([], self::avisos());
    }

    public function testSiFuncionesNoRespondeLaFuncionSigueProgramadaYSeReintenta(): void
    {
        $terminada = $this->funcion('-3 hours');

        // Un puerto en el que no escucha nadie: como funciones apagado.
        self::assertSame(0, $this->finalizacion('http://127.0.0.1:9')->finalizar());
        self::assertSame('programada', $this->estadoDe($terminada));

        // La próxima pasada, con funciones andando, la finaliza.
        self::assertSame(1, $this->finalizacion()->finalizar());
        self::assertSame('finalizada', $this->estadoDe($terminada));
    }

    public function testTambienFinalizaLasDeUnaSalaDadaDeBaja(): void
    {
        $terminada = $this->funcion('-3 hours');
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$this->sala->id]);

        self::assertSame(1, $this->finalizacion()->finalizar());
        self::assertSame('finalizada', $this->estadoDe($terminada));
    }
}
