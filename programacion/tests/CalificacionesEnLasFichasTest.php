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
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;
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
 * Las calificaciones en la ficha de la sala y en la de la obra (EF-04.3): el promedio, las
 * últimas y el historial de proyecciones, con un MySQL de verdad y un funciones de mentira
 * (Dobles/calificaciones.php) que responde lo que la prueba le deja en un archivo.
 */
final class CalificacionesEnLasFichasTest extends TestCase
{
    use ConBaseDePruebas;

    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /** @var resource|null */
    private static $servidor = null;

    private static string $urlFunciones;
    private static string $archivo;

    private BaseDeDatos $base;
    private Sala $sala;
    private string $obraId;
    private string $finalizada;

    public static function setUpBeforeClass(): void
    {
        self::$archivo = (string) tempnam(sys_get_temp_dir(), 'calificaciones-');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $puerto = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        self::$urlFunciones = "http://127.0.0.1:{$puerto}";
        $proceso = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$puerto}", __DIR__ . '/Dobles/calificaciones.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $tuberias,
            null,
            [...getenv(), 'CALIFICACIONES' => self::$archivo],
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
        @unlink(self::$archivo);
    }

    protected function setUp(): void
    {
        $this->base = self::baseConTablasNuevas($this->configuracion());
        file_put_contents(self::$archivo, '{}');

        $salas = new RepositorioDeSalas($this->base);
        $this->sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('Cine Club El Galpón', 'Un galpón.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15), 'sala.jpg');
        $salas->agregar($this->sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$this->sala->id]);

        $generoId = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$generoId, 'Drama', 'Obras de drama.']);
        $obra = Obra::nueva(self::ORGANIZADORA, 'Zama', 'Sinopsis.', 2017, 'Lucrecia Martel', 115, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$generoId]);
        (new RepositorioDeObras($this->base))->agregar($obra);
        $this->obraId = $obra->id;

        // Una función que ya se dio: se crea en el futuro y se pasa al pasado por SQL.
        $funcion = Funcion::programada($this->sala->id, [$this->obraId], 48, 3500.0, new DateTimeImmutable('+10 days'));
        (new RepositorioDeFunciones($this->base))->agregar($funcion);
        $this->base->ejecutar("UPDATE funcion SET fecha_hora = '2026-09-06 20:30:00', estado = 'finalizada' WHERE id = ?", [$funcion->id]);
        $this->finalizada = $funcion->id;
    }

    private function configuracion(?string $funciones = null): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'subsistemas' => ['funciones' => $funciones ?? self::$urlFunciones],
            'interno' => ['token' => 'token-de-programacion'],
        ]);
    }

    private function pedir(string $ruta, ?string $funciones = null): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion($funciones), new Log('programacion', fopen('php://memory', 'w')));

        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);

        return PaginasParaValidar::anotar('GET', $ruta, $aplicacion->atender(new Peticion('GET', (string) parse_url($ruta, PHP_URL_PATH), $consulta, [], [], [], [])));
    }

    /**
     * @param array<string, mixed> $datos
     */
    private static function funcionesResponde(array $datos): void
    {
        file_put_contents(self::$archivo, json_encode($datos));
    }

    private function conCalificaciones(): void
    {
        $ultimas = [
            ['funcion_id' => $this->finalizada, 'puntaje' => 5, 'comentario' => "Hermosa copia.\n<b>Volvería</b>.", 'espectador' => 'Eva', 'fecha' => '2026-09-07'],
            ['funcion_id' => $this->finalizada, 'puntaje' => 4, 'comentario' => null, 'espectador' => null, 'fecha' => '2026-09-06'],
        ];
        self::funcionesResponde([
            'salas' => [$this->sala->id => ['promedio' => 4.5, 'cantidad' => 2]],
            'obras' => [$this->obraId => ['promedio' => 4.5, 'cantidad' => 2]],
            'funciones' => [$this->finalizada => ['promedio' => 4.5, 'cantidad' => 2]],
            'asistentes' => [$this->finalizada => 41],
            'ultimas' => ['sala' => [$this->sala->id => $ultimas], 'obra' => [$this->obraId => $ultimas]],
        ]);
    }

    public function testLaFichaDeLaSalaMuestraElPromedioLasUltimasYElHistorial(): void
    {
        $this->conCalificaciones();

        $respuesta = $this->pedir("/salas/{$this->sala->id}");

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('<strong>4,5</strong>', $cuerpo);
        self::assertStringContainsString('(2 calificaciones)', $cuerpo);
        self::assertStringContainsString('Lo que dicen los espectadores', $cuerpo);
        // El comentario lo escribió un espectador: se escapa, y los saltos de línea pasan a <br>.
        self::assertStringContainsString('Hermosa copia.<br />' . "\n" . '&lt;b&gt;Volvería&lt;/b&gt;.', $cuerpo);
        // Sin el nombre (cuentas no respondió), la calificación sale igual.
        self::assertStringContainsString('Un espectador', $cuerpo);
        // El historial: la fecha, lo que se dio, cuántos entraron de cuántos y el promedio.
        self::assertStringContainsString('Historial de proyecciones', $cuerpo);
        self::assertStringContainsString('06 sep 2026', $cuerpo);
        self::assertStringContainsString("/cartelera/{$this->finalizada}", $cuerpo);
        self::assertStringContainsString('41 de 48', $cuerpo);
    }

    public function testUnaSalaSinCalificacionesLoDiceYSuHistorialSaleSinCalificar(): void
    {
        self::funcionesResponde([]);

        $cuerpo = $this->pedir("/salas/{$this->sala->id}")->cuerpo();

        self::assertStringNotContainsString('calificaciones)', $cuerpo);
        self::assertStringContainsString('Todavía nadie calificó una función de esta sala.', $cuerpo);
        self::assertStringContainsString('Sin calificar', $cuerpo);
        self::assertStringContainsString('0 de 48', $cuerpo);
    }

    public function testSiFuncionesNoRespondeLaFichaSaleIgualConUnAviso(): void
    {
        // Un puerto en el que no escucha nadie: como funciones apagado.
        $respuesta = $this->pedir("/salas/{$this->sala->id}", 'http://127.0.0.1:9');

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('Ahora no podemos mostrar las calificaciones.', $respuesta->cuerpo());
        // El historial es de programación: sale igual, sin los asistentes.
        self::assertStringContainsString('06 sep 2026', $respuesta->cuerpo());
        self::assertStringContainsString('<td>—</td>', $respuesta->cuerpo());
    }

    public function testLaFichaDeLaObraMuestraSuPromedioYLasUltimas(): void
    {
        $this->conCalificaciones();

        $respuesta = $this->pedir("/obras/{$this->obraId}");

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertStringContainsString('<strong>4,5</strong>', $respuesta->cuerpo());
        self::assertStringContainsString('Hermosa copia.', $respuesta->cuerpo());

        self::funcionesResponde([]);
        self::assertStringContainsString('Todavía nadie calificó una función de esta película.', $this->pedir("/obras/{$this->obraId}")->cuerpo());
    }

    // ---- Orden por reputación (EF-04.4) ---------------------------------------------------------

    /**
     * Tres salas con una función de Zama cada una: la mejor calificada da la más lejana, y la que
     * no tiene calificaciones, la más próxima. Por fecha salen al revés que por reputación.
     *
     * @return array{string, string, string} los nombres de las salas, de la mejor a la peor
     */
    private function tresSalas(): array
    {
        $salas = new RepositorioDeSalas($this->base);
        $funciones = new RepositorioDeFunciones($this->base);
        $nombres = [];
        foreach ([['Cine Club El Galpón', '+5 days', 4.5], ['Sótano 9', '+3 days', 3.0], ['La Terraza Indie', '+1 day', null]] as [$nombre, $cuando, $promedio]) {
            $sala = $nombre === 'Cine Club El Galpón'
                ? $this->sala
                : Sala::nueva(self::ORGANIZADORA, new DatosDeSala($nombre, 'Una sala.', 'Calle 1', 'Luján', 60, 2, 120, 15), 'sala.jpg');
            if ($sala !== $this->sala) {
                $salas->agregar($sala);
                $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);
            }
            $funciones->agregar(Funcion::programada($sala->id, [$this->obraId], 40, 3500.0, new DateTimeImmutable($cuando)));
            if ($promedio !== null) {
                $calificadas[$sala->id] = ['promedio' => $promedio, 'cantidad' => 3];
            }
            $nombres[] = $nombre;
        }
        self::funcionesResponde(['salas' => $calificadas ?? []]);

        return [$nombres[0], $nombres[1], $nombres[2]];
    }

    /**
     * @param list<string> $textos
     */
    private static function assertEnOrden(array $textos, string $cuerpo): void
    {
        $posiciones = array_map(static fn (string $texto): int|false => strpos($cuerpo, $texto), $textos);
        self::assertNotContains(false, $posiciones, 'Falta alguno de: ' . implode(', ', $textos));
        $ordenadas = $posiciones;
        sort($ordenadas);
        self::assertSame($ordenadas, $posiciones, 'No están en el orden: ' . implode(', ', $textos));
    }

    public function testLaCarteleraSeOrdenaPorLaReputacionDeLaSalaYLasSinCalificarVanAlFinal(): void
    {
        [$mejor, $media, $sinCalificar] = $this->tresSalas();

        $porReputacion = $this->pedir('/cartelera?orden=calificacion_desc');
        self::assertSame(200, $porReputacion->estado(), $porReputacion->cuerpo());
        self::assertEnOrden([$mejor, $media, $sinCalificar], $porReputacion->cuerpo());
        self::assertStringContainsString('Mejor calificadas', $porReputacion->cuerpo());

        // Por fecha, como siempre, al revés.
        self::assertEnOrden([$sinCalificar, $media, $mejor], $this->pedir('/cartelera')->cuerpo());
    }

    public function testSiFuncionesNoRespondeLaCarteleraSaleOrdenadaPorFecha(): void
    {
        [$mejor, $media, $sinCalificar] = $this->tresSalas();

        $respuesta = $this->pedir('/cartelera?orden=calificacion_desc', 'http://127.0.0.1:9');

        self::assertSame(200, $respuesta->estado());
        self::assertEnOrden([$sinCalificar, $media, $mejor], $respuesta->cuerpo());
    }

    public function testLaFichaDeLaObraMuestraPrimeroLasFuncionesDeLasSalasMejorCalificadas(): void
    {
        [$mejor, $media, $sinCalificar] = $this->tresSalas();

        self::assertEnOrden([$mejor, $media, $sinCalificar], $this->pedir("/obras/{$this->obraId}")->cuerpo());
    }
}
