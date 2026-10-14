<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas;

use DateTimeImmutable;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
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
use PHPUnit\Framework\TestCase;

/**
 * «Mis funciones» y alta para programar una función (E3-11.2) del organizador, de punta a punta.
 */
final class FuncionesDelOrganizadorTest extends TestCase
{
    use ConBaseDePruebas;

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
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
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

        return $aplicacion->atender(new Peticion(
            $metodo,
            (string) parse_url($ruta, PHP_URL_PATH),
            $consulta,
            $cuerpo,
            $cabeceras,
            ['csrf' => self::CSRF],
        ));
    }

    private function enviar(string $ruta, array $cuerpo, ?string $usuario = self::ORGANIZADORA, string $rol = 'organizador'): Respuesta
    {
        return $this->pedir('POST', $ruta, $usuario, $rol, ['_csrf' => self::CSRF, ...$cuerpo]);
    }

    /**
     * Una sala guardada, como la lee el repositorio. Queda habilitada, porque sólo en una
     * habilitada se crean funciones: la prueba que la necesita sin habilitar la deshabilita
     * después.
     */
    private function sala(string $organizador, string $nombre, int $capacidad = 60): Sala
    {
        $sala = Sala::nueva($organizador, new DatosDeSala($nombre, 'Un galpón.', 'Av. San Martín 450', 'Luján', $capacidad, 2, 120, 15), 'sala.jpg');
        $this->salas->agregar($sala);
        $this->base->ejecutar('UPDATE sala SET habilitada = TRUE WHERE id = ?', [$sala->id]);

        return $this->salas->buscar($sala->id) ?? self::fail('No se guardó la sala.');
    }

    /**
     * Una función en votación de títulos, con las dos obras como candidatas.
     */
    private function funcion(Sala $sala): Funcion
    {
        $funcion = $sala->crearFuncion(array_values($this->obras), 40, 3500.0);
        $this->funciones->agregar($funcion);

        return $funcion;
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
}
