<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El listado de usuarios del administrador de punta a punta: la aplicación entera con las rutas
 * de cuentas, la plantilla del paquete front y un MySQL de verdad. En desarrollo, para que Twig
 * falle si la plantilla usa una variable que el controlador no le pasa.
 */
final class AdministracionDeUsuariosTest extends TestCase
{
    use ConBaseDePruebas;

    private const SECRETO = 'secreto-de-nginx';
    private const URL = '/administracion/usuarios';
    private const ADMINISTRADOR = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private Configuracion $configuracion;
    private RepositorioDeUsuarios $usuarios;

    protected function setUp(): void
    {
        $this->configuracion = $this->configuracion();
        $this->usuarios = new RepositorioDeUsuarios(self::baseConTablasNuevas($this->configuracion));
    }

    /**
     * @param array<string, mixed> $usuarios la sección [usuarios] de config.ini
     */
    private function configuracion(array $usuarios = []): Configuracion
    {
        return new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => self::SECRETO],
            'usuarios' => $usuarios,
        ]);
    }

    /**
     * Como llega de nginx: sin el prefijo y, con un rol, con las cabeceras de la sesión.
     *
     * @param array<string, mixed> $usuarios la sección [usuarios] de config.ini
     */
    private function pedir(string $url = self::URL, ?string $rol = 'administrador', array $usuarios = []): Respuesta
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $cabeceras = $rol === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => self::ADMINISTRADOR, 'X-Rol' => $rol];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion($usuarios), new Log('cuentas', fopen('php://memory', 'w+')));

        return $aplicacion->atender(new Peticion('GET', (string) parse_url($url, PHP_URL_PATH), $consulta, [], $cabeceras));
    }

    /**
     * @param list<Rol> $roles además de espectador, con el que nace todo usuario
     */
    private function agregar(string $nombre, string $correo, array $roles = [], EstadoCuenta $estado = EstadoCuenta::Activa): string
    {
        $id = Uuid::nuevo();
        $this->usuarios->agregar(new Usuario($id, $nombre, $correo, 'hash', $estado, [Rol::Espectador, ...$roles]));

        return $id;
    }

    private function cargarUsuarios(): void
    {
        $this->agregar('Ana Pérez', 'ana@ejemplo.com', [Rol::Administrador]);
        $this->agregar('José Gómez', 'jgomez@correo.org', [Rol::Organizador]);
        $this->agregar('Marta Gil', 'marta_gil@ejemplo.com', estado: EstadoCuenta::Suspendida);
        $this->agregar('Pedro Vidal', 'pvidal@ejemplo.com', estado: EstadoCuenta::Pendiente);
        $this->agregar('Zoe 100% Cine', 'zoe@correo.org', [Rol::Moderador]);
    }

    /**
     * Los nombres de las filas de la tabla, en orden.
     *
     * @return list<string>
     */
    private static function nombres(Respuesta $respuesta): array
    {
        preg_match_all('#<span class="tabla-admin-principal">([^<]*)</span>#', $respuesta->cuerpo(), $nombres);

        return array_map(static fn (string $nombre): string => html_entity_decode($nombre, ENT_QUOTES | ENT_HTML5), $nombres[1]);
    }

    public function testElAdministradorVeTodosLosUsuariosPorNombre(): void
    {
        $this->cargarUsuarios();

        $respuesta = $this->pedir();
        $cuerpo = $respuesta->cuerpo();

        self::assertSame(200, $respuesta->estado(), $cuerpo);
        self::assertSame(['Ana Pérez', 'José Gómez', 'Marta Gil', 'Pedro Vidal', 'Zoe 100% Cine'], self::nombres($respuesta));
        self::assertStringContainsString('5 usuarios', $cuerpo);
        self::assertStringContainsString('action="/cuenta/administracion/usuarios"', $cuerpo);
        // Sin búsqueda no hay «Limpiar», ni paginado con una sola página.
        self::assertStringNotContainsString('Limpiar', $cuerpo);
        self::assertStringNotContainsString('class="paginado-numerado"', $cuerpo);
    }

    public function testCadaFilaMuestraElCorreoElMayorRolYElEstado(): void
    {
        $id = $this->agregar('José Gómez', 'jgomez@correo.org', [Rol::Organizador]);
        $this->agregar('Marta Gil', 'marta@ejemplo.com', estado: EstadoCuenta::Suspendida);

        $cuerpo = $this->pedir()->cuerpo();

        preg_match("#<tr data-usuario=\"{$id}\">.*?</tr>#s", $cuerpo, $fila);
        self::assertNotEmpty($fila, $cuerpo);
        self::assertStringContainsString('jgomez@correo.org', $fila[0]);
        // Un solo rol, el mayor, como el wireframe: el organizador también es espectador.
        preg_match_all('#class="usuario-rol rol-([a-z]+)"#', $fila[0], $roles);
        self::assertSame(['organizador'], $roles[1]);
        self::assertStringContainsString('estado-cuenta-activa', $fila[0]);
        // La inicial en el avatar.
        self::assertStringContainsString('<span class="tabla-admin-avatar" aria-hidden="true">J</span>', $fila[0]);
        self::assertStringContainsString('estado-cuenta-suspendida', $cuerpo);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function busquedas(): iterable
    {
        yield 'parte del nombre' => ['Gil', ['Marta Gil']];
        yield 'sin mayúsculas ni tildes' => ['jose gomez', ['José Gómez']];
        yield 'con tildes' => ['PÉREZ', ['Ana Pérez']];
        yield 'parte del correo' => ['correo.org', ['José Gómez', 'Zoe 100% Cine']];
        yield 'el correo sin mayúsculas' => ['PVIDAL@', ['Pedro Vidal']];
        yield 'con espacios alrededor' => ['  marta  ', ['Marta Gil']];
        // Los comodines de LIKE se buscan como texto.
        yield 'un %' => ['100%', ['Zoe 100% Cine']];
        yield 'un _' => ['_', ['Marta Gil']];
        yield 'sin resultados' => ['Nueve reinas', []];
    }

    /**
     * @param list<string> $esperados
     */
    #[DataProvider('busquedas')]
    public function testBuscaPorNombreOCorreo(string $texto, array $esperados): void
    {
        $this->cargarUsuarios();

        $respuesta = $this->pedir(self::URL . '?q=' . rawurlencode($texto));

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame($esperados, self::nombres($respuesta));
        // El buscador conserva lo buscado y ofrece volver al listado entero.
        self::assertStringContainsString('value="' . htmlspecialchars(trim($texto)) . '"', $respuesta->cuerpo());
        self::assertStringContainsString('href="/cuenta/administracion/usuarios"', $respuesta->cuerpo());
        if ($esperados === []) {
            self::assertStringContainsString('No hay usuarios que coincidan con la búsqueda.', $respuesta->cuerpo());
        }
    }

    public function testUnTextoConTildesNoRompeLaBusquedaEnElCorreo(): void
    {
        // El correo es ASCII: con «ñ» se busca sólo en el nombre.
        $this->agregar('Ñandú Films', 'nandu@ejemplo.com');
        $this->agregar('Otra Sala', 'otra@ejemplo.com');

        $respuesta = $this->pedir(self::URL . '?q=' . rawurlencode('ñandú'));

        self::assertSame(['Ñandú Films'], self::nombres($respuesta));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function busquedasInvalidas(): iterable
    {
        yield 'un arreglo' => ['q[]=ana'];
        yield 'muy larga' => ['q=' . str_repeat('a', 255)];
        yield 'no es UTF-8' => ['q=%FF'];
    }

    #[DataProvider('busquedasInvalidas')]
    public function testUnaBusquedaInvalidaListaATodosYAvisa(string $consulta): void
    {
        $this->cargarUsuarios();

        $respuesta = $this->pedir(self::URL . "?{$consulta}");

        self::assertSame(200, $respuesta->estado());
        self::assertCount(5, self::nombres($respuesta));
        self::assertStringContainsString('Algunos filtros no se aplicaron', $respuesta->cuerpo());
    }

    public function testElNombreSeMuestraEscapado(): void
    {
        $this->agregar('<script>alert(1)</script>', 'x@ejemplo.com');

        $cuerpo = $this->pedir()->cuerpo();

        self::assertStringNotContainsString('<script>alert(1)</script>', $cuerpo);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $cuerpo);
    }

    public function testSinUsuariosLoDice(): void
    {
        $respuesta = $this->pedir();

        self::assertSame(200, $respuesta->estado());
        self::assertStringContainsString('Todavía no hay usuarios registrados.', $respuesta->cuerpo());
    }

    public function testPaginaConservandoLosFiltros(): void
    {
        $this->cargarUsuarios();

        // De ejemplo.com, sólo espectadores: Marta Gil y Pedro Vidal.
        $respuesta = $this->pedir(self::URL . '?q=ejemplo.com&rol=espectador&pagina=2', usuarios: ['por_pagina' => 1]);
        $cuerpo = $respuesta->cuerpo();

        self::assertSame(['Pedro Vidal'], self::nombres($respuesta));
        self::assertStringContainsString('2 usuarios', $cuerpo);
        // Los números, con los filtros; la primera va sin el número, como la arma el formulario.
        self::assertSame(
            [
                '/cuenta/administracion/usuarios?q=ejemplo.com&amp;rol=espectador',
                '/cuenta/administracion/usuarios?q=ejemplo.com&amp;rol=espectador&amp;pagina=2',
            ],
            self::paginas($cuerpo),
        );
        self::assertMatchesRegularExpression('#class="paginado-numero actual" aria-current="page">2<#', $cuerpo);
    }

    public function testElPaginadoNumeraLaPrimeraLaUltimaYLasDeAlrededor(): void
    {
        foreach (range(1, 10) as $numero) {
            $this->agregar(sprintf('Usuario %02d', $numero), "u{$numero}@ejemplo.com");
        }

        $enLaPrimera = $this->pedir(usuarios: ['por_pagina' => 1])->cuerpo();
        $enLaSexta = $this->pedir(self::URL . '?pagina=6', usuarios: ['por_pagina' => 1])->cuerpo();

        self::assertSame(['1', '2', '3', '4', '5', '…', '10'], self::numerosDelPaginado($enLaPrimera));
        self::assertSame(['1', '…', '4', '5', '6', '7', '8', '…', '10'], self::numerosDelPaginado($enLaSexta));
    }

    /**
     * Los href del paginado, en orden.
     *
     * @return list<string>
     */
    private static function paginas(string $cuerpo): array
    {
        preg_match_all('#<a href="([^"]*)" class="paginado-numero#', $cuerpo, $enlaces);

        return $enlaces[1];
    }

    /**
     * Lo que se lee en el paginado: los números y los «…».
     *
     * @return list<string>
     */
    private static function numerosDelPaginado(string $cuerpo): array
    {
        preg_match('#<nav class="paginado-numerado".*?</nav>#s', $cuerpo, $paginado);
        preg_match_all('#>(\d+|…)<#u', $paginado[0] ?? '', $numeros);

        return $numeros[1];
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function filtros(): iterable
    {
        // El rol que se filtra es el mayor: espectador trae a los que son sólo eso.
        yield 'espectadores' => ['rol=espectador', ['Marta Gil', 'Pedro Vidal']];
        yield 'organizadores' => ['rol=organizador', ['José Gómez']];
        yield 'moderadores' => ['rol=moderador', ['Zoe 100% Cine']];
        yield 'administradores' => ['rol=administrador', ['Ana Pérez']];
        yield 'suspendidas' => ['estado=suspendida', ['Marta Gil']];
        yield 'pendientes' => ['estado=pendiente', ['Pedro Vidal']];
        yield 'con la búsqueda' => ['q=ejemplo&estado=activa', ['Ana Pérez']];
    }

    /**
     * @param list<string> $esperados
     */
    #[DataProvider('filtros')]
    public function testFiltraPorRolYEstado(string $consulta, array $esperados): void
    {
        $this->cargarUsuarios();

        $respuesta = $this->pedir(self::URL . "?{$consulta}");

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame($esperados, self::nombres($respuesta));
        // Los desplegables quedan en lo elegido.
        parse_str($consulta, $valores);
        foreach (['rol', 'estado'] as $filtro) {
            if (isset($valores[$filtro])) {
                self::assertStringContainsString("<option value=\"{$valores[$filtro]}\" selected>", $respuesta->cuerpo());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function filtrosInvalidos(): iterable
    {
        yield 'un rol que no existe' => ['rol=director'];
        yield 'visitante no es un rol de una cuenta' => ['rol=visitante'];
        yield 'un estado que no existe' => ['estado=borrada'];
        yield 'un arreglo' => ['estado[]=activa'];
    }

    #[DataProvider('filtrosInvalidos')]
    public function testUnFiltroInvalidoSeDejaAfueraYAvisa(string $consulta): void
    {
        $this->cargarUsuarios();

        $respuesta = $this->pedir(self::URL . "?{$consulta}");

        self::assertSame(200, $respuesta->estado());
        self::assertCount(5, self::nombres($respuesta));
        self::assertStringContainsString('Algunos filtros no se aplicaron', $respuesta->cuerpo());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function paginasQueNoExisten(): iterable
    {
        yield 'después de la última' => ['pagina=2'];
        yield 'cero' => ['pagina=0'];
        yield 'no es un número' => ['pagina=abc'];
        yield 'negativa' => ['pagina=-1'];
        yield 'enorme' => ['pagina=99999999999999999999'];
    }

    #[DataProvider('paginasQueNoExisten')]
    public function testUnaPaginaQueNoExisteDa404(string $consulta): void
    {
        $this->cargarUsuarios();

        self::assertSame(404, $this->pedir(self::URL . "?{$consulta}")->estado());
    }

    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function otrosRoles(): iterable
    {
        yield 'sin sesión' => [null, 401];
        yield 'espectador' => ['espectador', 403];
        yield 'organizador' => ['organizador', 403];
        yield 'moderador' => ['moderador', 403];
    }

    #[DataProvider('otrosRoles')]
    public function testSoloEntraElAdministrador(?string $rol, int $estado): void
    {
        $this->cargarUsuarios();

        $respuesta = $this->pedir(self::URL . '?q=ana', $rol);

        self::assertSame($estado, $respuesta->estado());
        self::assertStringNotContainsString('ana@ejemplo.com', $respuesta->cuerpo());
    }
}
