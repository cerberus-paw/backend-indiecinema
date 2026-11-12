<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use DateTimeImmutable;
use Dom\HTMLDocument;
use IndieCinema\Nucleo\Pruebas\Dobles\Formularios;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Programacion\Controladores\FormularioDeFuncion;
use IndieCinema\Programacion\Controladores\TarjetaDeFuncion;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\OrigenCopia;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Modelo\TipoObra;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;

/**
 * «Mis funciones» y alta para programar una función (E3-11.2) del organizador, de punta a punta.
 */
final class FuncionesDelOrganizadorTest extends TestCase
{
    use ConBaseDePruebas;
    use ConFuncionesDePrueba;

    public static function setUpBeforeClass(): void
    {
        self::setUpBeforeClassFunciones();
    }

    public static function tearDownAfterClass(): void
    {
        self::tearDownAfterClassFunciones();
    }

    private const SECRETO = 'secreto-de-nginx';
    private const CSRF = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const OTRO_ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private RepositorioDeSalas $salas;
    private RepositorioDeFunciones $funciones;

    /** @var array<string, string> los títulos del catálogo y su id */
    private array $obras = [];

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        @unlink(sys_get_temp_dir() . '/acuerdo_estado_global.txt');
        // Funciones contesta la ocupación: ninguna función tiene reservas salvo que la prueba las
        // ponga con ocupados() (F-01.1).
        file_put_contents(sys_get_temp_dir() . '/ocupacion_de_prueba.json', '{}');
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'subsistemas' => [
                'cuentas' => 'http://127.0.0.1:1',
                'funciones' => self::$urlFunciones,
            ],
            'interno' => ['token' => 'token-de-programacion'],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->salas = new RepositorioDeSalas($this->base);
        $this->funciones = new RepositorioDeFunciones($this->base);
        $this->salidaDelLog = fopen('php://memory', 'w');

        $generoId = Uuid::nuevo();
        $this->base->ejecutar('INSERT INTO genero (id, nombre, descripcion) VALUES (?, ?, ?)', [$generoId, 'Drama', 'Obras de drama.']);
        $obras = new RepositorioDeObras($this->base);
        foreach (['Zama', 'La ciénaga'] as $titulo) {
            $obra = Obra::nueva(self::ORGANIZADORA, $titulo, 'Sinopsis.', 2001, 'Lucrecia Martel', 103, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$generoId]);
            $obras->agregar($obra);
            $this->obras[$titulo] = $obra->id;
        }
    }

    protected function tearDown(): void
    {
        @unlink(sys_get_temp_dir() . '/acuerdo_estado_global.txt');
        @unlink(sys_get_temp_dir() . '/ocupacion_de_prueba.json');
    }

    /**
     * Los lugares que funciones dice que están reservados en la función; sin archivo, funciones
     * no contesta la ocupación, como si estuviera caído.
     */
    private static function ocupados(string $funcionId, ?int $ocupados): void
    {
        $archivo = sys_get_temp_dir() . '/ocupacion_de_prueba.json';
        if ($ocupados === null) {
            @unlink($archivo);

            return;
        }
        file_put_contents($archivo, json_encode([$funcionId => $ocupados]));
    }

    private function pedir(
        string $metodo = 'GET',
        string $ruta = '/organizador/funciones',
        ?string $usuario = self::ORGANIZADORA,
        string $rol = 'organizador',
        array $cuerpo = [],
    ): Respuesta {
        $cabeceras = $usuario === null
            ? []
            : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => $usuario, 'X-Rol' => $rol];
        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', $this->salidaDelLog));

        return PaginasParaValidar::anotar($metodo, $ruta, $aplicacion->atender(new Peticion(
            $metodo,
            (string) parse_url($ruta, PHP_URL_PATH),
            $consulta,
            $cuerpo,
            $cabeceras,
            ['csrf' => self::CSRF],
        )));
    }

    private function enviar(string $ruta, array $cuerpo, ?string $usuario = self::ORGANIZADORA, string $rol = 'organizador'): Respuesta
    {
        return $this->pedir('POST', $ruta, $usuario, $rol, ['_csrf' => self::CSRF, ...$cuerpo]);
    }

    /**
     * «2026-11-12 20:00:00»: un día y hora a tantos días de hoy, como las franjas del doble de
     * funciones. Con fechas fijas, las pruebas del acuerdo fallan cuando esas fechas pasan.
     */
    private static function dentroDe(int $dias, string $hora): string
    {
        return (new DateTimeImmutable("+{$dias} days"))->format('Y-m-d') . " {$hora}";
    }

    /**
     * Cómo responde el doble de funciones a «el acuerdo de esta función»: abierto (por omisión,
     * con la franja que lidera en el mínimo), sin_minimo, ninguno (todavía no tiene), caido o
     * pasada (la que lidera ya empezó).
     */
    private static function acuerdoEnFunciones(string $caso): void
    {
        file_put_contents(sys_get_temp_dir() . '/acuerdo_estado_global.txt', $caso);
    }

    /**
     * Una sala guardada, como la lee el repositorio. Queda habilitada, porque sólo en una
     * habilitada se crean funciones: la prueba que la necesita sin habilitar la deshabilita
     * después.
     */
    private function sala(string $organizador, string $nombre, int $capacidad = 60, int $peliculasPorFuncion = 2, int $duracionFuncion = 120): Sala
    {
        $sala = Sala::nueva($organizador, new DatosDeSala($nombre, 'Un galpón.', 'Av. San Martín 450', 'Luján', $capacidad, $peliculasPorFuncion, $duracionFuncion, 15), 'sala.jpg');
        $this->salas->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        return $this->salas->buscar($sala->id) ?? self::fail('No se guardó la sala.');
    }

    /**
     * Una función en votación de títulos, con las dos obras como candidatas si no se dicen otras.
     *
     * @param ?list<string> $candidatas
     */
    private function funcion(Sala $sala, ?array $candidatas = null): Funcion
    {
        $funcion = $sala->crearFuncion($candidatas ?? array_values($this->obras), 40, 3500.0);
        $this->funciones->agregar($funcion);

        return $funcion;
    }

    /**
     * Una obra más de la organizadora, para las votaciones de más de dos candidatas.
     */
    private function obra(string $titulo, int $duracion = 103): string
    {
        $generoId = (string) $this->base->valor('SELECT id FROM genero LIMIT 1');
        $obra = Obra::nueva(self::ORGANIZADORA, $titulo, 'Sinopsis.', 2001, 'Lucrecia Martel', $duracion, TipoObra::Pelicula, OrigenCopia::LicenciaAdquirida, [$generoId]);
        (new RepositorioDeObras($this->base))->agregar($obra);

        return $obra->id;
    }

    /**
     * Las candidatas en el orden en que las lee la base, que es el del desempate.
     *
     * @return list<string>
     */
    private function candidatas(Funcion $funcion): array
    {
        return ($this->funciones->buscar($funcion->id) ?? self::fail('No se encontró la función.'))->obrasIds;
    }

    /**
     * Tantos votos de espectadores distintos para esa obra.
     */
    private function votar(Funcion $funcion, string $obraId, int $votos): void
    {
        for ($i = 0; $i < $votos; ++$i) {
            $this->base->ejecutar('INSERT INTO voto (funcion_id, usuario_id, obra_id) VALUES (?, ?, ?)', [$funcion->id, Uuid::nuevo(), $obraId]);
        }
    }

    /**
     * Una ya programada para esa fecha.
     */
    private function programada(Sala $sala, string $fecha, string $titulo = 'Zama', string $programadaEl = 'now'): Funcion
    {
        $funcion = $this->funcion($sala);
        $funcion->cerrarVotacionTitulos([$this->obras[$titulo]]);
        $this->funciones->guardar($funcion);
        $funcion->fijarFechaHora(new DateTimeImmutable($fecha), new DateTimeImmutable($programadaEl));
        $this->funciones->guardar($funcion);

        return $funcion;
    }

    /**
     * Los ids de las funciones en el orden de la tabla.
     *
     * @return list<string>
     */
    private static function enLaTabla(string $cuerpo): array
    {
        preg_match_all('#<tr data-funcion="([0-9a-f-]{36})">#', $cuerpo, $ids);

        return $ids[1];
    }

    public function testMuestraSoloLasFuncionesDeSusSalas(): void
    {
        $propia = $this->funcion($this->sala(self::ORGANIZADORA, 'El Galpón'));
        $ajena = $this->funcion($this->sala(self::OTRO_ORGANIZADOR, 'El Sótano'));

        $respuesta = $this->pedir('GET', '/organizador/funciones', self::ORGANIZADORA);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertStringContainsString($propia->id, $respuesta->cuerpo());
        self::assertStringContainsString('El Galpón', $respuesta->cuerpo());
        self::assertStringNotContainsString($ajena->id, $respuesta->cuerpo());
        self::assertStringNotContainsString('El Sótano', $respuesta->cuerpo());
    }

    public function testMuestraElEstadoYLaFechaDeCadaFuncion(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $this->programada($sala, '2030-09-19 20:30:00');
        $this->funcion($sala);

        $cuerpo = $this->pedir('GET', '/organizador/funciones', self::ORGANIZADORA)->cuerpo();

        // La programada, con la fecha escrita como en la cartelera y su título ya elegido.
        self::assertStringContainsString('Jue 19 sep · 20:30', $cuerpo);
        self::assertStringContainsString('programada', $cuerpo);
        // La que sigue en votación: sin fecha y con las candidatas.
        self::assertStringContainsString('Sin fecha todavía', $cuerpo);
        self::assertStringContainsString('en votación de títulos', $cuerpo);
        self::assertStringContainsString('La ciénaga + Zama', $cuerpo);
        self::assertStringContainsString('títulos candidatos', $cuerpo);
    }

    public function testPrimeroLasQueSiguenEnCursoYDespuesLasTerminadas(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $dentroDeDosSemanas = $this->programada($sala, '2030-09-26 20:30:00');
        $dentroDeUnaSemana = $this->programada($sala, '2030-09-19 20:30:00');
        $enVotacion = $this->funcion($sala);
        $yaPaso = $this->programada($sala, '2020-03-05 20:30:00', programadaEl: '2020-01-01');
        $finalizada = $this->programada($sala, '2021-06-10 20:30:00', programadaEl: '2021-01-01');
        $finalizada->finalizar();
        $this->funciones->guardar($finalizada);
        $cancelada = $this->programada($sala, '2030-10-03 20:30:00');
        $cancelada->cancelar();
        $this->funciones->guardar($cancelada);

        self::assertSame(
            [$dentroDeUnaSemana->id, $dentroDeDosSemanas->id, $enVotacion->id, $cancelada->id, $finalizada->id, $yaPaso->id],
            self::enLaTabla($this->pedir('GET', '/organizador/funciones', self::ORGANIZADORA)->cuerpo()),
        );
    }

    public function testLaCanceladaSigueEnElPanel(): void
    {
        $funcion = $this->programada($this->sala(self::ORGANIZADORA, 'El Galpón'), '2030-09-19 20:30:00');
        $funcion->cancelar();
        $this->funciones->guardar($funcion);

        $cuerpo = $this->pedir('GET', '/organizador/funciones', self::ORGANIZADORA)->cuerpo();

        self::assertStringContainsString($funcion->id, $cuerpo);
        self::assertStringContainsString('cancelada', $cuerpo);
    }

    public function testLaFuncionDeUnaSalaSinHabilitarNoEnlazaASuFicha(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-09-19 20:30:00');
        $this->base->ejecutar('UPDATE sala SET habilitada = FALSE WHERE id = ?', [$sala->id]);

        $cuerpo = $this->pedir('GET', '/organizador/funciones', self::ORGANIZADORA)->cuerpo();

        self::assertStringContainsString($funcion->id, $cuerpo);
        self::assertStringContainsString('esperando habilitación', $cuerpo);
        self::assertStringNotContainsString("/cartelera/{$funcion->id}", $cuerpo);
    }

    public function testLaFuncionDeUnaSalaDadaDeBajaNoAparece(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$sala->id]);

        self::assertStringNotContainsString($funcion->id, $this->pedir('GET', '/organizador/funciones', self::ORGANIZADORA)->cuerpo());
    }

    public function testSinFuncionesLoDice(): void
    {
        $this->funcion($this->sala(self::OTRO_ORGANIZADOR, 'El Sótano'));

        self::assertStringContainsString('Todavía no tenés ninguna función en tus salas.', $this->pedir('GET', '/organizador/funciones', self::ORGANIZADORA)->cuerpo());
    }

    public function testUnEspectadorNoEntraYSinSesionHayQueIngresar(): void
    {
        $this->funcion($this->sala(self::ORGANIZADORA, 'El Galpón'));

        self::assertSame(403, $this->pedir('GET', '/organizador/funciones', self::ORGANIZADORA, 'espectador')->estado());
        self::assertSame(401, $this->pedir('GET', '/organizador/funciones', null)->estado());
    }

    // =========================================================================
    // PRUEBAS DE E3-11.2 (ALTA DE FUNCIÓN)
    // =========================================================================

    public function testElFormularioCargaLasSalasHabilitadasYElCatalogo(): void
    {
        $propiaHabilitada = $this->sala(self::ORGANIZADORA, 'El Galpón Habilitado');
        $propiaSinHabilitar = $this->sala(self::ORGANIZADORA, 'El Galpón Pendiente');
        $this->base->ejecutar('UPDATE sala SET habilitada = FALSE WHERE id = ?', [$propiaSinHabilitar->id]);
        $ajena = $this->sala(self::OTRO_ORGANIZADOR, 'Sala Ajena');

        $respuesta = $this->pedir('GET', '/organizador/funciones/nueva', self::ORGANIZADORA);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('Programar una función', $cuerpo);
        // Debe listar la sala propia habilitada
        self::assertStringContainsString('El Galpón Habilitado', $cuerpo);
        // NO debe listar la sala propia deshabilitada ni la ajena
        self::assertStringNotContainsString('El Galpón Pendiente', $cuerpo);
        self::assertStringNotContainsString('Sala Ajena', $cuerpo);
        // Debe mostrar las obras del catálogo
        self::assertStringContainsString('Zama', $cuerpo);
        self::assertStringContainsString('La ciénaga', $cuerpo);
        // Los parámetros que hereda la función, de la sala y no escritos en la plantilla
        self::assertStringContainsString("data-sala=\"{$propiaHabilitada->id}\"", $cuerpo);
        self::assertMatchesRegularExpression(
            '#<dt>Tiempo entre funciones</dt>\s*<dd>' . $propiaHabilitada->datos->tiempoEntreFunciones . ' min</dd>#',
            $cuerpo,
        );
    }

    /**
     * El navegador no sugiere fechas de otras funciones (E4-10.5); y si el envío vuelve vacío, el
     * resumen lleva a cada campo, también a los grupos sin un control propio, como las obras.
     */
    public function testLaFechaNoSeAutocompletaYElResumenLlevaACadaCampo(): void
    {
        $this->sala(self::ORGANIZADORA, 'El Galpón Habilitado');

        $campos = Formularios::autocompletado($this->pedir('GET', '/organizador/funciones/nueva', self::ORGANIZADORA)->cuerpo());
        $respuesta = $this->enviar('/organizador/funciones/nueva', [], self::ORGANIZADORA);

        self::assertSame('off', $campos['fecha']);
        self::assertSame(422, $respuesta->estado());
        $resumen = Formularios::resumen($respuesta->cuerpo());
        self::assertNotNull($resumen);
        self::assertContains('#obras', array_keys($resumen['enlaces']));
        self::assertContains('#sala_id', array_keys($resumen['enlaces']));
        self::assertSame('Revisá ' . count($resumen['enlaces']) . ' campos', $resumen['titulo']);
        foreach (array_keys($resumen['enlaces']) as $enlace) {
            self::assertStringContainsString('id="' . substr($enlace, 1) . '"', $respuesta->cuerpo(), "El link {$enlace} no lleva a ningún lado.");
        }
    }

    /**
     * E4-09.4: en un campo numérico Chromium descarta la coma al tipear «1500,50» y lo deja en
     * 150050 sin decir nada, así que el organizador carga $150.050 en vez de $1.500,50. El precio
     * va en un campo de texto, donde el navegador sí aplica el pattern que manda reglas(), con
     * inputmode="decimal" para que el celular siga abriendo el teclado numérico.
     */
    public function testElPrecioEsUnCampoDeTextoDecimalConElPatronDelServidor(): void
    {
        $this->sala(self::ORGANIZADORA, 'El Galpón');

        $cuerpo = $this->pedir('GET', '/organizador/funciones/nueva', self::ORGANIZADORA)->cuerpo();

        self::assertSame(1, preg_match('#<input[^>]*name="precio"[^>]*>#', $cuerpo, $campo), 'el campo del precio');
        self::assertStringContainsString('type="text"', $campo[0]);
        self::assertStringContainsString('inputmode="decimal"', $campo[0]);
        self::assertStringContainsString('pattern="[0-9]{1,8}([.,][0-9]{1,2})?"', $campo[0]);
        // min y step no valen en un campo de texto: si volvieran, volvería el campo numérico.
        self::assertStringNotContainsString(' min="', $campo[0]);
        self::assertStringNotContainsString(' step="', $campo[0]);
        // Y el texto que la validación en línea va a mostrar es el que contesta el servidor.
        self::assertStringContainsString(
            'data-error-patron="El precio tiene que ser un número de hasta 99.999.999,99, sin puntos de miles."',
            $campo[0],
        );
    }

    public function testUnEspectadorNoVeElFormularioDeAltaYSinSesionPideIngresar(): void
    {
        self::assertSame(403, $this->pedir('GET', '/organizador/funciones/nueva', self::ORGANIZADORA, 'espectador')->estado());
        self::assertSame(401, $this->pedir('GET', '/organizador/funciones/nueva', null)->estado());
        self::assertSame(403, $this->enviar('/organizador/funciones/nueva', [], self::ORGANIZADORA, 'espectador')->estado());
        self::assertSame(401, $this->enviar('/organizador/funciones/nueva', [], null)->estado());
    }

    public function testProgramarUnaFuncionExitosa(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón', capacidad: 80);
        $obraId = $this->obras['Zama'];

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'sala_id' => $sala->id,
            'obras' => [$obraId],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '60',
        ]);

        self::assertSame(303, $respuesta->estado());
        self::assertMatchesRegularExpression('#^/organizador/funciones\?programada=[0-9a-f-]{36}$#', $respuesta->cabecera('Location'));

        // Se verifica en la base de datos
        $funcionFila = $this->base->fila(
            'SELECT f.id, f.sala_id, f.estado, f.fecha_hora, f.precio_base, f.cupo
             FROM funcion f WHERE f.sala_id = ?',
            [$sala->id],
        );
        self::assertNotNull($funcionFila);
        self::assertSame('programada', $funcionFila['estado']);
        self::assertSame('2030-10-25 20:30:00', $funcionFila['fecha_hora']);
        self::assertSame('2500.00', $funcionFila['precio_base']);
        self::assertSame(60, (int) $funcionFila['cupo']);

        // Las obras de la función quedan con estado 'elegida'
        $obraFila = $this->base->fila(
            'SELECT obra_id, estado FROM funcion_obra WHERE funcion_id = ?',
            [$funcionFila['id']],
        );
        self::assertSame($obraId, $obraFila['obra_id']);
        self::assertSame('elegida', $obraFila['estado']);
    }

    public function testCrearUnaFuncionEnVotacionDeTitulosExitosa(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón', capacidad: 80);
        $obrasIds = array_values($this->obras);

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'modo' => FormularioDeFuncion::MODO_EN_VOTACION_TITULOS,
            'sala_id' => $sala->id,
            'obras' => $obrasIds,
            'cierre_fecha' => '2030-10-20',
            'cierre_hora' => '23:00',
            'precio' => '2500',
            'cupo' => '60',
        ]);

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/organizador/funciones', $respuesta->cabecera('Location'));

        $funcionFila = $this->base->fila(
            'SELECT id, estado, fecha_hora, votacion_cierra_en, precio_base, cupo FROM funcion WHERE sala_id = ?',
            [$sala->id],
        );
        self::assertNotNull($funcionFila);
        self::assertSame(EstadoFuncion::EnVotacionTitulos->value, $funcionFila['estado']);
        self::assertNull($funcionFila['fecha_hora']);
        self::assertSame('2030-10-20 23:00:00', $funcionFila['votacion_cierra_en']);
        self::assertSame('2500.00', $funcionFila['precio_base']);
        self::assertSame(60, (int) $funcionFila['cupo']);

        $obrasFila = $this->base->filas(
            'SELECT obra_id, estado FROM funcion_obra WHERE funcion_id = ? ORDER BY obra_id',
            [$funcionFila['id']],
        );
        sort($obrasIds);
        self::assertSame($obrasIds, array_column($obrasFila, 'obra_id'));
        self::assertSame(['candidata', 'candidata'], array_column($obrasFila, 'estado'));
    }

    /**
     * Criterio de aceptación E3-11.2: «Una función nueva aparece en la cartelera».
     */
    public function testLaFuncionProgramadaApareceEnLaCartelera(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'Cine Club El Galpón', capacidad: 50);
        $obraId = $this->obras['Zama'];

        $respuestaCrear = $this->enviar('/organizador/funciones/nueva', [
            'sala_id' => $sala->id,
            'obras' => [$obraId],
            'fecha' => '2030-11-12',
            'hora' => '21:00',
            'precio' => '1800',
            'cupo' => '45',
        ]);
        self::assertSame(303, $respuestaCrear->estado());

        // Se consulta la cartelera pública sin sesión
        $respuestaCartelera = $this->pedir('GET', '/cartelera', null);
        self::assertSame(200, $respuestaCartelera->estado());
        $cuerpoCartelera = $respuestaCartelera->cuerpo();

        // Debe aparecer la función con su obra, su sala y su precio
        self::assertStringContainsString('Zama', $cuerpoCartelera);
        self::assertStringContainsString('Cine Club El Galpón', $cuerpoCartelera);
        self::assertStringContainsString('$ 1.800', $cuerpoCartelera);
    }

    public function testRechazaSiLaSalaNoPerteneceAlOrganizador(): void
    {
        $this->sala(self::ORGANIZADORA, 'Mi Sala Habilitada');
        $ajena = $this->sala(self::OTRO_ORGANIZADOR, 'El Sótano');

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'sala_id' => $ajena->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '40',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('La sala seleccionada no es válida o no está habilitada.', $respuesta->cuerpo());
    }

    public function testRechazaSiLaSalaNoEstaHabilitada(): void
    {
        $this->sala(self::ORGANIZADORA, 'Mi Sala Habilitada');
        $deshabilitada = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $this->base->ejecutar('UPDATE sala SET habilitada = FALSE WHERE id = ?', [$deshabilitada->id]);

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'sala_id' => $deshabilitada->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '40',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('La sala seleccionada no es válida o no está habilitada.', $respuesta->cuerpo());
    }

    public function testRechazaSiElOrganizadorNoTieneSalasHabilitadas(): void
    {
        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'sala_id' => '00000000-0000-4000-8000-000000000000',
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '40',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('No tenés salas habilitadas', $respuesta->cuerpo());
    }

    public function testRechazaSinObras(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'sala_id' => $sala->id,
            'obras' => [],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '40',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Tenés que elegir al menos una obra del catálogo.', $respuesta->cuerpo());
    }

    /**
     * E4-09.3: el error de las obras está escrito fuera del parcial de campo; cada casilla lo
     * apunta con aria-describedby, así el lector de pantalla lo lee al pararse en una obra y no
     * sólo cuando aparece.
     */
    public function testSinObrasCadaCasillaDescribeElError(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'sala_id' => $sala->id,
            'obras' => [],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '40',
        ]);

        $documento = HTMLDocument::createFromString($respuesta->cuerpo(), LIBXML_NOERROR);
        self::assertStringContainsString('Tenés que elegir al menos una obra del catálogo.', (string) $documento->getElementById('error-obras')?->textContent);
        $casillas = $documento->querySelectorAll('input[name="obras[]"]');
        self::assertGreaterThan(0, $casillas->length);
        foreach ($casillas as $casilla) {
            self::assertSame('error-obras', $casilla->getAttribute('aria-describedby'));
            self::assertSame('true', $casilla->getAttribute('aria-invalid'));
        }
    }

    public function testRechazaFechaPasada(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2020-01-01',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '40',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('La fecha de la función tiene que ser futura.', $respuesta->cuerpo());
    }

    public function testProgramadaSigueRequiriendoFechaYHora(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'modo' => FormularioDeFuncion::MODO_PROGRAMADA,
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'precio' => '2500',
            'cupo' => '40',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Falta la fecha de la función.', $respuesta->cuerpo());
        self::assertStringContainsString('Falta el horario de la función.', $respuesta->cuerpo());
    }

    public function testVotacionDeTitulosPermiteMasObrasCandidatasQueElLimiteDePeliculasPorFuncionDeLaSala(): void
    {
        $sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('Microcine', 'Sala chica.', 'Av. Mitre 100', 'Luján', 40, 1, 120, 15), 'sala.jpg');
        $this->salas->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        $obrasIds = array_values($this->obras);

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'modo' => FormularioDeFuncion::MODO_EN_VOTACION_TITULOS,
            'sala_id' => $sala->id,
            'obras' => $obrasIds,
            'cierre_fecha' => '2030-10-20',
            'cierre_hora' => '23:00',
            'precio' => '2000',
            'cupo' => '30',
        ]);

        self::assertSame(303, $respuesta->estado());
        self::assertSame('/organizador/funciones', $respuesta->cabecera('Location'));

        $funcionId = (string) $this->base->valor('SELECT id FROM funcion WHERE sala_id = ?', [$sala->id]);
        $candidatas = array_column(
            $this->base->filas('SELECT obra_id FROM funcion_obra WHERE funcion_id = ? AND estado = "candidata"', [$funcionId]),
            'obra_id',
        );
        self::assertCount(2, $candidatas);
    }

    /**
     * F-01.6: con una sola candidata no hay nada que votar. Vuelve el formulario con el motivo y no
     * se crea ninguna función.
     */
    public function testUnaVotacionNecesitaAlMenosDosCandidatas(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'modo' => FormularioDeFuncion::MODO_EN_VOTACION_TITULOS,
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'cierre_fecha' => '2030-10-20',
            'cierre_hora' => '23:00',
            'precio' => '2500',
            'cupo' => '40',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Para abrir una votación elegí al menos dos obras candidatas.', $respuesta->cuerpo());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM funcion WHERE sala_id = ?', [$sala->id]));
    }

    /**
     * EF-05.4: cada candidata, sola, tiene que entrar en la duración de la función de la sala; si
     * no, ganaría una obra que no se puede proyectar. Juntas pueden pasarse: al cerrar quedan las
     * que entran.
     */
    public function testUnaCandidataMasLargaQueLaSalaNoAbreLaVotacion(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'modo' => FormularioDeFuncion::MODO_EN_VOTACION_TITULOS,
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama'], $this->obra('Rojo', 130)],
            'cierre_fecha' => '2030-10-20',
            'cierre_hora' => '23:00',
            'precio' => '2500',
            'cupo' => '40',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Rojo dura 130 minutos y en esta sala una función puede durar hasta 120.', $respuesta->cuerpo());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM funcion WHERE sala_id = ?', [$sala->id]));
    }

    /**
     * EF-03.1: la votación cierra sola en su fecha, así que abrirla sin decir cuándo cierra, o con
     * un cierre que ya pasó, vuelve al formulario con el motivo y no crea nada.
     *
     * @param array<string, string> $cierre
     */
    #[DataProvider('cierresQueNoSirven')]
    public function testUnaVotacionNecesitaUnCierreFuturo(array $cierre, string $motivo): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'modo' => FormularioDeFuncion::MODO_EN_VOTACION_TITULOS,
            'sala_id' => $sala->id,
            'obras' => array_values($this->obras),
            ...$cierre,
            'precio' => '2500',
            'cupo' => '40',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString($motivo, $respuesta->cuerpo());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM funcion WHERE sala_id = ?', [$sala->id]));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function cierresQueNoSirven(): array
    {
        return [
            'sin día' => [['cierre_hora' => '23:00'], 'Falta el día en que cierra la votación.'],
            'sin hora' => [['cierre_fecha' => '2030-10-20'], 'Falta la hora en que cierra la votación.'],
            'pasado' => [['cierre_fecha' => '2020-01-01', 'cierre_hora' => '10:00'], 'El cierre de la votación tiene que ser futuro.'],
            'día que no existe' => [['cierre_fecha' => '2030-04-31', 'cierre_hora' => '10:00'], 'El día y la hora del cierre no tienen un formato válido.'],
        ];
    }

    /**
     * Una función programada no tiene votación: el cierre que llegue, por ejemplo porque sin
     * JavaScript se ven los dos bloques, no se pide ni se guarda.
     */
    public function testUnaFuncionProgramadaNoGuardaCierreDeVotacion(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'modo' => FormularioDeFuncion::MODO_PROGRAMADA,
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'cierre_fecha' => '2020-01-01',
            'precio' => '2500',
            'cupo' => '40',
        ]);

        self::assertSame(303, $respuesta->estado());
        self::assertNull($this->base->valor('SELECT votacion_cierra_en FROM funcion WHERE sala_id = ?', [$sala->id]));
    }

    /**
     * F-01.6: un modo que no es ninguno de los dos vuelve con el error, y no se publica como
     * programada sin avisar, aunque el resto esté bien.
     */
    public function testUnModoDesconocidoNoSePublica(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'modo' => 'sorpresa',
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '40',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Elegí si la función se programa con fecha o se abre a votación de títulos.', $respuesta->cuerpo());
        self::assertSame(0, (int) $this->base->valor('SELECT COUNT(*) FROM funcion WHERE sala_id = ?', [$sala->id]));
    }

    public function testRechazaCupoMayorQueCapacidad(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón', capacidad: 50);

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '75',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('El cupo no puede ser mayor a la capacidad de la sala (50).', $respuesta->cuerpo());
    }

    public function testRechazaPrecioNegativo(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '-500',
            'cupo' => '40',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('El precio no puede ser negativo.', $respuesta->cuerpo());
    }

    public function testRechazaSinCsrf(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->pedir('POST', '/organizador/funciones/nueva', self::ORGANIZADORA, 'organizador', [
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            '_csrf' => 'csrf-invalido',
        ]);

        self::assertSame(403, $respuesta->estado());
    }
    /**
     * Manda el alta de una función con una obra, salvo lo que se le cambie.
     *
     * @param array<string, mixed> $campos
     */
    private function programar(Sala $sala, array $campos = []): Respuesta
    {
        return $this->enviar('/organizador/funciones/nueva', $campos + [
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '40',
        ]);
    }

    private function cuantasFunciones(Sala $sala): int
    {
        return (int) $this->base->valor('SELECT COUNT(*) FROM funcion WHERE sala_id = ?', [$sala->id]);
    }

    public function testRechazaMasObrasDeLasQueLaSalaPasaPorFuncion(): void
    {
        // Una película por función, y tiempo de sobra para las dos.
        $sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala('El Microcine', 'Una sala chica.', 'Mitre 10', 'Luján', 60, 1, 300, 15), 'sala.jpg');
        $this->salas->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        $respuesta = $this->programar($sala, ['obras' => array_values($this->obras)]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('En esta sala entran hasta 1 películas por función.', $respuesta->cuerpo());
        self::assertSame(0, $this->cuantasFunciones($sala));
    }

    public function testRechazaObrasQueDuranMasQueLaFuncionDeLaSala(): void
    {
        // La sala de las pruebas admite dos películas y 120 minutos; las dos obras suman 206.
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->programar($sala, ['obras' => array_values($this->obras)]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Las obras duran 206 minutos y en esta sala una función puede durar hasta 120.', $respuesta->cuerpo());
        self::assertSame(0, $this->cuantasFunciones($sala));
    }

    /**
     * Con una función de 20:30 a 22:13 (103 minutos) y 15 minutos entre funciones.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function horariosContraOtraFuncion(): iterable
    {
        yield 'a la misma hora' => ['20:30', false];
        yield 'empieza mientras la otra sigue' => ['21:00', false];
        yield 'empieza sin dejar la pausa' => ['22:20', false];
        yield 'empieza justo con la pausa' => ['22:28', true];
        yield 'termina sin dejar la pausa antes' => ['18:40', false];
        yield 'termina justo con la pausa antes' => ['18:32', true];
    }

    #[DataProvider('horariosContraOtraFuncion')]
    public function testNoSePisaConOtraFuncionDeLaSala(string $hora, bool $entra): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        self::assertSame(303, $this->programar($sala)->estado());

        $respuesta = $this->programar($sala, ['hora' => $hora, 'obras' => [$this->obras['La ciénaga']]]);

        if ($entra) {
            self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
            self::assertSame(2, $this->cuantasFunciones($sala));
        } else {
            // Vuelve al formulario con el motivo, no un error del servidor.
            self::assertSame(422, $respuesta->estado());
            self::assertStringContainsString('Se pisa con otra función de la sala, que va de 20:30 a 22:13', $respuesta->cuerpo());
            self::assertSame(1, $this->cuantasFunciones($sala));
        }
    }

    public function testEnOtraSalaOEnOtroDiaNoSePisa(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $otra = $this->sala(self::ORGANIZADORA, 'El Sótano');
        self::assertSame(303, $this->programar($sala)->estado());

        self::assertSame(303, $this->programar($otra)->estado());
        self::assertSame(303, $this->programar($sala, ['fecha' => '2030-10-26'])->estado());
    }

    public function testUnaFuncionCanceladaNoOcupaLaSala(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        self::assertSame(303, $this->programar($sala)->estado());
        $this->base->ejecutar("UPDATE funcion SET estado = 'cancelada' WHERE sala_id = ?", [$sala->id]);

        self::assertSame(303, $this->programar($sala)->estado());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function preciosQueNoEntran(): iterable
    {
        yield 'con exponente' => ['1e12'];
        yield 'nueve cifras' => ['100000000'];
        yield 'tres decimales' => ['2500.125'];
        yield 'con puntos de miles' => ['2.500.000'];
        yield 'con letras' => ['dos mil'];
    }

    #[DataProvider('preciosQueNoEntran')]
    public function testRechazaUnPrecioQueNoEntraEnLaColumna(string $precio): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->programar($sala, ['precio' => $precio]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('El precio tiene que ser un número de hasta 99.999.999,99', $respuesta->cuerpo());
        self::assertSame(0, $this->cuantasFunciones($sala));
    }

    public function testAceptaElPrecioConComaYElMasAlto(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        self::assertSame(303, $this->programar($sala, ['precio' => '2500,50'])->estado());
        self::assertSame(303, $this->programar($sala, ['precio' => '99999999.99', 'fecha' => '2030-10-26'])->estado());
        self::assertSame(
            ['2500.50', '99999999.99'],
            array_column($this->base->filas('SELECT precio_base FROM funcion WHERE sala_id = ? ORDER BY precio_base', [$sala->id]), 'precio_base'),
        );
    }

    public function testElAvisoDeProgramadaSoloConUnIdDeVerdad(): void
    {
        $this->sala(self::ORGANIZADORA, 'El Galpón');

        self::assertStringContainsString('La función quedó programada', $this->pedir('GET', '/organizador/funciones?programada=' . Uuid::nuevo())->cuerpo());
        self::assertStringNotContainsString('La función quedó programada', $this->pedir('GET', '/organizador/funciones?programada=cualquiera')->cuerpo());
        self::assertStringNotContainsString('La función quedó programada', $this->pedir('GET', '/organizador/funciones')->cuerpo());
    }

    /**
     * Manda la actualización de una función, salvo lo que se le cambie.
     *
     * @param array<string, mixed> $campos
     */
    private function actualizar(string $id, Sala $sala, array $campos = []): Respuesta
    {
        return $this->enviar("/organizador/funciones/{$id}/editar", $campos + [
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '40',
        ]);
    }

    public function testFormularioEdicionRequiereRolOrganizador(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');

        // Sin sesión
        self::assertSame(401, $this->pedir('GET', "/organizador/funciones/{$funcion->id}/editar", null)->estado());
        // Como espectador
        self::assertSame(403, $this->pedir('GET', "/organizador/funciones/{$funcion->id}/editar", self::ORGANIZADORA, 'espectador')->estado());
    }

    public function testFormularioEdicionDevuelve404SiNoExisteOEsAjena(): void
    {
        $salaAjena = $this->sala(self::OTRO_ORGANIZADOR, 'El Sótano');
        $funcionAjena = $this->programada($salaAjena, '2030-10-25 20:30:00');

        // Id inexistente
        self::assertSame(404, $this->pedir('GET', '/organizador/funciones/' . Uuid::nuevo() . '/editar')->estado());
        // Id no UUID
        self::assertSame(404, $this->pedir('GET', '/organizador/funciones/no-uuid/editar')->estado());
        // Función de otro organizador
        self::assertSame(404, $this->pedir('GET', "/organizador/funciones/{$funcionAjena->id}/editar")->estado());
    }

    /**
     * Sólo se edita una función programada que todavía no se dio. Una en votación (de títulos o de
     * fecha) no tiene nada que editar, y una cancelada, finalizada o con la fecha ya pasada ya
     * terminó: ni se abre el formulario ni se guarda, aunque se escriba la dirección a mano, y ni el
     * panel ni la ficha muestran el botón.
     */
    public function testSoloSeEditaUnaFuncionProgramada(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $enVotacion = $this->funcion($sala);

        $enAcuerdo = $this->funcion($sala);
        $enAcuerdo->cerrarVotacionTitulos([$this->obras['Zama']]);
        $this->funciones->guardar($enAcuerdo);

        $cancelada = $this->programada($sala, '2030-10-25 20:30:00');
        $cancelada->cancelar();
        $this->funciones->guardar($cancelada);

        // Finalizar pide que la función ya se haya dado: se la programa y se la finaliza con un
        // reloj posterior a su fecha.
        $finalizada = $this->programada($sala, '2030-11-20 21:00:00', programadaEl: '2030-11-19 10:00:00');
        $finalizada->finalizar(new DateTimeImmutable('2030-11-21 10:00:00'));
        $this->funciones->guardar($finalizada);

        // Programada, pero su fecha ya pasó: se proyectó aunque nadie la haya finalizado.
        $yaSeDio = $this->programada($sala, '2020-05-01 20:30:00', programadaEl: '2020-04-01 10:00:00');

        $programada = $this->programada($sala, '2030-12-05 20:30:00');

        $noEditables = [
            'en votación de títulos' => $enVotacion,
            'en acuerdo de fecha' => $enAcuerdo,
            'cancelada' => $cancelada,
            'finalizada' => $finalizada,
            'programada que ya se dio' => $yaSeDio,
        ];
        foreach ($noEditables as $caso => $funcion) {
            self::assertSame(404, $this->pedir('GET', "/organizador/funciones/{$funcion->id}/editar")->estado(), $caso);
            self::assertSame(404, $this->actualizar($funcion->id, $sala, ['cupo' => '10'])->estado(), $caso);
        }

        // Y ninguna cambió: ni el cupo ni la fecha, que en votación sigue sin estar.
        foreach ($noEditables as $caso => $funcion) {
            $fila = $this->base->fila('SELECT cupo, fecha_hora FROM funcion WHERE id = ?', [$funcion->id]) ?? self::fail('No está la función.');
            self::assertSame(40, (int) $fila['cupo'], $caso);
            self::assertSame($funcion->fechaHora?->format('Y-m-d H:i:s'), $fila['fecha_hora'], $caso);
        }

        // En el panel, el botón de editar sólo está en la programada.
        $panel = $this->pedir('GET', '/organizador/funciones')->cuerpo();
        self::assertStringContainsString("/organizador/funciones/{$programada->id}/editar", $panel);
        foreach ($noEditables as $caso => $funcion) {
            self::assertStringNotContainsString("/organizador/funciones/{$funcion->id}/editar", $panel, $caso);
        }

        // Y en la ficha, aunque la mire su organizadora.
        foreach ($noEditables as $caso => $funcion) {
            $ficha = $this->pedir('GET', "/cartelera/{$funcion->id}");
            self::assertSame(200, $ficha->estado(), $caso);
            self::assertStringNotContainsString('Editar función', $ficha->cuerpo(), $caso);
        }
    }

    public function testFormularioEdicionMuestraDatosPrecargadosYParametros(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');

        $respuesta = $this->pedir('GET', "/organizador/funciones/{$funcion->id}/editar");

        self::assertSame(200, $respuesta->estado());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('Editar función', $cuerpo);
        self::assertStringContainsString("/organizador/funciones/{$funcion->id}/editar", $cuerpo);
        self::assertStringContainsString('El Galpón', $cuerpo);
        self::assertStringContainsString('2030-10-25', $cuerpo);
        self::assertStringContainsString('20:30', $cuerpo);
        self::assertStringContainsString('3500', $cuerpo);
        self::assertStringContainsString('40', $cuerpo);
    }

    public function testActualizarRequierePermisosYCsrf(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');

        // Sin sesión
        $sinSesion = $this->pedir('POST', "/organizador/funciones/{$funcion->id}/editar", null, 'organizador', [
            '_csrf' => self::CSRF,
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-26',
            'hora' => '20:30',
            'precio' => '3000',
            'cupo' => '35',
        ]);
        self::assertSame(401, $sinSesion->estado());

        // Con rol espectador
        $espectador = $this->pedir('POST', "/organizador/funciones/{$funcion->id}/editar", self::ORGANIZADORA, 'espectador', [
            '_csrf' => self::CSRF,
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-26',
            'hora' => '20:30',
            'precio' => '3000',
            'cupo' => '35',
        ]);
        self::assertSame(403, $espectador->estado());

        // Sin CSRF válido
        $sinCsrf = $this->pedir('POST', "/organizador/funciones/{$funcion->id}/editar", self::ORGANIZADORA, 'organizador', [
            '_csrf' => 'token-invalido',
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-26',
            'hora' => '20:30',
            'precio' => '3000',
            'cupo' => '35',
        ]);
        self::assertSame(403, $sinCsrf->estado());

        // Función ajena
        $salaAjena = $this->sala(self::OTRO_ORGANIZADOR, 'El Sótano');
        $funcionAjena = $this->programada($salaAjena, '2030-10-25 20:30:00');
        $ajena = $this->actualizar($funcionAjena->id, $sala);
        self::assertSame(404, $ajena->estado());
    }

    public function testActualizarRechazaDatosInvalidos(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón', capacidad: 50);
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');

        // Precio negativo
        $precioNegativo = $this->actualizar($funcion->id, $sala, ['precio' => '-100']);
        self::assertSame(422, $precioNegativo->estado());
        self::assertStringContainsString('El precio no puede ser negativo.', $precioNegativo->cuerpo());

        // Cupo superior a capacidad
        $cupoExcedido = $this->actualizar($funcion->id, $sala, ['cupo' => '80']);
        self::assertSame(422, $cupoExcedido->estado());
        self::assertStringContainsString('El cupo no puede ser mayor a la capacidad de la sala (50).', $cupoExcedido->cuerpo());

        // Fecha pasada
        $fechaPasada = $this->actualizar($funcion->id, $sala, ['fecha' => '2020-01-01']);
        self::assertSame(422, $fechaPasada->estado());
        self::assertStringContainsString('La fecha de la función tiene que ser futura.', $fechaPasada->cuerpo());
    }

    public function testActualizarMismaFuncionNoColisionaConsigoMisma(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');

        // Modificamos precio y cupo pero mantenemos la misma sala, fecha y hora
        $respuesta = $this->actualizar($funcion->id, $sala, [
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '4900',
            'cupo' => '45',
        ]);

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/cartelera/{$funcion->id}?guardada=1", $respuesta->cabecera('Location'));
    }

    public function testActualizarRechazaColisionConOtraFuncion(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        // Función 1 de 20:30 a 22:13
        $this->programada($sala, '2030-10-25 20:30:00');
        // Función 2 en otro horario
        $funcion2 = $this->programada($sala, '2030-10-25 23:00:00');

        // Intentamos mover función 2 para que se pise con función 1
        $respuesta = $this->actualizar($funcion2->id, $sala, [
            'fecha' => '2030-10-25',
            'hora' => '21:00',
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Se pisa con otra función de la sala', $respuesta->cuerpo());
    }

    /**
     * F-01.1: el cupo no baja de los lugares reservados. Hasta ahí, sí.
     */
    public function testElCupoNoBajaDeLoReservado(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');
        self::ocupados($funcion->id, 30);

        $respuesta = $this->actualizar($funcion->id, $sala, ['cupo' => '20']);
        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Ya hay 30 lugares reservados: el cupo no puede ser menor.', $respuesta->cuerpo());
        self::assertSame(40, (int) $this->base->valor('SELECT cupo FROM funcion WHERE id = ?', [$funcion->id]));

        self::assertSame(303, $this->actualizar($funcion->id, $sala, ['cupo' => '30'])->estado());
        self::assertSame(30, (int) $this->base->valor('SELECT cupo FROM funcion WHERE id = ?', [$funcion->id]));
    }

    /**
     * F-01.1: con una reserva, la función no cambia de sala, de películas ni de fecha: quien
     * reservó lo hizo para eso. El precio y el cupo para arriba, sí.
     */
    public function testConReservasNoCambiaLaSalaNiLasPeliculasNiLaFecha(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $otraSala = $this->sala(self::ORGANIZADORA, 'Sala Alternativa', capacidad: 80);
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');
        self::ocupados($funcion->id, 1);

        foreach ([
            'la sala' => [$otraSala, []],
            'las películas' => [$sala, ['obras' => [$this->obras['La ciénaga']]]],
            'la fecha' => [$sala, ['fecha' => '2030-10-26']],
        ] as $que => [$salaNueva, $campos]) {
            $respuesta = $this->actualizar($funcion->id, $salaNueva, $campos);
            self::assertSame(422, $respuesta->estado(), "Cambió {$que} con reservas.");
            self::assertStringContainsString('La función ya tiene reservas: no se le puede cambiar la sala, las películas ni la fecha.', $respuesta->cuerpo());
        }

        self::assertSame(303, $this->actualizar($funcion->id, $sala, ['precio' => '3000', 'cupo' => '45'])->estado());
    }

    /**
     * F-01.1: si funciones no contesta, lo que podría pisar una reserva no se guarda; lo demás, sí.
     */
    public function testSinRespuestaDeFuncionesNoSeArriesgaAPisarReservas(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');
        self::ocupados($funcion->id, null);

        $respuesta = $this->actualizar($funcion->id, $sala, ['cupo' => '10']);
        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('No pudimos comprobar las reservas de la función', $respuesta->cuerpo());

        self::assertSame(303, $this->actualizar($funcion->id, $sala, ['precio' => '3000'])->estado());
    }

    public function testEdicionDePuntaAPuntaYCambiosSeVenEnLaFicha(): void
    {
        $sala1 = $this->sala(self::ORGANIZADORA, 'Cine Club El Galpón', capacidad: 60);
        $sala2 = $this->sala(self::ORGANIZADORA, 'Sala Alternativa', capacidad: 80);

        // Creamos la función originalmente
        $alta = $this->programar($sala1, [
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-11-10',
            'hora' => '19:00',
            'precio' => '2000',
            'cupo' => '30',
        ]);
        self::assertSame(303, $alta->estado());
        $funcionId = (string) $this->base->valor('SELECT id FROM funcion WHERE sala_id = ?', [$sala1->id]);

        // Editamos la función con nuevos datos
        $edicion = $this->actualizar($funcionId, $sala2, [
            'obras' => [$this->obras['La ciénaga']],
            'fecha' => '2030-11-20',
            'hora' => '21:30',
            'precio' => '4500',
            'cupo' => '55',
        ]);
        self::assertSame(303, $edicion->estado());
        self::assertSame("/cartelera/{$funcionId}?guardada=1", $edicion->cabecera('Location'));

        // Verificamos el criterio: «Listo cuando: Los cambios se ven en la ficha.»
        // Como organizadora dueña:
        $fichaDuenia = $this->pedir('GET', "/cartelera/{$funcionId}?guardada=1", self::ORGANIZADORA, 'organizador');
        self::assertSame(200, $fichaDuenia->estado());
        $cuerpoDuenia = $fichaDuenia->cuerpo();

        // Aviso de cambios guardados
        self::assertStringContainsString('¡Cambios guardados!', $cuerpoDuenia);
        self::assertStringContainsString('La función se actualizó correctamente', $cuerpoDuenia);
        // Obra nueva y no la anterior
        self::assertStringContainsString('La ciénaga', $cuerpoDuenia);
        self::assertStringNotContainsString('Zama', $cuerpoDuenia);
        // Sala nueva
        self::assertStringContainsString('Sala Alternativa', $cuerpoDuenia);
        // Precio nuevo y cupo nuevo
        self::assertStringContainsString('$ 4.500', $cuerpoDuenia);
        self::assertStringContainsString('55 lugares', $cuerpoDuenia);
        // Botón de editar visible para la dueña
        self::assertStringContainsString("/organizador/funciones/{$funcionId}/editar", $cuerpoDuenia);
        self::assertStringContainsString('Editar función', $cuerpoDuenia);

        // Como espectador en la cartelera pública:
        $fichaEspectador = $this->pedir('GET', "/cartelera/{$funcionId}", self::ORGANIZADORA, 'espectador');
        self::assertSame(200, $fichaEspectador->estado());
        $cuerpoEspectador = $fichaEspectador->cuerpo();
        self::assertStringContainsString('La ciénaga', $cuerpoEspectador);
        self::assertStringContainsString('Sala Alternativa', $cuerpoEspectador);
        self::assertStringContainsString('$ 4.500', $cuerpoEspectador);
        self::assertStringContainsString('55 lugares', $cuerpoEspectador);
        self::assertStringNotContainsString('Editar función', $cuerpoEspectador);

        // Sin sesión en la cartelera pública:
        $fichaPublica = $this->pedir('GET', "/cartelera/{$funcionId}", null);
        self::assertSame(200, $fichaPublica->estado());
        self::assertStringNotContainsString('Editar función', $fichaPublica->cuerpo());
    }

    public function testConfirmarCancelacionRequiereRolOrganizador(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');

        // Sin sesión
        self::assertSame(401, $this->pedir('GET', "/organizador/funciones/{$funcion->id}/cancelar", null)->estado());
        // Como espectador
        self::assertSame(403, $this->pedir('GET', "/organizador/funciones/{$funcion->id}/cancelar", self::ORGANIZADORA, 'espectador')->estado());
    }

    public function testConfirmarCancelacionDevuelve404SiNoExisteOEsAjena(): void
    {
        $salaAjena = $this->sala(self::OTRO_ORGANIZADOR, 'El Sótano');
        $funcionAjena = $this->programada($salaAjena, '2030-10-25 20:30:00');

        // Id inexistente
        self::assertSame(404, $this->pedir('GET', '/organizador/funciones/' . Uuid::nuevo() . '/cancelar')->estado());
        // Id no UUID
        self::assertSame(404, $this->pedir('GET', '/organizador/funciones/no-uuid/cancelar')->estado());
        // Función de otro organizador
        self::assertSame(404, $this->pedir('GET', "/organizador/funciones/{$funcionAjena->id}/cancelar")->estado());
    }

    public function testConfirmarCancelacionMuestraPantallaConDatosDeLaFuncion(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');

        $respuesta = $this->pedir('GET', "/organizador/funciones/{$funcion->id}/cancelar");

        self::assertSame(200, $respuesta->estado());
        $cuerpo = $respuesta->cuerpo();
        self::assertStringContainsString('Cancelar función', $cuerpo);
        self::assertStringContainsString('¿Estás seguro de que deseás cancelar esta función?', $cuerpo);
        self::assertStringContainsString('Zama', $cuerpo);
        self::assertStringContainsString('El Galpón', $cuerpo);
        self::assertStringContainsString('Vie 25 oct · 20:30', $cuerpo);
        self::assertStringContainsString('40 lugares', $cuerpo);
        self::assertStringContainsString('programada', $cuerpo);
        self::assertStringContainsString("/organizador/funciones/{$funcion->id}/cancelar", $cuerpo);
        self::assertStringContainsString('Confirmar cancelación', $cuerpo);
    }

    /**
     * Se cancela una función en votación (de títulos o de fecha) o programada que todavía no se
     * dio. Una cancelada, finalizada o con la fecha ya pasada ya terminó: es 404 al abrir la
     * confirmación y al cancelar, como en la edición, y ni el panel ni la ficha muestran el botón.
     */
    public function testSoloSeCancelaUnaFuncionQueNoTermino(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $enAcuerdo = $this->funcion($sala);
        $enAcuerdo->cerrarVotacionTitulos([$this->obras['Zama']]);
        $this->funciones->guardar($enAcuerdo);

        $cancelables = [
            'en votación de títulos' => $this->funcion($sala),
            'en acuerdo de fecha' => $enAcuerdo,
            'programada' => $this->programada($sala, '2030-12-05 20:30:00'),
        ];

        $cancelada = $this->programada($sala, '2030-10-25 20:30:00');
        $cancelada->cancelar();
        $this->funciones->guardar($cancelada);

        $finalizada = $this->programada($sala, '2020-03-05 20:30:00', programadaEl: '2020-01-01');
        $finalizada->finalizar(new DateTimeImmutable('2020-03-05 23:00:00'));
        $this->funciones->guardar($finalizada);

        $noCancelables = [
            'cancelada' => $cancelada,
            'finalizada' => $finalizada,
            // Programada, pero su fecha ya pasó: se proyectó aunque nadie la haya finalizado.
            'programada que ya se dio' => $this->programada($sala, '2020-05-01 20:30:00', programadaEl: '2020-04-01 10:00:00'),
        ];

        $panel = $this->pedir('GET', '/organizador/funciones')->cuerpo();
        foreach ($cancelables as $caso => $funcion) {
            self::assertSame(200, $this->pedir('GET', "/organizador/funciones/{$funcion->id}/cancelar")->estado(), $caso);
            self::assertStringContainsString("/organizador/funciones/{$funcion->id}/cancelar", $panel, $caso);
            self::assertStringContainsString('Cancelar función', $this->pedir('GET', "/cartelera/{$funcion->id}")->cuerpo(), $caso);
        }
        foreach ($noCancelables as $caso => $funcion) {
            self::assertSame(404, $this->pedir('GET', "/organizador/funciones/{$funcion->id}/cancelar")->estado(), $caso);
            self::assertSame(404, $this->enviar("/organizador/funciones/{$funcion->id}/cancelar", [])->estado(), $caso);
            self::assertStringNotContainsString("/organizador/funciones/{$funcion->id}/cancelar", $panel, $caso);
            self::assertStringNotContainsString('Cancelar función', $this->pedir('GET', "/cartelera/{$funcion->id}")->cuerpo(), $caso);
            // Y quedó como estaba.
            self::assertSame($funcion->estado->value, $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$funcion->id]), $caso);
        }

        // Una en votación se cancela de punta a punta, y se queda con sus candidatas.
        $enVotacion = $cancelables['en votación de títulos'];
        self::assertSame(303, $this->enviar("/organizador/funciones/{$enVotacion->id}/cancelar", [])->estado());
        $leida = $this->funciones->buscar($enVotacion->id) ?? self::fail('No está la función.');
        self::assertSame(EstadoFuncion::Cancelada, $leida->estado);
        self::assertEqualsCanonicalizing($enVotacion->obrasIds, $leida->obrasIds);
    }

    public function testCancelarRequierePermisosYCsrf(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');

        // Sin sesión
        $sinSesion = $this->pedir('POST', "/organizador/funciones/{$funcion->id}/cancelar", null, 'organizador', [
            '_csrf' => self::CSRF,
        ]);
        self::assertSame(401, $sinSesion->estado());

        // Con rol espectador
        $espectador = $this->pedir('POST', "/organizador/funciones/{$funcion->id}/cancelar", self::ORGANIZADORA, 'espectador', [
            '_csrf' => self::CSRF,
        ]);
        self::assertSame(403, $espectador->estado());

        // Sin CSRF válido
        $sinCsrf = $this->pedir('POST', "/organizador/funciones/{$funcion->id}/cancelar", self::ORGANIZADORA, 'organizador', [
            '_csrf' => 'token-invalido',
        ]);
        self::assertSame(403, $sinCsrf->estado());

        // Función ajena
        $salaAjena = $this->sala(self::OTRO_ORGANIZADOR, 'El Sótano');
        $funcionAjena = $this->programada($salaAjena, '2030-10-25 20:30:00');
        $ajena = $this->enviar("/organizador/funciones/{$funcionAjena->id}/cancelar", []);
        self::assertSame(404, $ajena->estado());
    }

    public function testCancelacionDePuntaAPuntaDesapareceDeCarteleraYQuedaEnPanel(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'Cine Club El Galpón', capacidad: 60);

        // 1. Programamos una función
        $alta = $this->programar($sala, [
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-11-15',
            'hora' => '20:30',
            'precio' => '3000',
            'cupo' => '50',
        ]);
        self::assertSame(303, $alta->estado());
        $funcionId = (string) $this->base->valor('SELECT id FROM funcion WHERE sala_id = ?', [$sala->id]);

        // Verificamos que aparece en la cartelera pública
        $carteleraAntes = $this->pedir('GET', '/cartelera', null);
        self::assertSame(200, $carteleraAntes->estado());
        self::assertStringContainsString('Zama', $carteleraAntes->cuerpo());
        self::assertStringContainsString('Cine Club El Galpón', $carteleraAntes->cuerpo());

        // 2. Ejecutamos la cancelación (POST con confirmación)
        $respuestaCancelar = $this->enviar("/organizador/funciones/{$funcionId}/cancelar", []);
        self::assertSame(303, $respuestaCancelar->estado());
        self::assertSame('/organizador/funciones?cancelada=1', $respuestaCancelar->cabecera('Location'));

        // 3. Verificamos que la función cancelada sale de la cartelera pública (criterio de aceptación)
        $carteleraDespues = $this->pedir('GET', '/cartelera', null);
        self::assertSame(200, $carteleraDespues->estado());
        self::assertStringNotContainsString($funcionId, $carteleraDespues->cuerpo());
        self::assertStringNotContainsString('Zama', $carteleraDespues->cuerpo());

        // 4. Verificamos que queda en el panel del organizador con aviso y estado cancelada
        $panel = $this->pedir('GET', '/organizador/funciones?cancelada=1', self::ORGANIZADORA, 'organizador');
        self::assertSame(200, $panel->estado());
        $cuerpoPanel = $panel->cuerpo();
        self::assertStringContainsString('La función fue cancelada', $cuerpoPanel);
        self::assertStringContainsString('Zama', $cuerpoPanel);
        self::assertStringContainsString('cancelada', $cuerpoPanel);
        // Al ser estado final, ya no tiene botón de editar ni cancelar en las acciones
        self::assertStringNotContainsString("/organizador/funciones/{$funcionId}/editar", $cuerpoPanel);
        self::assertStringNotContainsString("/organizador/funciones/{$funcionId}/cancelar", $cuerpoPanel);

        // 5. Verificamos que en su ficha pública muestra el aviso de función cancelada
        $ficha = $this->pedir('GET', "/cartelera/{$funcionId}", null);
        self::assertSame(200, $ficha->estado());
        $cuerpoFicha = $ficha->cuerpo();
        self::assertStringContainsString('Función cancelada', $cuerpoFicha);
        self::assertStringContainsString('Esta función fue cancelada', $cuerpoFicha);
        self::assertStringNotContainsString('Editar función', $cuerpoFicha);
        self::assertStringNotContainsString('Cancelar función', $cuerpoFicha);
    }

    /*
     * De acá para abajo, E3-11.5 (IC-124): validación y control de acceso. Lo que se le escapa a
     * una pantalla que ya anda con los datos de siempre: cabeceras falsificadas, salas que dejaron
     * de estar, roles que alcanzan al organizador sin ser el dueño y campos que no son lo que
     * dicen ser. Cada rechazo comprueba además que no quedó nada en la base.
     */

    /**
     * El núcleo sólo cree las cabeceras de sesión que vienen con el secreto de nginx. Sin él son
     * las que mandó el navegador, y se ignoran: ni el panel ni el alta se abren con una sesión
     * inventada.
     */
    public function testLasCabecerasDeSesionSinElSecretoDeNginxNoDanAcceso(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $deMentira = ['X-Usuario-Id' => self::ORGANIZADORA, 'X-Rol' => 'organizador'];

        foreach (['/organizador/funciones', '/organizador/funciones/nueva'] as $ruta) {
            self::assertSame(401, $this->sinSecreto('GET', $ruta, $deMentira)->estado(), $ruta);
        }
        self::assertSame(401, $this->sinSecreto('POST', '/organizador/funciones/nueva', $deMentira, [
            '_csrf' => self::CSRF,
            'sala_id' => $sala->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '40',
        ])->estado());
        self::assertSame(0, $this->cuantasFunciones($sala));
    }

    /**
     * Los roles son acumulativos: el administrador alcanza las rutas del organizador. Entra, pero
     * como organizador de lo suyo, que no es nada.
     */
    public function testUnAdministradorEntraPeroNoVeNiProgramaLoAjeno(): void
    {
        $ajena = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcionAjena = $this->programada($ajena, '2030-09-19 20:30:00');

        $panel = $this->pedir('GET', '/organizador/funciones', self::OTRO_ORGANIZADOR, 'administrador');
        self::assertSame(200, $panel->estado());
        self::assertStringNotContainsString($funcionAjena->id, $panel->cuerpo());
        self::assertStringNotContainsString('El Galpón', $panel->cuerpo());

        $respuesta = $this->enviar('/organizador/funciones/nueva', [
            'sala_id' => $ajena->id,
            'obras' => [$this->obras['Zama']],
            'fecha' => '2030-10-25',
            'hora' => '20:30',
            'precio' => '2500',
            'cupo' => '40',
        ], self::OTRO_ORGANIZADOR, 'administrador');

        self::assertSame(422, $respuesta->estado());
        self::assertSame(1, $this->cuantasFunciones($ajena));
    }

    /**
     * Una sala dada de baja ya no se ve en ningún lado: tampoco se le programan funciones, aunque
     * siga figurando como habilitada y el id se mande a mano.
     */
    public function testNoSeProgramaEnUnaSalaDadaDeBaja(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$sala->id]);

        $formulario = $this->pedir('GET', '/organizador/funciones/nueva');
        self::assertStringNotContainsString('El Galpón', $formulario->cuerpo());

        $respuesta = $this->programar($sala);

        self::assertSame(422, $respuesta->estado());
        self::assertSame(0, $this->cuantasFunciones($sala));
    }

    public function testRechazaUnaObraRepetida(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->programar($sala, ['obras' => [$this->obras['Zama'], $this->obras['Zama']]]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Una obra no puede estar dos veces en la misma función.', $respuesta->cuerpo());
        self::assertSame(0, $this->cuantasFunciones($sala));
    }

    public function testRechazaUnaObraQueNoEstaEnElCatalogo(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->programar($sala, ['obras' => [Uuid::nuevo()]]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('Una de las obras seleccionadas no existe en el catálogo.', $respuesta->cuerpo());
        self::assertSame(0, $this->cuantasFunciones($sala));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cuposQueNoSon(): iterable
    {
        yield 'vacío' => [''];
        yield 'cero' => ['0'];
        yield 'negativo' => ['-5'];
        yield 'con coma' => ['40,5'];
        yield 'palabras' => ['cuarenta'];
    }

    #[DataProvider('cuposQueNoSon')]
    public function testRechazaUnCupoQueNoEsAlMenosUnLugar(string $cupo): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->programar($sala, ['cupo' => $cupo]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('El cupo tiene que ser de al menos un lugar.', $respuesta->cuerpo());
        self::assertSame(0, $this->cuantasFunciones($sala));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fechasQueNoSon(): iterable
    {
        yield 'día que no existe' => ['2030-02-30', '20:30'];
        yield 'mes que no existe' => ['2030-13-01', '20:30'];
        yield 'hora que no existe' => ['2030-10-25', '25:00'];
        yield 'otro formato' => ['25/10/2030', '20:30'];
        yield 'palabras' => ['mañana', 'tarde'];
    }

    /**
     * Una fecha que no es una fecha vuelve al formulario con su aviso, no revienta.
     */
    #[DataProvider('fechasQueNoSon')]
    public function testRechazaUnaFechaOUnHorarioMalFormados(string $fecha, string $hora): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->programar($sala, ['fecha' => $fecha, 'hora' => $hora]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('La fecha y el horario no tienen un formato válido.', $respuesta->cuerpo());
        self::assertSame(0, $this->cuantasFunciones($sala));
    }

    /**
     * El formulario vacío no es un error del servidor: vuelve con lo que falta, campo por campo.
     */
    public function testUnPostVacioVuelveAlFormularioConTodosLosErrores(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $respuesta = $this->enviar('/organizador/funciones/nueva', []);

        self::assertSame(422, $respuesta->estado());
        foreach ([
            'Falta seleccionar la sala.',
            'Tenés que elegir al menos una obra del catálogo.',
            'Falta la fecha de la función.',
            'Falta el horario de la función.',
            'Falta el precio de la entrada.',
            'El cupo tiene que ser de al menos un lugar.',
        ] as $aviso) {
            self::assertStringContainsString($aviso, $respuesta->cuerpo(), $aviso);
        }
        self::assertSame(0, $this->cuantasFunciones($sala));
    }

    /**
     * Como pedir(), pero con las cabeceras tal cual: sirve para mandarlas sin el secreto de nginx.
     *
     * @param array<string, string> $cabeceras
     * @param array<string, mixed>  $cuerpo
     */
    private function sinSecreto(string $metodo, string $ruta, array $cabeceras, array $cuerpo = []): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion($metodo, $ruta, [], $cuerpo, $cabeceras, ['csrf' => self::CSRF]));
    }

    /*
     * La edición y la cancelación, con la misma mirada: que una función propia no sea la puerta
     * para tocar lo ajeno, y que lo que se rechaza no deje nada a medio cambiar.
     */

    /**
     * La función es suya, pero la sala a la que la quiere mover no: el formulario sólo acepta las
     * salas habilitadas del que pide, y la función se queda donde estaba.
     */
    public function testNoSeMueveUnaFuncionAUnaSalaAjena(): void
    {
        $propia = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $ajena = $this->sala(self::OTRO_ORGANIZADOR, 'El Sótano');
        $funcion = $this->programada($propia, '2030-10-25 20:30:00');

        $respuesta = $this->actualizar($funcion->id, $ajena);

        self::assertSame(422, $respuesta->estado());
        self::assertSame($propia->id, $this->base->valor('SELECT sala_id FROM funcion WHERE id = ?', [$funcion->id]));
    }

    /**
     * Tampoco a una sala propia que no está habilitada o que se dio de baja: en las dos la función
     * dejaría de poder verse.
     */
    public function testNoSeMueveUnaFuncionAUnaSalaPropiaQueNoEsta(): void
    {
        $propia = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($propia, '2030-10-25 20:30:00');

        $sinHabilitar = $this->sala(self::ORGANIZADORA, 'La Terraza');
        $this->base->ejecutar('UPDATE sala SET habilitada = FALSE WHERE id = ?', [$sinHabilitar->id]);
        $dadaDeBaja = $this->sala(self::ORGANIZADORA, 'El Sótano Propio');
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$dadaDeBaja->id]);

        foreach (['sin habilitar' => $sinHabilitar, 'dada de baja' => $dadaDeBaja] as $caso => $destino) {
            self::assertSame(422, $this->actualizar($funcion->id, $destino)->estado(), $caso);
            self::assertSame(
                $propia->id,
                $this->base->valor('SELECT sala_id FROM funcion WHERE id = ?', [$funcion->id]),
                $caso,
            );
        }
    }

    /**
     * Las obras se validan igual que en el alta, y un rechazo no deja la función a medio cambiar.
     */
    public function testLaEdicionValidaLasObrasYNoGuardaNadaSiRechaza(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');
        $antes = $this->base->fila('SELECT sala_id, fecha_hora, precio_base, cupo FROM funcion WHERE id = ?', [$funcion->id]);

        $repetida = $this->actualizar($funcion->id, $sala, [
            'obras' => [$this->obras['Zama'], $this->obras['Zama']],
            'cupo' => '55',
        ]);
        self::assertSame(422, $repetida->estado());
        self::assertStringContainsString('Una obra no puede estar dos veces en la misma función.', $repetida->cuerpo());

        $inventada = $this->actualizar($funcion->id, $sala, ['obras' => [Uuid::nuevo()], 'cupo' => '55']);
        self::assertSame(422, $inventada->estado());
        self::assertStringContainsString('Una de las obras seleccionadas no existe en el catálogo.', $inventada->cuerpo());

        // Ni el cupo que venía en el mismo formulario quedó guardado.
        self::assertEquals($antes, $this->base->fila('SELECT sala_id, fecha_hora, precio_base, cupo FROM funcion WHERE id = ?', [$funcion->id]));
    }

    /**
     * La confirmación de la cancelación es una pantalla: mirarla no cancela nada.
     */
    public function testAbrirLaConfirmacionNoCancelaLaFuncion(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');

        self::assertSame(200, $this->pedir('GET', "/organizador/funciones/{$funcion->id}/cancelar")->estado());
        self::assertSame(
            EstadoFuncion::Programada->value,
            $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$funcion->id]),
        );
    }

    /**
     * Las cabeceras de sesión sin el secreto de nginx tampoco abren la edición ni la cancelación,
     * y no cambian nada.
     */
    public function testSinElSecretoDeNginxNoSeEditaNiSeCancela(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');
        $deMentira = ['X-Usuario-Id' => self::ORGANIZADORA, 'X-Rol' => 'organizador'];

        foreach (['editar', 'cancelar'] as $accion) {
            self::assertSame(401, $this->sinSecreto('GET', "/organizador/funciones/{$funcion->id}/{$accion}", $deMentira)->estado(), $accion);
            self::assertSame(401, $this->sinSecreto('POST', "/organizador/funciones/{$funcion->id}/{$accion}", $deMentira, [
                '_csrf' => self::CSRF,
                'sala_id' => $sala->id,
                'obras' => [$this->obras['Zama']],
                'fecha' => '2030-11-30',
                'hora' => '21:00',
                'precio' => '9999',
                'cupo' => '7',
            ])->estado(), $accion);
        }

        $fila = $this->base->fila('SELECT estado, cupo FROM funcion WHERE id = ?', [$funcion->id]);
        self::assertSame(EstadoFuncion::Programada->value, $fila['estado']);
        self::assertSame(40, (int) $fila['cupo']);
    }

    /**
     * El administrador alcanza las rutas del organizador, pero no es el dueño: ni edita ni cancela
     * las funciones de otro.
     */
    public function testUnAdministradorNoEditaNiCancelaLoAjeno(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->programada($sala, '2030-10-25 20:30:00');

        foreach (['editar', 'cancelar'] as $accion) {
            self::assertSame(404, $this->pedir('GET', "/organizador/funciones/{$funcion->id}/{$accion}", self::OTRO_ORGANIZADOR, 'administrador')->estado(), $accion);
            self::assertSame(404, $this->pedir('POST', "/organizador/funciones/{$funcion->id}/{$accion}", self::OTRO_ORGANIZADOR, 'administrador', [
                '_csrf' => self::CSRF,
                'sala_id' => $sala->id,
                'obras' => [$this->obras['Zama']],
                'fecha' => '2030-11-30',
                'hora' => '21:00',
                'precio' => '9999',
                'cupo' => '7',
            ])->estado(), $accion);
        }

        $fila = $this->base->fila('SELECT estado, cupo FROM funcion WHERE id = ?', [$funcion->id]);
        self::assertSame(EstadoFuncion::Programada->value, $fila['estado']);
        self::assertSame(40, (int) $fila['cupo']);
    }

    // =========================================================================
    // Cierre de la votación con las más votadas (E3-12.4, EF-05.1)
    // =========================================================================

    /**
     * Criterio E3-12.4: «Listo cuando: La función queda con el título ganador y cambia de estado».
     * Desde EF-05.1 quedan tantos títulos como películas por función tiene la sala: en una de 2,
     * con 1, 3 y 5 votos, las de 5 y 3. La sala dura 360 minutos para que entren (EF-05.4).
     */
    public function testCerrarVotacionDejaLasMasVotadasHastaLasPeliculasPorFuncion(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón', duracionFuncion: 360, peliculasPorFuncion: 2);
        $tercera = $this->obra('Rojo');
        $funcion = $this->funcion($sala, [$this->obras['Zama'], $this->obras['La ciénaga'], $tercera]);
        $this->votar($funcion, $this->obras['Zama'], 1);
        $this->votar($funcion, $this->obras['La ciénaga'], 3);
        $this->votar($funcion, $tercera, 5);

        $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
        self::assertSame(303, $respuesta->estado());
        self::assertSame("/organizador/funciones/{$funcion->id}/proponer-franjas?votacion_cerrada=1", $respuesta->cabecera('Location'));

        $leida = $this->funciones->buscar($funcion->id) ?? self::fail('No se encontró la función.');
        self::assertSame(EstadoFuncion::EnAcuerdoFecha, $leida->estado);
        self::assertEqualsCanonicalizing([$tercera, $this->obras['La ciénaga']], $leida->obrasIds);

        // Las que no salieron siguen como candidatas, para el historial de la votación.
        $elegidas = $this->base->filas("SELECT obra_id FROM funcion_obra WHERE funcion_id = ? AND estado = 'elegida'", [$funcion->id]);
        self::assertCount(2, $elegidas);

        $panel = $this->pedir('GET', '/organizador/funciones')->cuerpo();
        self::assertStringContainsString('Rojo', $panel);
        self::assertStringContainsStringIgnoringCase('en acuerdo de fecha', $panel);
        self::assertStringNotContainsString("/organizador/funciones/{$funcion->id}/cerrar-votacion", $panel);
    }

    public function testEnUnaSalaDeUnaPeliculaQuedaSoloLaMasVotada(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón', peliculasPorFuncion: 1);
        $funcion = $this->funcion($sala);
        $this->votar($funcion, $this->obras['La ciénaga'], 2);
        $this->votar($funcion, $this->obras['Zama'], 1);

        self::assertSame(303, $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", [])->estado());

        $leida = $this->funciones->buscar($funcion->id) ?? self::fail('No se encontró la función.');
        self::assertSame([$this->obras['La ciénaga']], $leida->obrasIds);
    }

    /**
     * El empate en el corte lo gana la que va primero entre las candidatas, como se leen de la
     * base, así que el resultado es siempre el mismo.
     */
    public function testCerrarVotacionConEmpateEnElCorteDesempataPorElOrdenDeLasCandidatas(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón', duracionFuncion: 360, peliculasPorFuncion: 2);
        $tercera = $this->obra('Rojo');
        $funcion = $this->funcion($sala, [$this->obras['Zama'], $this->obras['La ciénaga'], $tercera]);
        [$primera, $segunda] = array_values(array_diff($this->candidatas($funcion), [$tercera]));
        $this->votar($funcion, $tercera, 3);
        $this->votar($funcion, $segunda, 1);
        $this->votar($funcion, $primera, 1);

        self::assertSame(303, $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", [])->estado());

        $leida = $this->funciones->buscar($funcion->id) ?? self::fail('No se encontró la función.');
        self::assertEqualsCanonicalizing([$tercera, $primera], $leida->obrasIds);
    }

    public function testCerrarVotacionSinVotosDejaLasPrimerasCandidatas(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón', duracionFuncion: 360, peliculasPorFuncion: 2);
        $funcion = $this->funcion($sala, [$this->obra('Rojo'), $this->obras['Zama'], $this->obras['La ciénaga']]);
        $candidatas = $this->candidatas($funcion);

        self::assertSame(303, $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", [])->estado());

        $leida = $this->funciones->buscar($funcion->id) ?? self::fail('No se encontró la función.');
        self::assertSame(EstadoFuncion::EnAcuerdoFecha, $leida->estado);
        self::assertEqualsCanonicalizing(array_slice($candidatas, 0, 2), $leida->obrasIds);
    }

    /**
     * EF-05.4: quedan las más votadas que entran juntas en la duración de la sala. En una de 2
     * películas y 180 minutos, A (5 votos, 120 min) entra; B (3, 100) ya no; C (1, 50) sí.
     */
    public function testAlCerrarSeSalteaLaQueNoEntraEnLaDuracion(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón', peliculasPorFuncion: 2, duracionFuncion: 180);
        [$a, $b, $c] = [$this->obra('A', 120), $this->obra('B', 100), $this->obra('C', 50)];
        $funcion = $this->funcion($sala, [$a, $b, $c]);
        $this->votar($funcion, $a, 5);
        $this->votar($funcion, $b, 3);
        $this->votar($funcion, $c, 1);

        self::assertSame(303, $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", [])->estado());

        $leida = $this->funciones->buscar($funcion->id) ?? self::fail('No se encontró la función.');
        self::assertEqualsCanonicalizing([$a, $c], $leida->obrasIds);
    }

    /**
     * Si la sala achicó la duración después de abrir la votación y ya no entra ninguna, queda la
     * más votada sola, para no trabar la función, y el log lo avisa.
     */
    public function testSiNoEntraNingunaQuedaLaMasVotadaYElLogLoAvisa(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->votar($funcion, $this->obras['La ciénaga'], 2);
        $this->base->ejecutar('UPDATE sala SET duracion_funcion = 90 WHERE id = ?', [$sala->id]);

        self::assertSame(303, $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", [])->estado());

        $leida = $this->funciones->buscar($funcion->id) ?? self::fail('No se encontró la función.');
        self::assertSame([$this->obras['La ciénaga']], $leida->obrasIds);
        rewind($this->salidaDelLog);
        $log = (string) stream_get_contents($this->salidaDelLog);
        self::assertStringContainsString('ninguna candidata entra en la duración', $log);
        self::assertStringContainsString($funcion->id, $log);
    }

    public function testConMenosCandidatasQuePeliculasPorFuncionQuedanTodas(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón', duracionFuncion: 360, peliculasPorFuncion: 3);
        $funcion = $this->funcion($sala);
        $this->votar($funcion, $this->obras['Zama'], 1);

        self::assertSame(303, $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", [])->estado());

        $leida = $this->funciones->buscar($funcion->id) ?? self::fail('No se encontró la función.');
        self::assertEqualsCanonicalizing(array_values($this->obras), $leida->obrasIds);
    }

    public function testCerrarVotacionDesdeLaFichaTambienLlevaAProponerLasFranjas(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);

        $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", ['volver' => 'ficha']);
        self::assertSame(303, $respuesta->estado());
        self::assertSame("/organizador/funciones/{$funcion->id}/proponer-franjas?votacion_cerrada=1", $respuesta->cabecera('Location'));

        $formulario = $this->pedir('GET', "/organizador/funciones/{$funcion->id}/proponer-franjas?votacion_cerrada=1");
        self::assertSame(200, $formulario->estado());
        self::assertStringContainsString('Se cerró la votación de títulos', $formulario->cuerpo());
    }

    public function testCerrarVotacionRechazaEstadosInvalidos(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');

        $enAcuerdo = $this->funcion($sala);
        $enAcuerdo->cerrarVotacionTitulos([$this->obras['Zama']]);
        $this->funciones->guardar($enAcuerdo);

        $cancelada = $this->programada($sala, '2030-10-25 20:30:00');
        $cancelada->cancelar();
        $this->funciones->guardar($cancelada);

        $programada = $this->programada($sala, '2030-12-05 20:30:00');

        $invalidas = [
            'en acuerdo de fecha' => $enAcuerdo,
            'cancelada' => $cancelada,
            'programada' => $programada,
        ];

        foreach ($invalidas as $caso => $funcionInvalida) {
            $resp = $this->enviar("/organizador/funciones/{$funcionInvalida->id}/cerrar-votacion", []);
            self::assertSame(404, $resp->estado(), $caso);
        }
    }

    public function testCerrarVotacionRequierePermisosYCsrf(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);

        // Sin sesión -> 401
        $sinSesion = $this->pedir('POST', "/organizador/funciones/{$funcion->id}/cerrar-votacion", null, 'organizador', [
            '_csrf' => self::CSRF,
        ]);
        self::assertSame(401, $sinSesion->estado());

        // Espectador (rol insuficiente) -> 403
        $espectador = $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", [], Uuid::nuevo(), 'espectador');
        self::assertSame(403, $espectador->estado());

        // Otro organizador (no es su sala) -> 404
        $otroOrg = $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", [], self::OTRO_ORGANIZADOR, 'organizador');
        self::assertSame(404, $otroOrg->estado());

        // Administrador (no es el dueño de la sala) -> 404
        $admin = $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", [], self::OTRO_ORGANIZADOR, 'administrador');
        self::assertSame(404, $admin->estado());

        // Sin token CSRF -> 403
        $sinCsrf = $this->pedir('POST', "/organizador/funciones/{$funcion->id}/cerrar-votacion", self::ORGANIZADORA, 'organizador', []);
        self::assertSame(403, $sinCsrf->estado());
    }

    public function testOrganizadorProponeFranjasYMinimoDeAsistentes(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);

        // Cerramos la votación para que pase a en_acuerdo_fecha
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
        $leida = $this->funciones->buscar($funcion->id) ?? self::fail('No se encontró la función.');
        self::assertSame(EstadoFuncion::EnAcuerdoFecha, $leida->estado);

        // El organizador propone las franjas y el mínimo (todavía no tiene acuerdo)
        self::acuerdoEnFunciones('ninguno');
        $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/acuerdo", [
            'minimo_asistentes' => '20',
            'franja_inicio' => [self::dentroDe(10, '20:00:00'), self::dentroDe(11, '21:00:00')],
            'franja_fin' => [self::dentroDe(10, '22:30:00'), ''],
        ]);

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/cartelera/{$funcion->id}?acuerdo_propuesto=1", $respuesta->cabecera('Location'));
    }

    public function testProponerAcuerdoRechazaMinimoInvalidoOFranjasVacias(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);

        // Mínimo cero: vuelve el formulario con el motivo (E3-13.F2), no una redirección
        $sinMinimo = $this->enviar("/organizador/funciones/{$funcion->id}/acuerdo", [
            'minimo_asistentes' => '0',
            'franja_inicio' => [self::dentroDe(10, '20:00:00'), self::dentroDe(11, '20:00:00')],
        ]);
        self::assertSame(422, $sinMinimo->estado());
        self::assertStringContainsString('El mínimo de asistentes tiene que ser un número entre 1 y 40', $sinMinimo->cuerpo());

        // Sin franjas
        $sinFranjas = $this->enviar("/organizador/funciones/{$funcion->id}/acuerdo", [
            'minimo_asistentes' => '15',
            'franja_inicio' => [],
        ]);
        self::assertSame(422, $sinFranjas->estado());
        self::assertStringContainsString('Proponé al menos 2 franjas', $sinFranjas->cuerpo());
    }

    /**
     * E3-13.F2: con los campos del formulario, lo que está mal vuelve con el motivo y lo cargado,
     * y no llega a funciones. Antes, una franja pasada se guardaba y una que terminaba antes de
     * empezar daba una página 500.
     */
    public function testProponerFranjasValidaLasFranjasYMuestraLosErroresEnElFormulario(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
        self::acuerdoEnFunciones('ninguno');
        $manana = (new DateTimeImmutable('+1 day'))->format('Y-m-d');
        $ayer = (new DateTimeImmutable('-1 day'))->format('Y-m-d');
        $hoy = (new DateTimeImmutable())->format('Y-m-d');
        $ruta = "/organizador/funciones/{$funcion->id}/proponer-franjas";

        $casos = [
            'termina antes de empezar' => [[$manana, '22:00', '20:00'], [$manana, '18:00', '19:00'], 'La opción 1 tiene que terminar después de empezar.'],
            'una que ya pasó' => [[$manana, '20:00', '22:00'], [$ayer, '20:00', '22:00'], 'La opción 2 ya pasó'],
            'dos iguales' => [[$manana, '20:00', '22:00'], [$manana, '20:00', '23:00'], 'Las opciones 1 y 2 empiezan el mismo día a la misma hora.'],
            // E3-13.F8: «Me sirve» se marca hasta el día anterior a la primera franja.
            'una de hoy' => [[$manana, '20:00', '22:00'], [$hoy, '23:58', '23:59'], 'La opción 2 es de hoy'],
        ];
        foreach ($casos as $caso => [$primera, $segunda, $motivo]) {
            $respuesta = $this->enviar($ruta, [
                'minimo_asistentes' => '5',
                'franjas' => [
                    ['fecha' => $primera[0], 'hora_inicio' => $primera[1], 'hora_fin' => $primera[2]],
                    ['fecha' => $segunda[0], 'hora_inicio' => $segunda[1], 'hora_fin' => $segunda[2]],
                ],
            ]);
            self::assertSame(422, $respuesta->estado(), $caso);
            self::assertStringContainsString($motivo, $respuesta->cuerpo(), $caso);
            // Lo que cargó vuelve en el formulario.
            self::assertStringContainsString("value=\"{$segunda[0]}\"", $respuesta->cuerpo(), $caso);
        }

        // Una sola franja: el formulario pide al menos dos.
        $una = $this->enviar($ruta, [
            'minimo_asistentes' => '5',
            'franjas' => [['fecha' => $manana, 'hora_inicio' => '20:00', 'hora_fin' => '22:00']],
        ]);
        self::assertSame(422, $una->estado());
        self::assertStringContainsString('Proponé al menos 2 franjas', $una->cuerpo());
        // E4-09.3: el error del conjunto lo describen la lista y la fecha y el inicio de cada opción,
        // así se lee al tabular por ellas. El fin no: su aria-describedby lo maneja el gestor de
        // franjas, para el aviso del rango.
        $documento = HTMLDocument::createFromString($una->cuerpo(), LIBXML_NOERROR);
        self::assertStringContainsString('Proponé al menos 2 franjas', (string) $documento->getElementById('error-franjas')?->textContent);
        self::assertSame('error-franjas', $documento->querySelector('[data-contenedor-franjas]')?->getAttribute('aria-describedby'));
        foreach ($documento->querySelectorAll('[data-contenedor-franjas] .fila-franja-horaria') as $fila) {
            self::assertSame('error-franjas', $fila->querySelector('[data-campo-fecha]')?->getAttribute('aria-describedby'));
            self::assertSame('error-franjas', $fila->querySelector('[data-campo-inicio]')?->getAttribute('aria-describedby'));
            self::assertNull($fila->querySelector('[data-campo-fin]')?->getAttribute('aria-describedby'));
        }

        // Bien cargadas, se proponen.
        $bien = $this->enviar($ruta, [
            'minimo_asistentes' => '5',
            'franjas' => [
                ['fecha' => $manana, 'hora_inicio' => '20:00', 'hora_fin' => '22:00'],
                ['fecha' => $manana, 'hora_inicio' => '18:00', 'hora_fin' => ''],
            ],
        ]);
        self::assertSame(303, $bien->estado());
        self::assertSame("/cartelera/{$funcion->id}?acuerdo_propuesto=1", $bien->cabecera('Location'));
    }

    public function testProponerFranjasConUnAcuerdoYaAbiertoMuestraElMotivo(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
        self::acuerdoEnFunciones('abierto');

        $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/proponer-franjas", [
            'minimo_asistentes' => '5',
            'franja_inicio' => [self::dentroDe(10, '20:00:00'), self::dentroDe(11, '20:00:00')],
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertStringContainsString('ya tiene un acuerdo de fecha abierto', $respuesta->cuerpo());
    }

    public function testProponerAcuerdoRechazaFuncionEnOtroEstado(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala); // está en_votacion_titulos, no en_acuerdo_fecha

        $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/acuerdo", [
            'minimo_asistentes' => '15',
            'franja_inicio' => [self::dentroDe(10, '20:00:00')],
        ]);

        self::assertSame(404, $respuesta->estado());
    }

    /**
     * Criterio E3-13.4: Si la franja ganadora alcanza el mínimo, la función pasa a programada con esa fecha.
     */
    public function testConfirmarAcuerdoSiAlcanzaMinimoPasaAProgramadaConEsaFecha(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);

        self::assertSame('en_acuerdo_fecha', $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$funcion->id]));

        // El organizador confirma la franja ganadora
        $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/confirmar-acuerdo", ['volver' => 'ficha']);

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/cartelera/{$funcion->id}?guardada=1", $respuesta->cabecera('Location'));

        // La función ahora está programada y con la fecha fijada
        $fila = $this->base->fila('SELECT estado, fecha_hora FROM funcion WHERE id = ?', [$funcion->id]) ?? self::fail();
        self::assertSame('programada', $fila['estado']);
        // La de la franja que lidera en el doble de funciones, a diez días de hoy.
        self::assertSame(self::dentroDe(10, '20:00:00'), $fila['fecha_hora']);

        // La cartelera pública ahora muestra la función como programada
        $cartelera = $this->pedir('GET', "/cartelera/{$funcion->id}")->cuerpo();
        self::assertStringContainsStringIgnoringCase('programada', $cartelera);
        // «12 nov» de «Jue 12 nov · 20:00»
        $diaYMes = explode(' ', explode(' · ', TarjetaDeFuncion::fecha(new DateTimeImmutable(self::dentroDe(10, '20:00:00'))))[0], 2)[1];
        self::assertStringContainsString($diaYMes, $cartelera);
    }

    /**
     * E3-13.F4: con la franja que lidera ya empezada, confirmar muestra el motivo y la función
     * sigue en acuerdo. Antes, funciones cerraba el acuerdo y la función quedaba trabada.
     */
    public function testConfirmarAcuerdoConLaGanadoraYaEmpezadaMuestraElMotivoYNoTraba(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
        self::acuerdoEnFunciones('pasada');

        $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/confirmar-acuerdo", ['volver' => 'ficha']);

        self::assertSame(303, $respuesta->estado());
        self::assertStringContainsString('error_acuerdo=' . rawurlencode('La franja ganadora ya empezó'), $respuesta->cabecera('Location') ?? '');

        // La ficha se lo muestra al organizador.
        $ficha = $this->pedir('GET', (string) $respuesta->cabecera('Location'))->cuerpo();
        self::assertStringContainsString('No se pudo actualizar el acuerdo', $ficha);
        self::assertStringContainsString('La franja ganadora ya empezó', $ficha);
        self::assertSame('en_acuerdo_fecha', $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$funcion->id]));
    }

    /**
     * E3-13.F4: confirmar o cerrar un acuerdo que ya se cerró (dos clics, dos pestañas) muestra el
     * motivo. Antes era el 422 de funciones, que llegaba como una página 500.
     */
    public function testConfirmarOCerrarUnAcuerdoYaCerradoMuestraElMotivo(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
        self::acuerdoEnFunciones('sin_minimo');

        foreach (['confirmar-acuerdo', 'cerrar-sin-minimo'] as $accion) {
            $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/{$accion}", []);
            self::assertSame(303, $respuesta->estado(), $accion);
            self::assertStringContainsString(rawurlencode('El acuerdo de fecha ya está cerrado.'), $respuesta->cabecera('Location') ?? '', $accion);
        }

        // Sin franjas propuestas, tampoco.
        self::acuerdoEnFunciones('ninguno');
        $sinFranjas = $this->enviar("/organizador/funciones/{$funcion->id}/confirmar-acuerdo", []);
        self::assertStringContainsString(rawurlencode('Esta función todavía no tiene franjas propuestas.'), $sinFranjas->cabecera('Location') ?? '');
    }

    public function testConfirmarAcuerdoRechazaFuncionEnOtroEstado(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala); // está en_votacion_titulos

        $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/confirmar-acuerdo", []);
        self::assertSame(404, $respuesta->estado());
    }

    public function testConfirmarAcuerdoRechazaOrganizadorAjeno(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);

        $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/confirmar-acuerdo", [], usuario: self::OTRO_ORGANIZADOR);
        self::assertSame(404, $respuesta->estado());
    }

    /**
     * Criterio E3-13.5: Si no alcanza el mínimo, la función no se programa y el organizador decide qué hacer.
     */
    public function testCerrarAcuerdoSinMinimoNoProgramaLaFuncionYPermiteDecidirAlOrganizador(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);

        self::assertSame('en_acuerdo_fecha', $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$funcion->id]));

        // El organizador cierra el acuerdo sin alcanzar el quórum
        $cierre = $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-sin-minimo", []);
        self::assertSame(303, $cierre->estado());

        // La función NO pasa a programada; se mantiene en acuerdo de fecha sin fecha fijada
        $fila = $this->base->fila('SELECT estado, fecha_hora FROM funcion WHERE id = ?', [$funcion->id]) ?? self::fail();
        self::assertSame('en_acuerdo_fecha', $fila['estado']);
        self::assertNull($fila['fecha_hora']);

        // Ficha muestra el estado sin quórum y las opciones de decisión para el organizador. Iban
        // dentro de un if sobre un texto que la ficha ya no tiene, así que no comprobaban nada
        // (F-01.3): ahora sí.
        $fichaOrganizador = $this->pedir('GET', "/cartelera/{$funcion->id}")->cuerpo();
        self::assertStringContainsString('La función no se programa por falta de quórum', $fichaOrganizador);
        self::assertStringContainsString('Proponer nuevas franjas horarias', $fichaOrganizador);
        self::assertStringContainsString('Cancelar función', $fichaOrganizador);

        // Ficha pública para espectadores explica que no se programó
        $fichaEspectador = $this->pedir('GET', "/cartelera/{$funcion->id}", usuario: null)->cuerpo();
        self::assertStringContainsString('La función no se programa por falta de quórum', $fichaEspectador);
        self::assertStringNotContainsString('Proponer nuevas franjas horarias', $fichaEspectador);

        // El organizador decide cancelar la función
        $cancelacion = $this->enviar("/organizador/funciones/{$funcion->id}/cancelar", []);
        self::assertSame(303, $cancelacion->estado());
        self::assertSame('cancelada', $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$funcion->id]));
    }

    /**
     * E3-13.F1: «Mis funciones» muestra el paso que sigue en cada función en acuerdo de fecha.
     */
    public function testMisFuncionesOfreceProponerLasFranjasSiTodaviaNoHayAcuerdo(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
        self::acuerdoEnFunciones('ninguno');

        $lista = $this->pedir()->cuerpo();

        self::assertStringContainsString("/organizador/funciones/{$funcion->id}/proponer-franjas", $lista);
        self::assertStringContainsString('Proponer franjas', $lista);
    }

    public function testMisFuncionesOfreceConfirmarLaFechaSiLaQueLideraLlegoAlMinimo(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
        self::acuerdoEnFunciones('abierto'); // la que lidera tiene 2 votos y el mínimo es 2

        $lista = $this->pedir()->cuerpo();

        self::assertStringContainsString("/organizador/funciones/{$funcion->id}/confirmar-acuerdo", $lista);
        self::assertStringContainsString('Confirmar fecha', $lista);
    }

    public function testMisFuncionesMuestraElAcuerdoSinQuorumConNuevasFechas(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
        self::acuerdoEnFunciones('sin_minimo');

        $lista = $this->pedir()->cuerpo();

        self::assertStringContainsString('Sin quórum', $lista);
        self::assertStringContainsString('Nuevas fechas', $lista);
        self::assertStringNotContainsString('Confirmar fecha', $lista);
    }

    public function testMisFuncionesSaleIgualSiFuncionesNoResponde(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
        self::acuerdoEnFunciones('caido');

        $respuesta = $this->pedir();

        self::assertSame(200, $respuesta->estado());
        // Sin el dato, lleva a la ficha, que tiene todas las acciones del acuerdo.
        self::assertStringContainsString('Gestionar franjas', $respuesta->cuerpo());
    }

    /**
     * E3-13.F1: el alta no ofrece abrir un acuerdo de fecha directo: el acuerdo viene después de
     * la votación de títulos.
     */
    public function testElAltaNoOfreceAbrirUnAcuerdoDeFecha(): void
    {
        $this->sala(self::ORGANIZADORA, 'El Galpón');

        $formulario = $this->pedir('GET', '/organizador/funciones/nueva')->cuerpo();

        self::assertStringNotContainsString('en_acuerdo_fecha', $formulario);
        self::assertStringNotContainsString('Abrir acuerdo de fecha', $formulario);
    }

    /**
     * E3-13.F5: «Cancelar función» en la ficha sin quórum lleva a la página de confirmación de
     * siempre, en vez de un confirm() del navegador.
     */
    public function testCancelarSinQuorumPasaPorLaPaginaDeConfirmacion(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
        self::acuerdoEnFunciones('sin_minimo');

        $ficha = $this->pedir('GET', "/cartelera/{$funcion->id}")->cuerpo();
        self::assertStringContainsString("href=\"/organizador/funciones/{$funcion->id}/cancelar\"", $ficha);
        self::assertStringNotContainsString('onsubmit', $ficha);

        $confirmacion = $this->pedir('GET', "/organizador/funciones/{$funcion->id}/cancelar");
        self::assertSame(200, $confirmacion->estado());
        self::assertStringContainsString('Confirmar cancelación', $confirmacion->cuerpo());
    }

    /**
     * F-01.3: después de cerrar sin mínimo, la función no queda trabada. Las dos salidas que le da
     * el sitio al organizador funcionan hasta el final: proponer otras fechas abre un acuerdo nuevo,
     * y cancelarla la cancela. Hasta ahora sólo se probaba que se ofrecieran.
     */
    public function testDespuesDeCerrarSinMinimoSeProponenOtrasFechasOSeCancela(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        [$otrasFechas, $cancelada] = [$this->funcion($sala), $this->funcion($sala)];
        foreach ([$otrasFechas, $cancelada] as $funcion) {
            $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);
            self::acuerdoEnFunciones('abierto');
            self::assertSame(303, $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-sin-minimo", [])->estado());
        }

        $propuesta = $this->enviar("/organizador/funciones/{$otrasFechas->id}/acuerdo", [
            'minimo_asistentes' => '10',
            'franja_inicio' => [self::dentroDe(20, '20:00:00'), self::dentroDe(21, '21:00:00')],
            'franja_fin' => [self::dentroDe(20, '22:30:00'), ''],
        ]);
        self::assertSame(303, $propuesta->estado());
        self::assertSame("/cartelera/{$otrasFechas->id}?acuerdo_propuesto=1", $propuesta->cabecera('Location'));
        self::assertSame('en_acuerdo_fecha', $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$otrasFechas->id]));

        self::acuerdoEnFunciones('sin_minimo');
        self::assertSame(303, $this->enviar("/organizador/funciones/{$cancelada->id}/cancelar", [])->estado());
        self::assertSame('cancelada', $this->base->valor('SELECT estado FROM funcion WHERE id = ?', [$cancelada->id]));
    }

    public function testCerrarAcuerdoSinMinimoRechazaOrganizadorAjeno(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala);
        $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-votacion", []);

        $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-sin-minimo", [], usuario: self::OTRO_ORGANIZADOR);
        self::assertSame(404, $respuesta->estado());
    }

    public function testCerrarAcuerdoSinMinimoRechazaFuncionEnOtroEstado(): void
    {
        $sala = $this->sala(self::ORGANIZADORA, 'El Galpón');
        $funcion = $this->funcion($sala); // está en_votacion_titulos

        $respuesta = $this->enviar("/organizador/funciones/{$funcion->id}/cerrar-sin-minimo", []);
        self::assertSame(404, $respuesta->estado());
    }
}

