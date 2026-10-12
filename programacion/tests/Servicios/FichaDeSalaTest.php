<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Pruebas\Servicios;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\FiltrosDeCartelera;
use IndieCinema\Programacion\Modelo\FuncionEnCartelera;
use IndieCinema\Programacion\Pruebas\ConBaseDePruebas;
use IndieCinema\Programacion\Repositorios\RepositorioDeCartelera;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeGeneros;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Servicios\ArchivosDeSalas;
use IndieCinema\Programacion\Servicios\DatosDePrueba;
use IndieCinema\Programacion\Servicios\ServicioDeSalas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La ficha de una sala contra un MySQL de verdad, con los datos de demostración: quién la ve y
 * qué funciones trae.
 *
 * La página la prueba de punta a punta SalasDelOrganizadorTest cuando da 404; la de una sala que
 * se ve llega con su plantilla.
 */
final class FichaDeSalaTest extends TestCase
{
    use ConBaseDePruebas;

    /** Cine Club El Alambique, de Luján. */
    private const ALAMBIQUE = '00000001-0000-4000-8000-000000000001';
    private const OTRO_ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private BaseDeDatos $base;
    private string $carpeta;
    private ServicioDeSalas $servicio;
    private RepositorioDeCartelera $cartelera;

    protected function setUp(): void
    {
        // Antes de saltearla: tearDown() corre igual y la usa.
        $this->carpeta = sys_get_temp_dir() . '/archivos-' . bin2hex(random_bytes(4));
        $configuracion = new Configuracion([
            'app' => ['subsistema' => 'programacion', 'entorno' => 'desarrollo'],
            'archivos' => ['carpeta' => $this->carpeta],
            'base' => self::baseDePruebas(),
        ]);
        $this->base = self::baseConTablasNuevas($configuracion);
        $salas = new RepositorioDeSalas($this->base);
        $this->cartelera = new RepositorioDeCartelera($this->base);
        $this->servicio = new ServicioDeSalas($salas, new ArchivosDeSalas($configuracion), new Log('programacion', fopen('php://memory', 'w')), $this->cartelera);

        DatosDePrueba::para(
            $configuracion,
            new RepositorioDeGeneros($this->base),
            $salas,
            new RepositorioDeObras($this->base),
            new RepositorioDeFunciones($this->base),
        )->cargar();
    }

    protected function tearDown(): void
    {
        foreach (['salas', 'afiches'] as $subcarpeta) {
            array_map('unlink', glob("{$this->carpeta}/{$subcarpeta}/*") ?: []);
            @rmdir("{$this->carpeta}/{$subcarpeta}");
        }
        @rmdir($this->carpeta);
    }

    private static function usuario(string $id, Rol $rol = Rol::Organizador): Usuario
    {
        return new Usuario($id, $rol);
    }

    /**
     * @param list<FuncionEnCartelera> $funciones
     *
     * @return list<string>
     */
    private static function ids(array $funciones): array
    {
        return array_map(static fn (FuncionEnCartelera $funcion): string => $funcion->id, $funciones);
    }

    private function deshabilitar(string $id): void
    {
        $this->base->ejecutar('UPDATE sala SET habilitada = FALSE WHERE id = ?', [$id]);
    }

    /**
     * Quien sea que la pida: una sala habilitada es pública.
     *
     * @return iterable<string, array{?Usuario}>
     */
    public static function visitantes(): iterable
    {
        yield 'sin sesión' => [null];
        yield 'un espectador' => [self::usuario(Uuid::nuevo(), Rol::Espectador)];
        yield 'otro organizador' => [self::usuario(self::OTRO_ORGANIZADOR)];
        yield 'su organizador' => [self::usuario(DatosDePrueba::ORGANIZADOR_DEFECTO_ID)];
    }

    #[DataProvider('visitantes')]
    public function testUnaSalaHabilitadaSeVeConSusProximasFunciones(?Usuario $visitante): void
    {
        $ficha = $this->servicio->ficha(self::ALAMBIQUE, $visitante);

        self::assertSame(self::ALAMBIQUE, $ficha->sala->id);
        self::assertSame('Cine Club El Alambique', $ficha->sala->datos->nombre);
        self::assertSame('San Martín 420', $ficha->sala->datos->direccion);
        // Las de la cartelera en esta sala, en el mismo orden, y ninguna de otra.
        $deLaCartelera = array_values(array_filter(
            $this->cartelera->todas(new FiltrosDeCartelera()),
            static fn (FuncionEnCartelera $funcion): bool => $funcion->salaId === self::ALAMBIQUE,
        ));
        self::assertNotEmpty($ficha->funciones);
        self::assertSame(self::ids($deLaCartelera), self::ids($ficha->funciones));
    }

    public function testLasFuncionesQueNoEstanEnLaCarteleraNoSeMuestran(): void
    {
        [$primera] = $this->servicio->ficha(self::ALAMBIQUE, null)->funciones;
        $this->base->ejecutar("UPDATE funcion SET estado = 'cancelada' WHERE id = ?", [$primera->id]);

        self::assertNotContains($primera->id, self::ids($this->servicio->ficha(self::ALAMBIQUE, null)->funciones));
    }

    public function testUnaSalaNoHabilitadaNoSeVePublicamente(): void
    {
        $this->deshabilitar(self::ALAMBIQUE);

        foreach ([null, self::usuario(self::OTRO_ORGANIZADOR), self::usuario(Uuid::nuevo(), Rol::Administrador)] as $usuario) {
            try {
                $this->servicio->ficha(self::ALAMBIQUE, $usuario);
                self::fail('Se vio una sala sin habilitar.');
            } catch (ExcepcionHttp $error) {
                self::assertSame(404, $error->estado);
            }
        }
    }

    public function testSuOrganizadorLaVeSinHabilitarYSinFunciones(): void
    {
        $this->deshabilitar(self::ALAMBIQUE);

        $ficha = $this->servicio->ficha(self::ALAMBIQUE, self::usuario(DatosDePrueba::ORGANIZADOR_DEFECTO_ID));

        self::assertFalse($ficha->sala->habilitada);
        // No están en la cartelera hasta que se habilite.
        self::assertSame([], $ficha->funciones);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function salasQueNoEstan(): iterable
    {
        yield 'un id que no existe' => [Uuid::nuevo()];
        yield 'un id que no es un UUID' => ['1'];
        yield 'una inyección' => ["' OR '1'='1"];
    }

    #[DataProvider('salasQueNoEstan')]
    public function testUnaSalaInexistenteDa404(string $id): void
    {
        $this->expectExceptionObject(ExcepcionHttp::noEncontrada());

        $this->servicio->ficha($id, self::usuario(DatosDePrueba::ORGANIZADOR_DEFECTO_ID));
    }

    public function testUnaSalaDadaDeBajaNoLaVeNiSuOrganizador(): void
    {
        $this->base->ejecutar('UPDATE sala SET dada_de_baja_en = NOW() WHERE id = ?', [self::ALAMBIQUE]);

        $this->expectExceptionObject(ExcepcionHttp::noEncontrada());

        $this->servicio->ficha(self::ALAMBIQUE, self::usuario(DatosDePrueba::ORGANIZADOR_DEFECTO_ID));
    }
}
