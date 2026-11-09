<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use IndieCinema\Nucleo\Pruebas\Dobles\PaginasParaValidar;

/**
 * «Mi cuenta» (/cuenta/) de punta a punta: la aplicación entera con las rutas de cuentas, la
 * plantilla del paquete front y un MySQL de verdad. Se mira el contenido de la página, sin el
 * encabezado, que también muestra el nombre y el rol: los datos tienen que salir de la cuenta.
 */
final class MiCuentaTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    /**
     * Una cuenta creada en marzo de 2025, con espectador y los roles que se pidan.
     *
     * @param list<Rol> $roles además de espectador
     */
    private function agregar(array $roles = []): string
    {
        $id = Uuid::nuevo();
        (new RepositorioDeUsuarios($this->base))->agregar(
            new Usuario($id, 'Eva Ramos', 'eva@ejemplo.com', 'hash', EstadoCuenta::Activa, [Rol::Espectador, ...$roles]),
        );
        $this->base->ejecutar("UPDATE usuario SET creado_en = '2025-03-10 18:30:00' WHERE id = ?", [$id]);

        return $id;
    }

    /**
     * Como llega de nginx: con la sesión, el nombre que muestra el encabezado y el mayor rol.
     */
    private function pedir(?string $id, string $rol = 'espectador'): Respuesta
    {
        $cabeceras = $id === null ? [] : [
            'X-Nginx-Secreto' => self::SECRETO,
            'X-Usuario-Id' => $id,
            'X-Rol' => $rol,
            'X-Usuario-Nombre' => rawurlencode('Eva'),
        ];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('cuentas', fopen('php://memory', 'w')));

        return PaginasParaValidar::anotar('GET', '/cuenta/', $aplicacion->atender(new Peticion('GET', '/', [], [], $cabeceras, [], [])));
    }

    /**
     * El contenido de la página, sin el encabezado ni el pie.
     */
    private static function contenido(Respuesta $respuesta): string
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        preg_match('#<main.*?</main>#s', $respuesta->cuerpo(), $contenido);

        return $contenido[0] ?? '';
    }

    /**
     * La barra lateral de «Mi cuenta».
     */
    private static function barra(Respuesta $respuesta): string
    {
        preg_match('#<nav[^>]*aria-label="Mi cuenta".*?</nav>#s', self::contenido($respuesta), $barra);

        return $barra[0] ?? '';
    }

    public function testMuestraElNombreElCorreoLosRolesYDesdeCuandoTieneLaCuenta(): void
    {
        $contenido = self::contenido($this->pedir($this->agregar()));

        self::assertStringContainsString('Eva Ramos', $contenido);
        self::assertStringContainsString('eva@ejemplo.com', $contenido);
        self::assertStringContainsString('Desde marzo de 2025', $contenido);
        self::assertStringContainsString('Espectador', $contenido);
        self::assertStringNotContainsString('Organizador', $contenido);
    }

    public function testMuestraTodosLosRolesDeLaCuenta(): void
    {
        $contenido = self::contenido($this->pedir($this->agregar([Rol::Organizador]), 'organizador'));

        self::assertStringContainsString('Espectador', $contenido);
        self::assertStringContainsString('Organizador', $contenido);
    }

    public function testLosDatosSonSoloDeLectura(): void
    {
        $contenido = self::contenido($this->pedir($this->agregar()));

        self::assertStringNotContainsString('<form', $contenido);
        self::assertStringNotContainsString('<input', $contenido);
    }

    public function testLaBarraMarcaMisDatosYLlevaAMisReservas(): void
    {
        $barra = self::barra($this->pedir($this->agregar()));

        self::assertMatchesRegularExpression('#<a href="/cuenta/"[^>]*aria-current="page"#', $barra);
        self::assertStringContainsString('href="/funcion/reservas"', $barra);
        self::assertDoesNotMatchRegularExpression('#<a href="/funcion/reservas"[^>]*aria-current#', $barra);
    }

    /**
     * @return iterable<string, array{string, list<Rol>, bool}>
     */
    public static function roles(): iterable
    {
        yield 'espectador' => ['espectador', [], false];
        yield 'organizador' => ['organizador', [Rol::Organizador], true];
        yield 'moderador' => ['moderador', [Rol::Organizador, Rol::Moderador], true];
    }

    /**
     * @param list<Rol> $roles
     */
    #[DataProvider('roles')]
    public function testElPanelDelOrganizadorSoloAQuienAlcanzaElRol(string $rol, array $roles, bool $loVe): void
    {
        $barra = self::barra($this->pedir($this->agregar($roles), $rol));

        self::assertSame($loVe, str_contains($barra, 'href="/organizador/salas"'));
    }

    public function testSinSesionPideIngresar(): void
    {
        self::assertSame(401, $this->pedir(null)->estado());
    }
}
