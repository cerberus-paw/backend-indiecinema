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
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\ImagenSubida;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Pruebas\Controladores\FormularioDeSalaTest;
use IndieCinema\Programacion\Pruebas\Dobles\Imagenes;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Servicios\ArchivosDeSalas;
use PHPUnit\Framework\TestCase;

/**
 * La API interna de salas de punta a punta (EF-07.1): la aplicación entera con las rutas de
 * programación, la autorización por token y un MySQL de verdad. La llama moderación, que tiene las
 * solicitudes de sala y no tiene acceso al esquema de programación.
 */
final class SalasInternasTest extends TestCase
{
    use ConBaseDePruebas;

    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const MODERADOR = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const TOKEN_DE_MODERACION = 'token-de-moderacion';
    private const TOKEN_DE_FUNCIONES = 'token-de-funciones';

    private BaseDeDatos $base;
    private Configuracion $configuracion;
    private RepositorioDeSalas $salas;

    /** @var resource */
    private $salidaDelLog;

    /** El volumen de archivos, uno por prueba. */
    private string $carpeta;

    protected function setUp(): void
    {
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'llamadores' => [
                'moderacion' => hash('sha256', self::TOKEN_DE_MODERACION),
                'funciones' => hash('sha256', self::TOKEN_DE_FUNCIONES),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->salas = new RepositorioDeSalas($this->base);
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->carpeta}/salas/*") ?: []);
        @rmdir("{$this->carpeta}/salas");
        @rmdir($this->carpeta);
    }

    /**
     * @return list<string> las fotos que hay en el volumen
     */
    private function fotosGuardadas(): array
    {
        return array_map('basename', glob("{$this->carpeta}/salas/*") ?: []);
    }

    /**
     * El alta de una sala como la manda moderación: los datos válidos del formulario, salvo lo que
     * se cambie, y la foto como archivo subido.
     *
     * @param array<string, string>     $cambios
     * @param ?array<string, mixed>     $foto    el archivo; sin uno, la foto de prueba
     */
    private function crear(string $id, array $cambios = [], ?array $foto = null, string $organizador = self::ORGANIZADORA, string $llamador = 'moderacion', string $token = self::TOKEN_DE_MODERACION): Respuesta
    {
        return $this->pedir(
            'POST',
            '/interno/salas',
            cuerpo: $cambios + ['id' => $id, 'organizador_id' => $organizador] + FormularioDeSalaTest::camposValidos(),
            archivos: ['imagen' => $foto ?? Imagenes::subida('sala.png')],
            llamador: $llamador,
            token: $token,
        );
    }

    /**
     * Un archivo cualquiera, como si lo hubiera subido moderación con ese nombre.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    private static function archivo(string $contenido, string $nombre): array
    {
        $temporal = (string) tempnam(sys_get_temp_dir(), 'subida');
        file_put_contents($temporal, $contenido);

        return ['name' => $nombre, 'type' => 'image/jpeg', 'tmp_name' => $temporal, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contenido)];
    }

    private function cuantasSalas(): int
    {
        return (int) $this->base->valor('SELECT COUNT(*) FROM sala');
    }

    private function sala(bool $habilitada = true, string $nombre = 'Cine Club El Galpón'): Sala
    {
        $sala = Sala::nueva(self::ORGANIZADORA, new DatosDeSala($nombre, 'Un galpón.', 'Av. San Martín 450', 'Luján', 60, 2, 120, 15), 'sala.jpg');
        $this->salas->agregar($sala);
        if ($habilitada) {
            $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);
        }

        return $this->salas->buscar($sala->id) ?? self::fail('No se guardó la sala.');
    }

    /**
     * @param array<string, mixed> $consulta
     * @param array<string, mixed> $cuerpo
     * @param array<string, mixed> $archivos
     */
    private function pedir(
        string $metodo,
        string $ruta,
        array $consulta = [],
        array $cuerpo = [],
        array $archivos = [],
        string $llamador = 'moderacion',
        string $token = self::TOKEN_DE_MODERACION,
    ): Respuesta {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion($metodo, $ruta, $consulta, $cuerpo, [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ], [], $archivos));
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function habilitacion(string $salaId, array $cuerpo, string $llamador = 'moderacion', string $token = self::TOKEN_DE_MODERACION): Respuesta
    {
        return $this->pedir('POST', "/interno/salas/{$salaId}/habilitacion", cuerpo: $cuerpo, llamador: $llamador, token: $token);
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    /**
     * Las salas del listado público, por nombre.
     */
    private function enElListado(): string
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', '/salas'))->cuerpo();
    }

    public function testSuspenderSacaLaSalaDelListadoYNoLaDejaProgramar(): void
    {
        $sala = $this->sala();
        self::assertStringContainsString('Cine Club El Galpón', $this->enElListado());

        $respuesta = $this->habilitacion($sala->id, ['habilitada' => false, 'motivo' => 'Venció el seguro.', 'moderador_id' => self::MODERADOR]);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(['id' => $sala->id, 'habilitada' => false], json_decode($respuesta->cuerpo(), true));
        self::assertStringNotContainsString('Cine Club El Galpón', $this->enElListado());
        $suspendida = $this->salas->buscar($sala->id) ?? self::fail('No está la sala.');
        try {
            $suspendida->crearFuncion([Uuid::nuevo()], 40, 3500.0);
            self::fail('Una sala suspendida programó una función.');
        } catch (ReglaIncumplida $error) {
            self::assertSame('La sala no está habilitada: no se pueden programar funciones.', $error->getMessage());
        }
        self::assertStringContainsString(self::MODERADOR . " deshabilitó la sala {$sala->id}: Venció el seguro.", $this->log());
    }

    public function testHabilitarVuelveAMostrarLaSala(): void
    {
        $sala = $this->sala(habilitada: false);

        $respuesta = $this->habilitacion($sala->id, ['habilitada' => true, 'motivo' => 'Documentación al día.', 'moderador_id' => self::MODERADOR]);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertStringContainsString('Cine Club El Galpón', $this->enElListado());
        self::assertStringContainsString(self::MODERADOR . " habilitó la sala {$sala->id}: Documentación al día.", $this->log());
    }

    public function testPedirElEstadoQueYaTieneNoHaceNada(): void
    {
        $sala = $this->sala();

        $respuesta = $this->habilitacion($sala->id, ['habilitada' => true, 'motivo' => 'Otra vez.', 'moderador_id' => self::MODERADOR]);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertStringNotContainsString('habilitó la sala', $this->log());
    }

    public function testUnaSalaQueNoExisteODadaDeBajaEs404(): void
    {
        $dadaDeBaja = $this->sala();
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$dadaDeBaja->id]);
        $cuerpo = ['habilitada' => false, 'motivo' => 'Venció el seguro.', 'moderador_id' => self::MODERADOR];

        self::assertSame(404, $this->habilitacion(Uuid::nuevo(), $cuerpo)->estado());
        self::assertSame(404, $this->habilitacion($dadaDeBaja->id, $cuerpo)->estado());
        self::assertSame(404, $this->habilitacion('no-es-un-id', $cuerpo)->estado());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function habilitacionesMalFormadas(): iterable
    {
        yield 'sin habilitada' => [['motivo' => 'Venció el seguro.', 'moderador_id' => self::MODERADOR]];
        yield 'habilitada como texto' => [['habilitada' => 'false', 'motivo' => 'Venció el seguro.', 'moderador_id' => self::MODERADOR]];
        yield 'sin motivo' => [['habilitada' => false, 'moderador_id' => self::MODERADOR]];
        yield 'motivo vacío' => [['habilitada' => false, 'motivo' => '  ', 'moderador_id' => self::MODERADOR]];
        yield 'moderador que no es UUID' => [['habilitada' => false, 'motivo' => 'Venció el seguro.', 'moderador_id' => 'yo']];
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('habilitacionesMalFormadas')]
    public function testUnPedidoMalFormadoEs400(array $cuerpo): void
    {
        $sala = $this->sala();

        self::assertSame(400, $this->habilitacion($sala->id, $cuerpo)->estado());
        self::assertTrue(($this->salas->buscar($sala->id) ?? self::fail('No está la sala.'))->habilitada);
    }

    public function testLeeVariasSinLasDadasDeBajaNiLasQueNoExisten(): void
    {
        $galpon = $this->sala();
        $sinHabilitar = $this->sala(habilitada: false, nombre: 'El Microcine');
        $dadaDeBaja = $this->sala(nombre: 'La Vieja Usina');
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$dadaDeBaja->id]);

        $respuesta = $this->pedir('GET', '/interno/salas', ['ids' => implode(',', [$galpon->id, $sinHabilitar->id, $dadaDeBaja->id, Uuid::nuevo()])]);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertEquals([
            $galpon->id => ['nombre' => 'Cine Club El Galpón', 'localidad' => 'Luján', 'organizador_id' => self::ORGANIZADORA, 'habilitada' => true],
            $sinHabilitar->id => ['nombre' => 'El Microcine', 'localidad' => 'Luján', 'organizador_id' => self::ORGANIZADORA, 'habilitada' => false],
        ], json_decode($respuesta->cuerpo(), true));
    }

    public function testSinNingunaQueExistaEsUnObjetoVacio(): void
    {
        $respuesta = $this->pedir('GET', '/interno/salas', ['ids' => Uuid::nuevo()]);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('{}', $respuesta->cuerpo());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function idsMalPedidos(): iterable
    {
        yield 'sin ids' => [null];
        yield 'vacío' => [''];
        yield 'uno que no es UUID' => [Uuid::nuevo() . ',no-es-un-id'];
        yield 'más de 50' => [implode(',', array_map(static fn (): string => Uuid::nuevo(), range(1, 51)))];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('idsMalPedidos')]
    public function testLosIdsMalPedidosSon400(?string $ids): void
    {
        self::assertSame(400, $this->pedir('GET', '/interno/salas', $ids === null ? [] : ['ids' => $ids])->estado());
    }

    public function testLeerVariasSoloLaPideModeracion(): void
    {
        self::assertSame(403, $this->pedir('GET', '/interno/salas', ['ids' => $this->sala()->id], llamador: 'funciones', token: self::TOKEN_DE_FUNCIONES)->estado());
    }

    public function testCrearDejaLaSalaHabilitadaEnElListadoConSuFoto(): void
    {
        $id = Uuid::nuevo();

        $respuesta = $this->crear($id);

        self::assertSame(201, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(
            ['id' => $id, 'nombre' => 'Cine Club El Galpón', 'localidad' => 'Luján', 'organizador_id' => self::ORGANIZADORA, 'habilitada' => true],
            json_decode($respuesta->cuerpo(), true),
        );
        self::assertStringContainsString('Cine Club El Galpón', $this->enElListado());
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', fopen('php://memory', 'w')));
        self::assertSame(file_get_contents(Imagenes::ruta('sala.png')), $aplicacion->atender(new Peticion('GET', "/salas/{$id}/imagen"))->cuerpo());
        $sala = $this->salas->buscar($id) ?? self::fail('No se guardó la sala.');
        self::assertSame(self::ORGANIZADORA, $sala->organizadorId);
        self::assertSame(60, $sala->datos->capacidad);
    }

    /**
     * Si moderación creó la sala y no llegó a guardar su id, el reintento no crea otra ni guarda
     * otra foto.
     */
    public function testRepetirElPedidoNoCreaOtra(): void
    {
        $id = Uuid::nuevo();
        self::assertSame(201, $this->crear($id)->estado());

        $repetido = $this->crear($id, ['nombre' => 'Otro nombre']);

        self::assertSame(200, $repetido->estado(), $repetido->cuerpo());
        self::assertSame('Cine Club El Galpón', json_decode($repetido->cuerpo(), true)['nombre']);
        self::assertSame(1, $this->cuantasSalas());
        self::assertCount(1, $this->fotosGuardadas());
    }

    /**
     * La sala de una solicitud que se mudó a moderación ya existe, sin habilitar: al aprobarla queda
     * con los datos y la foto que se corrigieron allá, habilitada, y la foto vieja se borra.
     */
    public function testCompletaLaSalaSinHabilitarDeEseId(): void
    {
        $vieja = $this->sala(habilitada: false, nombre: 'El Galponcito');
        $fotoVieja = (new ArchivosDeSalas($this->configuracion))->guardar(new ImagenSubida(Imagenes::subida('sala.jpg')['tmp_name'], 'jpg'));
        $this->base->ejecutar('UPDATE sala SET imagen = ? WHERE id = ?', [$fotoVieja, $vieja->id]);

        $respuesta = $this->crear($vieja->id);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $sala = $this->salas->buscar($vieja->id) ?? self::fail('No está la sala.');
        self::assertTrue($sala->habilitada);
        self::assertSame('Cine Club El Galpón', $sala->datos->nombre);
        self::assertNotSame($fotoVieja, $sala->imagen);
        self::assertSame([(string) $sala->imagen], $this->fotosGuardadas());
        self::assertSame(1, $this->cuantasSalas());
    }

    public function testLaSalaDeOtroOrganizadorEs409(): void
    {
        $ajena = $this->sala();
        $sinHabilitar = $this->sala(habilitada: false, nombre: 'El Microcine');
        $otro = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

        self::assertSame(409, $this->crear($ajena->id, organizador: $otro)->estado());
        self::assertSame(409, $this->crear($sinHabilitar->id, organizador: $otro)->estado());
        self::assertFalse(($this->salas->buscar($sinHabilitar->id) ?? self::fail('No está la sala.'))->habilitada);
        self::assertSame([], $this->fotosGuardadas());
    }

    public function testElIdDeUnaDadaDeBajaEs409(): void
    {
        $dadaDeBaja = $this->sala();
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [$dadaDeBaja->id]);

        self::assertSame(409, $this->crear($dadaDeBaja->id)->estado());
        self::assertSame([], $this->fotosGuardadas());
    }

    /**
     * @return iterable<string, array{array<string, string>, ?array<string, mixed>, string, string}>
     */
    public static function altasInvalidas(): iterable
    {
        yield 'sin nombre' => [['nombre' => ''], null, 'nombre', 'Falta el nombre.'];
        yield 'capacidad fuera de rango' => [['capacidad' => '0'], null, 'capacidad', 'La capacidad tiene que ser un número de butacas entre 1 y 2000.'];
        yield 'un .exe con nombre de foto' => [[], self::archivo("MZ\x90\x00" . str_repeat("\x00", 200), 'sala.jpg'), 'imagen', 'La foto tiene que ser JPG, PNG o WebP.'];
        yield 'una foto de más de 5 MB' => [[], self::archivo(str_repeat('a', 5 * 1024 * 1024 + 1), 'sala.jpg'), 'imagen', 'La foto puede pesar hasta 5 MB.'];
        yield 'sin foto' => [[], ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0], 'imagen', 'Falta la foto de la sala.'];
    }

    /**
     * Los mismos errores, con los mismos mensajes, que el formulario de sala: moderación se los
     * muestra al moderador tal cual.
     *
     * @param array<string, string> $cambios
     * @param ?array<string, mixed> $foto
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('altasInvalidas')]
    public function testLosDatosInvalidosSon422ConElErrorDelCampo(array $cambios, ?array $foto, string $campo, string $mensaje): void
    {
        $respuesta = $this->crear(Uuid::nuevo(), $cambios, $foto);

        self::assertSame(422, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame($mensaje, json_decode($respuesta->cuerpo(), true)['errores'][$campo] ?? null);
        self::assertSame(0, $this->cuantasSalas());
        self::assertSame([], $this->fotosGuardadas());
    }

    public function testElIdYElOrganizadorSonUuid(): void
    {
        self::assertSame(400, $this->crear('no-es-un-id')->estado());
        self::assertSame(400, $this->crear(Uuid::nuevo(), organizador: 'yo')->estado());
        self::assertSame(0, $this->cuantasSalas());
    }

    public function testCrearSoloLaPideModeracion(): void
    {
        self::assertSame(403, $this->crear(Uuid::nuevo(), llamador: 'funciones', token: self::TOKEN_DE_FUNCIONES)->estado());
        self::assertSame(0, $this->cuantasSalas());
    }

    public function testSoloLaLlamaModeracion(): void
    {
        $sala = $this->sala();

        $respuesta = $this->habilitacion($sala->id, ['habilitada' => false, 'motivo' => 'Venció el seguro.', 'moderador_id' => self::MODERADOR], 'funciones', self::TOKEN_DE_FUNCIONES);

        self::assertSame(403, $respuesta->estado());
        self::assertTrue(($this->salas->buscar($sala->id) ?? self::fail('No está la sala.'))->habilitada);
    }
}
