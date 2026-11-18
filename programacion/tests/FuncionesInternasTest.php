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
 * GET /interno/funciones/{id} y GET /interno/funciones?ids=… de punta a punta: la aplicación entera con las rutas de
 * programación, la autorización por token de la API interna y un MySQL de verdad.
 */
final class FuncionesInternasTest extends TestCase
{
    use ConBaseDePruebas;

    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const TOKEN_DE_FUNCIONES = 'token-de-funciones';
    private const TOKEN_DE_CUENTAS = 'token-de-cuentas';
    private const TOKEN_DE_MODERACION = 'token-de-moderacion';
    private const TOKEN_DE_RECAUDACION = 'token-de-recaudacion';
    private const OTRO_ORGANIZADOR = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const DUENA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    private BaseDeDatos $base;
    private Configuracion $configuracion;
    private RepositorioDeSalas $salas;
    private RepositorioDeFunciones $funciones;
    private Sala $sala;

    /** @var array<string, string> las dos obras del catálogo: el id de cada una, por su título */
    private array $obras = [];

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'llamadores' => [
                'funciones' => hash('sha256', self::TOKEN_DE_FUNCIONES),
                'cuentas' => hash('sha256', self::TOKEN_DE_CUENTAS),
                'moderacion' => hash('sha256', self::TOKEN_DE_MODERACION),
                'recaudacion' => hash('sha256', self::TOKEN_DE_RECAUDACION),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->salas = new RepositorioDeSalas($this->base);
        $this->funciones = new RepositorioDeFunciones($this->base);
        $this->sala = $this->salaHabilitada();

        $generoId = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$generoId, 'Drama', 'Obras de drama.']);
        $obras = new RepositorioDeObras($this->base);
        foreach (['Zama', 'La ciénaga'] as $titulo) {
            $obra = Obra::nueva(self::ORGANIZADORA, $titulo, 'Sinopsis.', 2001, 'Lucrecia Martel', 103, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$generoId]);
            $obras->agregar($obra);
            $this->obras[$titulo] = $obra->id;
        }
    }

    /**
     * Una sala guardada y habilitada por el administrador, como la lee el repositorio: sólo en una
     * habilitada se crean funciones.
     */
    private function salaHabilitada(): Sala
    {
        $sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('Cine Club El Galpón', 'Un galpón.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15), 'sala.jpg');
        $this->salas->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        return $this->salas->buscar($sala->id) ?? self::fail('No se guardó la sala.');
    }

    private function funcion(): Funcion
    {
        $funcion = $this->sala->crearFuncion(array_values($this->obras), 40, 3500.0);
        $this->funciones->agregar($funcion);

        return $funcion;
    }

    /**
     * @param array<string, string> $consulta
     */
    private function pedir(string $id, string $llamador = 'funciones', string $token = self::TOKEN_DE_FUNCIONES, array $consulta = []): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', "/interno/funciones/{$id}", $consulta, [], [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ], [], []));
    }

    private function pedirVarias(string $ids, string $llamador = 'funciones', string $token = self::TOKEN_DE_FUNCIONES): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', '/interno/funciones', ['ids' => $ids], [], [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ], [], []));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Respuesta $respuesta): array
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());

        return json_decode($respuesta->cuerpo(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEnVotacionDaLasCandidatasYSinFecha(): void
    {
        $funcion = $this->funcion();

        self::assertSame([
            'id' => $funcion->id,
            'estado' => 'en_votacion_titulos',
            'fecha_hora' => null,
            'precio_base' => 3500,
            'cupo' => 40,
            'titulos' => ['La ciénaga', 'Zama'],
            'titulos_elegidos' => false,
            'sala' => ['id' => $this->sala->id, 'nombre' => 'Cine Club El Galpón', 'localidad' => 'Luján', 'habilitada' => true, 'direccion' => 'Av. San Martín 450', 'duracion_funcion' => 120],
            'organizador_id' => self::ORGANIZADORA,
            // Las mismas que los títulos, por id y en el mismo orden (EF-04.2).
            'obras_ids' => [$this->obras['La ciénaga'], $this->obras['Zama']],
        ], $this->json($this->pedir($funcion->id)));
    }

    public function testProgramadaDaLasElegidasYLaFecha(): void
    {
        $funcion = $this->funcion();
        $funcion->cerrarVotacionTitulos([$this->obras['Zama']]);
        $funcion->fijarFechaHora(new DateTimeImmutable('2026-10-24 20:30:00'), new DateTimeImmutable('2026-10-11 18:00:00'));
        $this->funciones->guardar($funcion);

        $datos = $this->json($this->pedir($funcion->id));

        self::assertSame('programada', $datos['estado']);
        self::assertSame('2026-10-24T20:30:00', $datos['fecha_hora']);
        self::assertSame(['Zama'], $datos['titulos']);
        self::assertTrue($datos['titulos_elegidos']);
        self::assertSame([$this->obras['Zama']], $datos['obras_ids']);
        // Cuánto dura una función en la sala: funciones arma con eso la ventana de asistencia (EF-05.2).
        self::assertSame(120, $datos['sala']['duracion_funcion']);
    }

    public function testUnaSalaDeshabilitadaApareceMarcadaYUnaDadaDeBajaNo(): void
    {
        $funcion = $this->funcion();

        $this->base->ejecutar('UPDATE sala SET habilitada = FALSE WHERE id = ?', [$this->sala->id]);
        self::assertFalse($this->json($this->pedir($funcion->id))['sala']['habilitada']);

        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$this->sala->id]);
        self::assertSame(404, $this->pedir($funcion->id)->estado());
    }

    public function testUnaQueNoExisteDa404(): void
    {
        self::assertSame(404, $this->pedir(Uuid::nuevo())->estado());
        self::assertSame(404, $this->pedir('no-es-un-id')->estado());
    }

    /**
     * EF-07.8: moderación la lee para mostrar la función denunciada y actuar sobre su sala o sus
     * obras.
     */
    public function testModeracionTambienLaLee(): void
    {
        $funcion = $this->funcion();

        $respuesta = $this->pedir($funcion->id, 'moderacion', self::TOKEN_DE_MODERACION);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(self::ORGANIZADORA, $this->json($respuesta)['organizador_id']);
    }

    /**
     * Sólo funciones y moderación: cuentas tiene un token válido, pero la ruta no lo acepta.
     */
    public function testSoloLaPideFuncionesConSuToken(): void
    {
        $id = $this->funcion()->id;

        self::assertSame(403, $this->pedir($id, 'cuentas', self::TOKEN_DE_CUENTAS)->estado());
        self::assertSame(403, $this->pedir($id, 'funciones', self::TOKEN_DE_CUENTAS)->estado());
        self::assertSame(403, $this->pedir($id, 'funciones', '')->estado());
    }

    /**
     * «Mis reservas» (E4-04.1) pide las funciones de una página en un solo pedido: cada una con
     * los mismos campos que la ruta de a una, con su id como clave.
     */
    public function testVariasDaCadaUnaConSuIdComoClave(): void
    {
        $votacion = $this->funcion();
        $programada = $this->funcion();
        $programada->cerrarVotacionTitulos([$this->obras['Zama']]);
        $programada->fijarFechaHora(new DateTimeImmutable('2026-10-24 20:30:00'), new DateTimeImmutable('2026-10-11 18:00:00'));
        $this->funciones->guardar($programada);

        $datos = $this->json($this->pedirVarias("{$votacion->id},{$programada->id}"));

        self::assertEqualsCanonicalizing([$votacion->id, $programada->id], array_keys($datos));
        self::assertSame($this->json($this->pedir($votacion->id)), $datos[$votacion->id]);
        self::assertSame($this->json($this->pedir($programada->id)), $datos[$programada->id]);
    }

    public function testVariasNoTraeLasQueNoExistenNiLasDeUnaSalaDadaDeBaja(): void
    {
        $funcion = $this->funcion();
        $otraSala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('Sala Cerrada', 'Cerró.', 'Calle 1', 'Luján', 30, 2, 120, 15), 'sala.jpg');
        $this->salas->agregar($otraSala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$otraSala->id]);
        $deBaja = ($this->salas->buscar($otraSala->id) ?? self::fail('No se guardó la sala.'))->crearFuncion(array_values($this->obras), 20, 0.0);
        $this->funciones->agregar($deBaja);
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$otraSala->id]);

        $datos = $this->json($this->pedirVarias(implode(',', [$funcion->id, Uuid::nuevo(), $deBaja->id])));

        self::assertSame([$funcion->id], array_keys($datos));
    }

    public function testVariasSinNingunaQueExistaEsUnObjetoVacio(): void
    {
        $respuesta = $this->pedirVarias(Uuid::nuevo());

        self::assertSame(200, $respuesta->estado());
        self::assertSame('{}', $respuesta->cuerpo());
    }

    public function testVariasRechazaUnPedidoVacioDemasiadoLargoOConIdsQueNoSonUuid(): void
    {
        $veintiuno = implode(',', array_map(static fn (): string => Uuid::nuevo(), range(1, 21)));
        $veinte = implode(',', array_map(static fn (): string => Uuid::nuevo(), range(1, 20)));

        self::assertSame(400, $this->pedirVarias('')->estado());
        self::assertSame(400, $this->pedirVarias($veintiuno)->estado());
        self::assertSame(400, $this->pedirVarias(Uuid::nuevo() . ',no-es-un-id')->estado());
        self::assertSame(200, $this->pedirVarias($veinte)->estado());
    }

    public function testVariasSoloLasPideFuncionesConSuToken(): void
    {
        $id = $this->funcion()->id;

        self::assertSame(403, $this->pedirVarias($id, 'cuentas', self::TOKEN_DE_CUENTAS)->estado());
        self::assertSame(403, $this->pedirVarias($id, 'funciones', '')->estado());
    }

    // ---- Las próximas, para el recordatorio (EF-03.3) -----------------------------------------

    /**
     * @param array<string, string> $consulta
     */
    private function pedirProximas(array $consulta, string $llamador = 'funciones', string $token = self::TOKEN_DE_FUNCIONES): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', '/interno/funciones/proximas', $consulta, [], [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ], [], []));
    }

    private function programadaEl(string $cuando): string
    {
        $funcion = $this->funcion();
        $funcion->cerrarVotacionTitulos([$this->obras['Zama']]);
        $funcion->fijarFechaHora(new DateTimeImmutable($cuando), new DateTimeImmutable('2026-01-01 00:00:00'));
        $this->funciones->guardar($funcion);

        return $funcion->id;
    }

    public function testProximasDaLasProgramadasDelRangoEnOrden(): void
    {
        $tarde = $this->programadaEl('2026-12-02 21:00:00');
        $temprano = $this->programadaEl('2026-12-02 18:00:00');
        $this->programadaEl('2026-12-04 20:00:00');
        // En votación no tiene fecha: no está.
        $this->funcion();

        $respuesta = $this->pedirProximas(['desde' => '2026-12-01T20:00:00', 'hasta' => '2026-12-02T20:00:00']);
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $lista = json_decode($respuesta->cuerpo(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$temprano], array_column($lista, 'id'));
        self::assertSame('Av. San Martín 450', $lista[0]['sala']['direccion']);

        $hastaLaNoche = json_decode($this->pedirProximas(['desde' => '2026-12-01T20:00:00', 'hasta' => '2026-12-02T22:00:00'])->cuerpo(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$temprano, $tarde], array_column($hastaLaNoche, 'id'));
    }

    public function testProximasPideUnRangoValidoYSoloLaPideFunciones(): void
    {
        self::assertSame(400, $this->pedirProximas([])->estado());
        self::assertSame(400, $this->pedirProximas(['desde' => '2026-12-02T20:00:00', 'hasta' => '2026-12-01T20:00:00'])->estado());
        self::assertSame(400, $this->pedirProximas(['desde' => '2026-12-01T20:00:00', 'hasta' => '2027-01-01T20:00:00'])->estado());
        self::assertSame(400, $this->pedirProximas(['desde' => 'mañana', 'hasta' => '2026-12-01T20:00:00'])->estado());
        self::assertSame(403, $this->pedirProximas(['desde' => '2026-12-01T20:00:00', 'hasta' => '2026-12-02T20:00:00'], 'cuentas', self::TOKEN_DE_CUENTAS)->estado());
    }

    /**
     * @param array<string, string> $consulta
     */
    private function pedirFinalizadas(array $consulta, string $llamador = 'recaudacion', string $token = self::TOKEN_DE_RECAUDACION): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', '/interno/funciones/finalizadas', $consulta, [], [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ], [], []));
    }

    /**
     * Una función que se votó, se programó y terminó: «Zama» le gana a «La ciénaga» por los votos
     * que se pasen, uno por espectador.
     *
     * @param list<string> $votos        el título que votó cada espectador
     * @param string       $finalizadaEn cuándo la finalizó la tarea, que no es cuándo se dio
     */
    private function finalizadaEl(string $cuando, array $votos = [], ?Sala $sala = null, string $finalizadaEn = '2026-12-31 23:00:00'): string
    {
        $funcion = ($sala ?? $this->sala)->crearFuncion(array_values($this->obras), 40, 3500.0);
        $this->funciones->agregar($funcion);
        foreach ($votos as $titulo) {
            $this->base->ejecutar('INSERT INTO voto (funcion_id, usuario_id, obra_id) VALUES (?, ?, ?)', [$funcion->id, Uuid::nuevo(), $this->obras[$titulo]]);
        }
        $funcion->cerrarVotacionTitulos([$this->obras['Zama']]);
        $funcion->fijarFechaHora(new DateTimeImmutable($cuando), new DateTimeImmutable('2026-01-01 00:00:00'));
        $funcion->finalizar(new DateTimeImmutable($finalizadaEn));
        $this->funciones->guardar($funcion);

        return $funcion->id;
    }

    public function testFinalizadasDaLasDelPeriodoConSusObrasYSusVotos(): void
    {
        $conVotos = $this->finalizadaEl('2026-11-04 21:00:00', ['Zama', 'Zama', 'La ciénaga']);
        $ultimoDia = $this->finalizadaEl('2026-11-30 23:30:00');
        $this->finalizadaEl('2026-12-01 00:00:00');
        $this->finalizadaEl('2026-10-31 23:59:00');
        // Programada todavía: no terminó.
        $this->programadaEl('2026-11-10 20:00:00');

        $datos = $this->json($this->pedirFinalizadas(['desde' => '2026-11-01', 'hasta' => '2026-11-30']));

        self::assertTrue($datos['completas']);
        self::assertSame([$conVotos, $ultimoDia], array_column($datos['funciones'], 'id'));
        $funcion = $datos['funciones'][0];
        self::assertSame(['2026-11-04T21:00:00', 40, '3500.00', self::ORGANIZADORA], [$funcion['fecha_hora'], $funcion['cupo'], $funcion['precio_base'], $funcion['organizador_id']]);
        // Cuándo la finalizó la tarea, que es por donde la busca el reparto (EF-08.2).
        self::assertSame('2026-12-31T23:00:00', $funcion['finalizada_en']);
        self::assertSame(['id' => $this->sala->id, 'nombre' => 'Cine Club El Galpón'], $funcion['sala']);
        self::assertSame([
            ['id' => $this->obras['La ciénaga'], 'titulo' => 'La ciénaga', 'dueno_id' => self::ORGANIZADORA, 'elegida' => false, 'votos' => 1],
            ['id' => $this->obras['Zama'], 'titulo' => 'Zama', 'dueno_id' => self::ORGANIZADORA, 'elegida' => true, 'votos' => 2],
        ], $funcion['obras']);
        self::assertCount(3, $funcion['votantes_ids']);
        self::assertSame([], $datos['funciones'][1]['votantes_ids']);
    }

    public function testFinalizadasDeUnOrganizadorODeUnDueno(): void
    {
        $otraSala = Sala::nueva(self::OTRO_ORGANIZADOR, new DatosDeSala('El Cineclub', 'Otro.', 'Mitre 10', 'Luján', 50, 2, 120, 15), 'sala.jpg');
        $this->salas->agregar($otraSala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$otraSala->id]);
        $propia = $this->finalizadaEl('2026-11-04 21:00:00');
        $ajena = $this->finalizadaEl('2026-11-05 21:00:00', [], $this->salas->buscar($otraSala->id));
        $this->base->ejecutar('UPDATE obra SET dueno_id = ? WHERE id = ?', [self::DUENA, $this->obras['La ciénaga']]);
        $periodo = ['desde' => '2026-11-01', 'hasta' => '2026-11-30'];

        self::assertSame([$propia], array_column($this->json($this->pedirFinalizadas([...$periodo, 'organizador' => self::ORGANIZADORA]))['funciones'], 'id'));
        self::assertSame([$ajena], array_column($this->json($this->pedirFinalizadas([...$periodo, 'organizador' => self::OTRO_ORGANIZADOR]))['funciones'], 'id'));
        // La ciénaga perdió en las dos, pero estuvo: su dueña ve las dos.
        self::assertSame([$propia, $ajena], array_column($this->json($this->pedirFinalizadas([...$periodo, 'dueno' => self::DUENA]))['funciones'], 'id'));
        self::assertSame([$propia, $ajena], array_column($this->json($this->pedirFinalizadas($periodo))['funciones'], 'id'));
    }

    /**
     * El cursor de la tarea del reparto (EF-08.2): va por cuándo se finalizó cada una y no por
     * cuándo se dio, que es lo que hace que una función vieja finalizada tarde no se saltee.
     */
    public function testFinalizadasDesdeUnMomentoVanPorLaFinalizacionYNoPorLaFecha(): void
    {
        // La más vieja se finalizó última: con el cursor entra, y va al final.
        $vieja = $this->finalizadaEl('2026-11-04 21:00:00', finalizadaEn: '2026-11-20 10:00:00');
        $antes = $this->finalizadaEl('2026-11-10 21:00:00', finalizadaEn: '2026-11-11 00:15:00');
        $despues = $this->finalizadaEl('2026-11-18 21:00:00', finalizadaEn: '2026-11-19 00:15:00');
        // Programada todavía: no se finalizó, no tiene por dónde entrar.
        $this->programadaEl('2026-11-19 20:00:00');

        $datos = $this->json($this->pedirFinalizadas(['desde' => '2026-11-19T00:15:00']));

        self::assertTrue($datos['completas']);
        self::assertSame([$despues, $vieja], array_column($datos['funciones'], 'id'));
        self::assertSame(
            ['2026-11-18T21:00:00', '2026-11-19T00:15:00'],
            [$datos['funciones'][0]['fecha_hora'], $datos['funciones'][0]['finalizada_en']],
        );
        // Desde antes de todas, las tres, de la primera finalizada a la última.
        self::assertSame(
            [$antes, $despues, $vieja],
            array_column($this->json($this->pedirFinalizadas(['desde' => '2026-11-01T00:00:00']))['funciones'], 'id'),
        );
    }

    /**
     * La tarea del reparto reparte todo el sitio: si llegara con un filtro, repartiría de menos y
     * sin que se note. Mejor un 400.
     */
    public function testFinalizadasDesdeUnMomentoPideUnMomentoYNoAceptaFiltros(): void
    {
        self::assertSame(400, $this->pedirFinalizadas(['desde' => '2026-11-19'])->estado());
        self::assertSame(400, $this->pedirFinalizadas(['desde' => 'anoche'])->estado());
        self::assertSame(400, $this->pedirFinalizadas(['desde' => '2026-11-19T00:15:00', 'organizador' => self::ORGANIZADORA])->estado());
        self::assertSame(400, $this->pedirFinalizadas(['desde' => '2026-11-19T00:15:00', 'dueno' => self::DUENA])->estado());
        self::assertSame(403, $this->pedirFinalizadas(['desde' => '2026-11-19T00:15:00'], 'funciones', self::TOKEN_DE_FUNCIONES)->estado());
    }

    public function testFinalizadasPideUnPeriodoValidoYSoloLaPideRecaudacion(): void
    {
        self::assertSame(400, $this->pedirFinalizadas([])->estado());
        self::assertSame(400, $this->pedirFinalizadas(['desde' => '2026-11-30', 'hasta' => '2026-11-01'])->estado());
        self::assertSame(400, $this->pedirFinalizadas(['desde' => '2026-02-31', 'hasta' => '2026-03-10'])->estado());
        // Más de un año sí sirve: la reportería pide todo cuando no se elige un período (IC-658).
        self::assertSame(200, $this->pedirFinalizadas(['desde' => '2000-01-01', 'hasta' => '2026-11-30'])->estado());
        self::assertSame(400, $this->pedirFinalizadas(['desde' => '2026-11-01', 'hasta' => '2026-11-30', 'organizador' => 'yo'])->estado());
        self::assertSame(403, $this->pedirFinalizadas(['desde' => '2026-11-01', 'hasta' => '2026-11-30'], 'funciones', self::TOKEN_DE_FUNCIONES)->estado());
    }

    public function testInformaSiElUsuarioEstaBloqueadoEnLaSalaDeLaFuncion(): void
    {
        $funcion = $this->funcion();
        $bloqueado = Uuid::nuevo();
        $libre = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO bloqueo (sala_id, usuario_id, motivo, bloqueado_por) VALUES (?, ?, ?, ?)',
            [$this->sala->id, $bloqueado, 'Bloqueo de prueba', self::ORGANIZADORA],
        );

        self::assertTrue($this->json($this->pedir($funcion->id, consulta: ['usuario' => $bloqueado]))['usuario_bloqueado']);
        self::assertFalse($this->json($this->pedir($funcion->id, consulta: ['usuario' => $libre]))['usuario_bloqueado']);
        self::assertArrayNotHasKey('usuario_bloqueado', $this->json($this->pedir($funcion->id)));
        self::assertSame(400, $this->pedir($funcion->id, consulta: ['usuario' => 'no-es-uuid'])->estado());
    }
}
