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
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private RepositorioDeUsuarios $usuarios;

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        $this->configuracion = $this->configuracion();
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->usuarios = new RepositorioDeUsuarios($this->base);
        $this->salidaDelLog = fopen('php://memory', 'w+');
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
    private function pedir(string $url = self::URL, ?string $rol = 'administrador', array $usuarios = [], string $metodo = 'GET', array $cuerpo = []): Respuesta
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $consulta);
        $cabeceras = $rol === null ? [] : ['X-Nginx-Secreto' => self::SECRETO, 'X-Usuario-Id' => self::ADMINISTRADOR, 'X-Rol' => $rol];
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion($usuarios), new Log('cuentas', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion($metodo, (string) parse_url($url, PHP_URL_PATH), $consulta, $cuerpo, $cabeceras, ['csrf' => self::CSRF]));
    }

    /**
     * Un POST del panel, con el token y, si vienen, los campos del formulario.
     *
     * @param array<string, mixed> $campos
     */
    private function enviar(string $url, array $campos = [], ?string $rol = 'administrador'): Respuesta
    {
        return $this->pedir($url, $rol, metodo: 'POST', cuerpo: $campos + ['_csrf' => self::CSRF]);
    }

    /**
     * «Guardar cambios»: el rol elegido en cada desplegable y, si viene, el que tenía al mostrarse.
     *
     * @param array<string, mixed>  $roles   id => el rol elegido
     * @param array<string, string> $antes   id => el rol con el que se mostró
     * @param array<string, string> $filtros los campos ocultos de la búsqueda
     */
    private function guardar(array $roles, array $antes = [], array $filtros = []): Respuesta
    {
        return $this->enviar(self::URL . '/roles', ['roles' => $roles, 'antes' => $antes] + $filtros);
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    /**
     * @return list<string>
     */
    private function roles(string $id): array
    {
        return array_column($this->base->filas('SELECT tipo FROM rol WHERE usuario_id = ? ORDER BY tipo', [$id]), 'tipo');
    }

    private function abrirSesion(string $id): void
    {
        $this->base->ejecutar(
            'INSERT INTO sesion (huella, usuario_id, creada_en, usada_en, vence_en) VALUES (?, ?, NOW(), NOW(), NOW() + INTERVAL 1 DAY)',
            [hash('sha256', random_bytes(16)), $id],
        );
    }

    private function sesiones(string $id): int
    {
        return (int) $this->base->valor('SELECT COUNT(*) FROM sesion WHERE usuario_id = ?', [$id]);
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
        // Un solo rol, el mayor, elegido en el desplegable como el wireframe: el organizador también
        // es espectador.
        preg_match_all('#<option value="([a-z]+)" selected>#', $fila[0], $roles);
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

    public function testFiltraPorElAnioDeAlta(): void
    {
        $this->cargarUsuarios();
        $this->base->ejecutar("UPDATE usuario SET creado_en = '2025-06-01 10:00:00' WHERE nombre = 'José Gómez'");
        $this->base->ejecutar("UPDATE usuario SET creado_en = '2024-12-31 23:59:59' WHERE nombre = 'Marta Gil'");

        $respuesta = $this->pedir(self::URL . '?alta=2025');
        $cuerpo = $respuesta->cuerpo();

        self::assertSame(['José Gómez'], self::nombres($respuesta));
        // El 31 de diciembre a la noche todavía es del año anterior.
        self::assertSame(['Marta Gil'], self::nombres($this->pedir(self::URL . '?alta=2024')));
        // Las opciones son los años con cuentas, del más nuevo al más viejo, y queda el elegido.
        preg_match('#<select name="alta"[^>]*>(.*?)</select>#s', $cuerpo, $desplegable);
        preg_match_all('#<option value="(\d{4})"#', $desplegable[1] ?? '', $anios);
        self::assertSame([(int) date('Y'), 2025, 2024], array_map(intval(...), $anios[1]));
        self::assertStringContainsString('<option value="2025" selected>2025</option>', $desplegable[1] ?? '');
    }

    public function testUnAnioSinCuentasQuedaElegidoYNoTraeANadie(): void
    {
        $this->cargarUsuarios();

        $respuesta = $this->pedir(self::URL . '?alta=2019');

        self::assertSame([], self::nombres($respuesta));
        self::assertStringContainsString('No hay usuarios que coincidan', $respuesta->cuerpo());
        self::assertStringContainsString('<option value="2019" selected>2019</option>', $respuesta->cuerpo());
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
        yield 'un año que no es un número' => ['alta=hola'];
        yield 'un año de antes de 1970' => ['alta=1969'];
        yield 'un año de cinco cifras' => ['alta=20255'];
        yield 'el año como arreglo' => ['alta[]=2025'];
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

    public function testLaTablaEsUnFormularioConElRolDeCadaUnoEnUnDesplegable(): void
    {
        $espectadora = $this->agregar('Eva Espectadora', 'eva@ejemplo.com');
        $organizador = $this->agregar('Omar Organizador', 'omar@ejemplo.com', [Rol::Organizador]);
        $moderadora = $this->agregar('Mora Moderadora', 'mora@ejemplo.com', [Rol::Moderador]);

        $cuerpo = $this->pedir(self::URL . '?q=ejemplo')->cuerpo();

        // Un solo formulario para toda la tabla, con el token y la búsqueda, para volver a ella.
        preg_match('#<form class="tabla-admin-formulario" action="/cuenta/administracion/usuarios/roles" method="POST">.*?</form>#s', $cuerpo, $formulario);
        self::assertNotEmpty($formulario, $cuerpo);
        self::assertSame(1, substr_count($formulario[0], 'name="_csrf" value="' . self::CSRF . '"'));
        self::assertStringContainsString('<input type="hidden" name="q" value="ejemplo">', $formulario[0]);
        self::assertStringContainsString('>Guardar cambios</button>', $formulario[0]);
        self::assertStringContainsString('<button type="reset"', $formulario[0]);
        foreach ([$espectadora => 'espectador', $organizador => 'organizador'] as $id => $rol) {
            preg_match("#<tr data-usuario=\"{$id}\">.*?</tr>#s", $formulario[0], $fila);
            self::assertStringContainsString("<select name=\"roles[{$id}]\"", $fila[0] ?? '');
            self::assertStringContainsString("<option value=\"{$rol}\" selected>", $fila[0] ?? '');
            // El rol con el que se mostró, para cambiar sólo el que se tocó.
            self::assertStringContainsString("<input type=\"hidden\" name=\"antes[{$id}]\" value=\"{$rol}\">", $fila[0] ?? '');
        }
        // El moderador y el administrador no se cambian desde la web: el desplegable, deshabilitado
        // y sin nombre, no se manda.
        preg_match("#<tr data-usuario=\"{$moderadora}\">.*?</tr>#s", $formulario[0], $fila);
        self::assertStringNotContainsString('name="roles[', $fila[0] ?? '');
        self::assertMatchesRegularExpression('#<select[^>]* disabled[^>]*>\s*<option>Moderador</option>#', $fila[0] ?? '');
    }

    public function testLasTarjetasCuentanTodasLasCuentas(): void
    {
        $this->cargarUsuarios();
        $viejo = $this->agregar('Omar Viejo', 'omar@ejemplo.com', [Rol::Organizador]);
        $this->base->ejecutar("UPDATE usuario SET creado_en = '2020-01-15 12:00:00' WHERE id = ?", [$viejo]);
        // Un organizador suspendido no está activo.
        $this->agregar('Sara Suspendida', 'sara@ejemplo.com', [Rol::Organizador], EstadoCuenta::Suspendida);

        // Con una búsqueda, los números siguen siendo de todas las cuentas.
        $cuerpo = $this->pedir(self::URL . '?q=ana')->cuerpo();

        preg_match_all('#<dd class="panel-admin-dato-valor">([^<]*)</dd>\s*<dd class="panel-admin-dato-nota">([^<]*)</dd>#', $cuerpo, $datos);
        self::assertSame(['7', '2'], $datos[1], $cuerpo);
        self::assertSame(['+6 este mes', 'con la cuenta activa'], $datos[2]);
    }

    public function testDarElRolDeOrganizadorCierraSusSesionesYQuedaEnElLog(): void
    {
        $id = $this->agregar('Eva Espectadora', 'eva@ejemplo.com');
        $this->abrirSesion($id);

        $respuesta = $this->guardar([$id => 'organizador'], [$id => 'espectador']);

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame("/cuenta/administracion/usuarios?organizador_dado={$id}", $respuesta->cabecera('Location'));
        self::assertSame(['espectador', 'organizador'], $this->roles($id));
        // Con el rol nuevo tiene que volver a ingresar: así nginx manda el rol al día.
        self::assertSame(0, $this->sesiones($id));
        self::assertStringContainsString(self::ADMINISTRADOR . " le dio el rol de organizador a {$id}.", $this->log());
        self::assertStringContainsString(
            'Le diste el rol de organizador a «Eva Espectadora»',
            $this->pedir(self::URL . "?organizador_dado={$id}")->cuerpo(),
        );
    }

    public function testQuitarElRolDeOrganizadorCierraSusSesionesYQuedaEnElLog(): void
    {
        $id = $this->agregar('Omar Organizador', 'omar@ejemplo.com', [Rol::Organizador]);
        $this->abrirSesion($id);

        $respuesta = $this->guardar([$id => 'espectador'], [$id => 'organizador']);

        self::assertSame(303, $respuesta->estado());
        self::assertSame("/cuenta/administracion/usuarios?organizador_quitado={$id}", $respuesta->cabecera('Location'));
        self::assertSame(['espectador'], $this->roles($id));
        self::assertSame(0, $this->sesiones($id));
        self::assertStringContainsString(self::ADMINISTRADOR . " le quitó el rol de organizador a {$id}.", $this->log());
        self::assertStringContainsString(
            'Le quitaste el rol de organizador a «Omar Organizador»',
            $this->pedir(self::URL . "?organizador_quitado={$id}")->cuerpo(),
        );
    }

    public function testGuardaLosCambiosDeTodaLaTablaJuntos(): void
    {
        $eva = $this->agregar('Eva Espectadora', 'eva@ejemplo.com');
        $omar = $this->agregar('Omar Organizador', 'omar@ejemplo.com', [Rol::Organizador]);
        $igual = $this->agregar('Inés Igual', 'ines@ejemplo.com');
        $this->abrirSesion($igual);

        // Llegan los de toda la página: los dos que cambiaron y el que quedó como estaba.
        $respuesta = $this->guardar(
            [$eva => 'organizador', $omar => 'espectador', $igual => 'espectador'],
            [$eva => 'espectador', $omar => 'organizador', $igual => 'espectador'],
        );

        self::assertSame(303, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame('/cuenta/administracion/usuarios?roles_cambiados=2', $respuesta->cabecera('Location'));
        self::assertSame(['espectador', 'organizador'], $this->roles($eva));
        self::assertSame(['espectador'], $this->roles($omar));
        // Al que no cambió no se le cierra la sesión.
        self::assertSame(1, $this->sesiones($igual));
        self::assertStringContainsString(
            'Cambiaste el rol de 2 usuarios',
            $this->pedir(self::URL . '?roles_cambiados=2')->cuerpo(),
        );
    }

    public function testSoloCambiaElQueElAdministradorToco(): void
    {
        // Cuando se cargó la página Omar era espectador; otro administrador ya lo hizo organizador.
        $omar = $this->agregar('Omar Organizador', 'omar@ejemplo.com', [Rol::Organizador]);
        $eva = $this->agregar('Eva Espectadora', 'eva@ejemplo.com');

        $respuesta = $this->guardar([$omar => 'espectador', $eva => 'organizador'], [$omar => 'espectador', $eva => 'espectador']);

        self::assertSame("/cuenta/administracion/usuarios?organizador_dado={$eva}", $respuesta->cabecera('Location'));
        // El desplegable de Omar no se tocó: no se pisa el cambio del otro administrador.
        self::assertSame(['espectador', 'organizador'], $this->roles($omar));
    }

    public function testVuelveALaMismaBusqueda(): void
    {
        $id = $this->agregar('Eva Espectadora', 'eva@ejemplo.com');

        // Los filtros viajan en campos ocultos del mismo formulario.
        $respuesta = $this->guardar([$id => 'organizador'], [$id => 'espectador'], ['q' => 'eva', 'rol' => 'espectador', 'estado' => 'activa', 'alta' => '2025']);

        self::assertSame(
            "/cuenta/administracion/usuarios?q=eva&rol=espectador&estado=activa&alta=2025&organizador_dado={$id}",
            $respuesta->cabecera('Location'),
        );
    }

    /**
     * @return iterable<string, array{list<Rol>, string, list<string>}>
     */
    public static function cambiosQueNoCambianNada(): iterable
    {
        yield 'organizador a quien ya lo es' => [[Rol::Organizador], 'organizador', ['espectador', 'organizador']];
        yield 'espectador a quien ya lo es' => [[], 'espectador', ['espectador']];
    }

    /**
     * Un navegador sin :has muestra «Guardar cambios» siempre: guardar sin cambiar nada vuelve sin
     * aviso. También sin el rol de antes, que entonces no se puede comparar.
     *
     * @param list<Rol>    $roles
     * @param list<string> $quedan
     */
    #[DataProvider('cambiosQueNoCambianNada')]
    public function testGuardarSinCambiosNoCambiaNadaNiSeRegistra(array $roles, string $rol, array $quedan): void
    {
        $id = $this->agregar('Ana Pérez', 'ana@ejemplo.com', $roles);
        $this->abrirSesion($id);

        foreach ([[$id => $rol], []] as $antes) {
            $respuesta = $this->guardar([$id => $rol], $antes);

            self::assertSame(303, $respuesta->estado());
            self::assertSame('/cuenta/administracion/usuarios', $respuesta->cabecera('Location'));
        }
        self::assertSame($quedan, $this->roles($id));
        self::assertSame(1, $this->sesiones($id));
        self::assertStringNotContainsString('rol de organizador', $this->log());
    }

    /**
     * @return iterable<string, array{list<Rol>, mixed}>
     */
    public static function cambiosQueNoSePuedenHacer(): iterable
    {
        // Esos roles no se cambian desde la web, ni para sumarles el de organizador.
        yield 'un administrador a organizador' => [[Rol::Administrador], 'organizador'];
        yield 'un administrador a espectador' => [[Rol::Administrador], 'espectador'];
        yield 'un moderador a espectador' => [[Rol::Moderador], 'espectador'];
        // Desde la web no se da otro rol que el de organizador.
        yield 'un espectador a administrador' => [[], 'administrador'];
        yield 'un espectador a moderador' => [[], 'moderador'];
        yield 'un espectador a visitante' => [[], 'visitante'];
        yield 'un rol que no existe' => [[], 'productor'];
        yield 'el rol como arreglo' => [[], ['organizador']];
    }

    /**
     * @param list<Rol> $roles
     */
    #[DataProvider('cambiosQueNoSePuedenHacer')]
    public function testConUnCambioQueNoSePuedeHacerNoSeGuardaNinguno(array $roles, mixed $rol): void
    {
        $id = $this->agregar('Ana Pérez', 'ana@ejemplo.com', $roles);
        $eva = $this->agregar('Eva Espectadora', 'eva@ejemplo.com');
        $antes = $this->roles($id);
        $this->abrirSesion($eva);

        // El de Eva sí se puede, y va primero: tampoco se guarda.
        self::assertSame(400, $this->guardar([$eva => 'organizador', $id => $rol])->estado());

        self::assertSame($antes, $this->roles($id));
        self::assertSame(['espectador'], $this->roles($eva));
        self::assertSame(1, $this->sesiones($eva));
    }

    public function testSinLosRolesDa400(): void
    {
        self::assertSame(400, $this->enviar(self::URL . '/roles')->estado());
        self::assertSame(400, $this->enviar(self::URL . '/roles', ['roles' => 'organizador'])->estado());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function usuariosQueNoExisten(): iterable
    {
        yield 'un id que no está' => [Uuid::nuevo()];
        yield 'no es un id' => ['abc'];
    }

    #[DataProvider('usuariosQueNoExisten')]
    public function testConUnUsuarioQueNoExisteDa404YNoSeGuardaNinguno(string $id): void
    {
        $eva = $this->agregar('Eva Espectadora', 'eva@ejemplo.com');

        self::assertSame(404, $this->guardar([$eva => 'organizador', $id => 'organizador'])->estado());

        self::assertSame(['espectador'], $this->roles($eva));
    }

    public function testSinElTokenCsrfNoCambiaElRol(): void
    {
        $id = $this->agregar('Eva Espectadora', 'eva@ejemplo.com');

        $respuesta = $this->pedir(self::URL . '/roles', metodo: 'POST', cuerpo: ['roles' => [$id => 'organizador'], '_csrf' => str_repeat('d', 64)]);

        self::assertSame(403, $respuesta->estado());
        self::assertSame(['espectador'], $this->roles($id));
    }

    #[DataProvider('otrosRoles')]
    public function testSoloElAdministradorCambiaRoles(?string $rol, int $estado): void
    {
        $espectadora = $this->agregar('Eva Espectadora', 'eva@ejemplo.com');
        $organizador = $this->agregar('Omar Organizador', 'omar@ejemplo.com', [Rol::Organizador]);

        self::assertSame($estado, $this->enviar(self::URL . '/roles', ['roles' => [$espectadora => 'organizador', $organizador => 'espectador']], $rol)->estado());

        self::assertSame(['espectador'], $this->roles($espectadora));
        self::assertSame(['espectador', 'organizador'], $this->roles($organizador));
    }
}
