<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDeObra;
use IndieCinema\Programacion\Modelo\EstadoObra;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La API interna de obras de punta a punta (EF-07.7): la aplicación entera con las rutas de
 * programación, la autorización por token y un MySQL de verdad. La llama moderación, que revisa las
 * obras nuevas en su bandeja y no tiene acceso al esquema de programación.
 */
final class ObrasInternasTest extends TestCase
{
    use ConBaseDePruebas;

    private const DUENA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const MODERADOR = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const TOKEN_DE_MODERACION = 'token-de-moderacion';
    private const TOKEN_DE_FUNCIONES = 'token-de-funciones';

    private BaseDeDatos $base;
    private Configuracion $configuracion;
    private string $generoId;

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'llamadores' => [
                'moderacion' => hash('sha256', self::TOKEN_DE_MODERACION),
                'funciones' => hash('sha256', self::TOKEN_DE_FUNCIONES),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->salidaDelLog = fopen('php://memory', 'w+');
        $this->generoId = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$this->generoId, 'Drama', 'Obras de drama.']);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedir(string $metodo, string $ruta, array $cuerpo = [], string $llamador = 'moderacion', string $token = self::TOKEN_DE_MODERACION): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion($metodo, $ruta, [], $cuerpo, [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ]));
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function revisar(string $id, array $cuerpo): Respuesta
    {
        return $this->pedir('POST', "/interno/obras/{$id}/revision", $cuerpo + ['moderador_id' => self::MODERADOR]);
    }

    private function obra(string $titulo, EstadoObra $estado = EstadoObra::Pendiente, string $creada = '2026-10-01 10:00:00'): string
    {
        $obra = Obra::crear(
            self::DUENA,
            new DatosDeObra($titulo, 'Un pueblo y su río.', 2020, 'Rocío Paz', 95, TipoObra::Pelicula, OrigenCopia::ObraPropia, [$this->generoId]),
            'afiche.jpg',
            $estado,
        );
        (new RepositorioDeObras($this->base))->agregar($obra);
        $this->base->ejecutar('UPDATE obra SET creada_en = ? WHERE id = ?', [$creada, $obra->id]);

        return $obra->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function enLaBase(string $id): array
    {
        return $this->base->fila('SELECT estado, motivo FROM obra WHERE id = ?', [$id]) ?? [];
    }

    private function sala(): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO sala (id, organizador_id, nombre, descripcion, direccion, localidad, capacidad,'
            . ' peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, habilitada)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, self::DUENA, 'El Galpón', 'Un galpón con proyector.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15, 1],
        );

        return $id;
    }

    /**
     * @param array<string, string> $obras id => candidata|elegida
     */
    private function funcion(string $estado, array $obras): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO funcion (id, sala_id, estado, fecha_hora, precio_base, cupo) VALUES (?, ?, ?, ?, 2000, 50)',
            [$id, $this->sala(), $estado, $estado === 'programada' ? '2027-01-10 20:00:00' : null],
        );
        foreach ($obras as $obraId => $enLaFuncion) {
            $this->base->ejecutar('INSERT INTO funcion_obra (funcion_id, obra_id, estado) VALUES (?, ?, ?)', [$id, $obraId, $enLaFuncion]);
        }

        return $id;
    }

    private function votar(string $funcionId, string $obraId): void
    {
        $this->base->ejecutar('INSERT INTO voto (funcion_id, usuario_id, obra_id) VALUES (?, ?, ?)', [$funcionId, Uuid::nuevo(), $obraId]);
    }

    /**
     * @return array{estado: string, obras: list<string>, votos: list<string>}
     */
    private function laFuncion(string $id): array
    {
        return [
            'estado' => (string) $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$id]),
            'obras' => array_column($this->base->filas('SELECT obra_id FROM funcion_obra WHERE funcion_id = ? ORDER BY obra_id', [$id]), 'obra_id'),
            'votos' => array_column($this->base->filas('SELECT obra_id FROM voto WHERE funcion_id = ? ORDER BY obra_id', [$id]), 'obra_id'),
        ];
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    public function testLasPendientesVanPrimeroLaMasViejaConTodosSusDatos(): void
    {
        $nueva = $this->obra('La nueva', creada: '2026-10-05 10:00:00');
        $vieja = $this->obra('La vieja', creada: '2026-10-01 10:00:00');
        $this->obra('Aprobada', EstadoObra::Aprobada);
        $this->obra('Rechazada', EstadoObra::Rechazada);
        $baja = $this->obra('Dada de baja');
        (new RepositorioDeObras($this->base))->darDeBaja($baja);

        $respuesta = $this->pedir('GET', '/interno/obras/pendientes');

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $obras = json_decode($respuesta->cuerpo(), true);
        self::assertSame([$vieja, $nueva], array_column($obras, 'id'));
        self::assertSame([
            'id' => $vieja,
            'titulo' => 'La vieja',
            'tipo' => 'pelicula',
            'anio' => 2020,
            'director' => 'Rocío Paz',
            'duracion' => 95,
            'sinopsis' => 'Un pueblo y su río.',
            'generos' => ['Drama'],
            'origen_copia' => 'obra_propia',
            'afiche' => true,
            'dueno_id' => self::DUENA,
            'estado' => 'pendiente',
            'motivo' => null,
            'creada_en' => '2026-10-01T10:00:00',
        ], $obras[0]);
    }

    /**
     * Para que la pestaña muestre una obra que no está pendiente y el moderador la rechace (lo usa
     * la denuncia de EF-07.8).
     */
    public function testUnaObraPorSuIdConSuEstadoYSuMotivo(): void
    {
        $id = $this->obra('La aprobada', EstadoObra::Aprobada, '2026-10-03 09:30:00');
        $this->base->ejecutar('UPDATE obra SET motivo = ? WHERE id = ?', ['Está bien.', $id]);

        $respuesta = $this->pedir('GET', "/interno/obras/{$id}");

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame([
            'id' => $id,
            'titulo' => 'La aprobada',
            'tipo' => 'pelicula',
            'anio' => 2020,
            'director' => 'Rocío Paz',
            'duracion' => 95,
            'sinopsis' => 'Un pueblo y su río.',
            'generos' => ['Drama'],
            'origen_copia' => 'obra_propia',
            'afiche' => true,
            'dueno_id' => self::DUENA,
            'estado' => 'aprobada',
            'motivo' => 'Está bien.',
            'creada_en' => '2026-10-03T09:30:00',
        ], json_decode($respuesta->cuerpo(), true));
    }

    public function testUnaObraQueNoExisteODadaDeBajaEs404(): void
    {
        $baja = $this->obra('Dada de baja', EstadoObra::Aprobada);
        (new RepositorioDeObras($this->base))->darDeBaja($baja);

        foreach ([$baja, Uuid::nuevo(), 'no-es-un-id'] as $id) {
            self::assertSame(404, $this->pedir('GET', "/interno/obras/{$id}")->estado(), $id);
        }
    }

    public function testSinPendientesEsUnaListaVacia(): void
    {
        self::assertSame('[]', $this->pedir('GET', '/interno/obras/pendientes')->cuerpo());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function resoluciones(): iterable
    {
        yield 'aprobar' => ['aprobar', 'aprobada'];
        yield 'rechazar' => ['rechazar', 'rechazada'];
    }

    #[DataProvider('resoluciones')]
    public function testResolverDejaElEstadoYElMotivo(string $resolucion, string $estado): void
    {
        $id = $this->obra('Mi obra');

        $respuesta = $this->revisar($id, ['resolucion' => $resolucion, 'motivo' => 'Revisada.']);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(
            ['id' => $id, 'titulo' => 'Mi obra', 'dueno_id' => self::DUENA, 'estado' => $estado, 'cambio' => true],
            json_decode($respuesta->cuerpo(), true),
        );
        self::assertSame(['estado' => $estado, 'motivo' => 'Revisada.'], $this->enLaBase($id));
        self::assertStringContainsString(self::MODERADOR . " revisó la obra {$id}: {$estado} (Revisada.)", $this->log());
    }

    /**
     * El reintento de moderación (o dos pestañas) no cambia nada: ni el motivo.
     */
    public function testResolverLoMismoDosVecesNoCambiaNada(): void
    {
        $id = $this->obra('Mi obra');
        $this->revisar($id, ['resolucion' => 'aprobar', 'motivo' => 'Bien.']);

        $otra = $this->revisar($id, ['resolucion' => 'aprobar', 'motivo' => 'Otro motivo.']);

        self::assertSame(200, $otra->estado());
        self::assertFalse(json_decode($otra->cuerpo(), true)['cambio']);
        self::assertSame(['estado' => 'aprobada', 'motivo' => 'Bien.'], $this->enLaBase($id));
    }

    public function testUnaRechazadaSeAprueba(): void
    {
        $id = $this->obra('Mi obra', EstadoObra::Rechazada);

        self::assertSame(200, $this->revisar($id, ['resolucion' => 'aprobar', 'motivo' => 'Ahora sí.'])->estado());

        self::assertSame(['estado' => 'aprobada', 'motivo' => 'Ahora sí.'], $this->enLaBase($id));
    }

    /**
     * Q16: una aprobada que se rechaza (por una denuncia) sale de las votaciones abiertas, y sus
     * votos se borran para que quien la votó elija otra.
     */
    public function testRechazarUnaAprobadaLaSacaDeLaVotacionYBorraSusVotos(): void
    {
        $rechazada = $this->obra('La denunciada', EstadoObra::Aprobada);
        $otra = $this->obra('La otra', EstadoObra::Aprobada);
        $funcion = $this->funcion('en_votacion_titulos', [$rechazada => 'candidata', $otra => 'candidata']);
        $this->votar($funcion, $rechazada);
        $this->votar($funcion, $otra);

        self::assertSame(200, $this->revisar($rechazada, ['resolucion' => 'rechazar', 'motivo' => 'Denunciada.'])->estado());

        self::assertSame(['estado' => 'en_votacion_titulos', 'obras' => [$otra], 'votos' => [$otra]], $this->laFuncion($funcion));
    }

    /**
     * Una votación sin candidatas no tiene sentido: se cancela. Queda con la obra, porque una
     * función siempre tiene alguna.
     */
    public function testSiEraLaUnicaCandidataLaFuncionSeCancela(): void
    {
        $rechazada = $this->obra('La denunciada', EstadoObra::Aprobada);
        $funcion = $this->funcion('en_votacion_titulos', [$rechazada => 'candidata']);
        $this->votar($funcion, $rechazada);

        self::assertSame(200, $this->revisar($rechazada, ['resolucion' => 'rechazar', 'motivo' => 'Denunciada.'])->estado());

        self::assertSame(['estado' => 'cancelada', 'obras' => [$rechazada], 'votos' => []], $this->laFuncion($funcion));
    }

    /**
     * Q3: la votación ya terminó en un acuerdo, y una programada tiene reservas.
     */
    public function testRechazarUnaAprobadaNoTocaUnAcuerdoNiUnaProgramada(): void
    {
        $rechazada = $this->obra('La denunciada', EstadoObra::Aprobada);
        $otra = $this->obra('La otra', EstadoObra::Aprobada);
        $acuerdo = $this->funcion('en_acuerdo_fecha', [$rechazada => 'elegida', $otra => 'candidata']);
        $programada = $this->funcion('programada', [$rechazada => 'elegida']);
        $antes = [$this->laFuncion($acuerdo), $this->laFuncion($programada)];

        self::assertSame(200, $this->revisar($rechazada, ['resolucion' => 'rechazar', 'motivo' => 'Denunciada.'])->estado());

        self::assertSame($antes, [$this->laFuncion($acuerdo), $this->laFuncion($programada)]);
        self::assertSame('en_acuerdo_fecha', $antes[0]['estado']);
        self::assertSame('programada', $antes[1]['estado']);
    }

    public function testUnaQueNoExisteODadaDeBajaEs404(): void
    {
        $baja = $this->obra('Dada de baja');
        (new RepositorioDeObras($this->base))->darDeBaja($baja);

        foreach ([$baja, Uuid::nuevo(), 'no-es-un-id'] as $id) {
            self::assertSame(404, $this->revisar($id, ['resolucion' => 'aprobar', 'motivo' => 'Bien.'])->estado());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function pedidosMalFormados(): iterable
    {
        yield 'sin resolución' => [['motivo' => 'Bien.']];
        yield 'otra resolución' => [['resolucion' => 'pendiente', 'motivo' => 'Bien.']];
        yield 'sin motivo' => [['resolucion' => 'aprobar', 'motivo' => '  ']];
        yield 'motivo de más' => [['resolucion' => 'aprobar', 'motivo' => str_repeat('a', 1001)]];
        yield 'sin moderador' => [['resolucion' => 'aprobar', 'motivo' => 'Bien.', 'moderador_id' => 'nadie']];
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    #[DataProvider('pedidosMalFormados')]
    public function testUnPedidoMalFormadoEs400(array $cuerpo): void
    {
        $id = $this->obra('Mi obra');

        self::assertSame(400, $this->revisar($id, $cuerpo)->estado());
        self::assertSame(['estado' => 'pendiente', 'motivo' => null], $this->enLaBase($id));
    }

    public function testSoloLaLlamaModeracion(): void
    {
        $id = $this->obra('Mi obra');

        self::assertSame(403, $this->pedir('GET', '/interno/obras/pendientes', llamador: 'funciones', token: self::TOKEN_DE_FUNCIONES)->estado());
        self::assertSame(403, $this->pedir('GET', "/interno/obras/{$id}", llamador: 'funciones', token: self::TOKEN_DE_FUNCIONES)->estado());
        self::assertSame(403, $this->pedir('POST', "/interno/obras/{$id}/revision", ['resolucion' => 'aprobar', 'motivo' => 'Bien.', 'moderador_id' => self::MODERADOR], 'funciones', self::TOKEN_DE_FUNCIONES)->estado());
        self::assertSame('pendiente', $this->enLaBase($id)['estado']);
    }
}
