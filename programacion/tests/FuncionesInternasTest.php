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
 * GET /interno/funciones/{id} de punta a punta: la aplicación entera con las rutas de
 * programación, la autorización por token de la API interna y un MySQL de verdad.
 */
final class FuncionesInternasTest extends TestCase
{
    use ConBaseDePruebas;

    private const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const TOKEN_DE_FUNCIONES = 'token-de-funciones';
    private const TOKEN_DE_CUENTAS = 'token-de-cuentas';

    private BaseDeDatos $base;
    private Configuracion $configuracion;
    private RepositorioDeSalas $salas;
    private RepositorioDeFunciones $funciones;
    private Sala $sala;

    /** @var list<string> las dos obras del catálogo */
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

    private function pedir(string $id, string $llamador = 'funciones', string $token = self::TOKEN_DE_FUNCIONES): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('programacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', "/interno/funciones/{$id}", [], [], [
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
            'sala' => ['id' => $this->sala->id, 'nombre' => 'Cine Club El Galpón', 'localidad' => 'Luján', 'habilitada' => true],
            'organizador_id' => self::ORGANIZADORA,
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
     * Sólo funciones: cuentas tiene un token válido, pero la ruta no lo acepta.
     */
    public function testSoloLaPideFuncionesConSuToken(): void
    {
        $id = $this->funcion()->id;

        self::assertSame(403, $this->pedir($id, 'cuentas', self::TOKEN_DE_CUENTAS)->estado());
        self::assertSame(403, $this->pedir($id, 'funciones', self::TOKEN_DE_CUENTAS)->estado());
        self::assertSame(403, $this->pedir($id, 'funciones', '')->estado());
    }
}
