<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

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
 * GET /interno/usuarios/{id} y POST /interno/usuarios/{id}/roles de punta a punta, como los llama
 * la bandeja de solicitudes de sala de programación: con su token, contra un MySQL de verdad. El
 * JSON es el que espera UsuariosDeCuentas en programación (su prueba usa una cuentas de mentira
 * que responde lo mismo).
 */
final class UsuariosInternosTest extends TestCase
{
    use ConBaseDePruebas;

    private const TOKEN_DE_PROGRAMACION = 'token-de-programacion';
    private const TOKEN_DE_FUNCIONES = 'token-de-funciones';
    private const TOKEN_DE_MODERACION = 'token-de-moderacion';
    private const TOKEN_DE_RECAUDACION = 'token-de-recaudacion';
    private const ADMINISTRADOR = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private RepositorioDeUsuarios $usuarios;
    private string $eva;

    /** @var resource */
    private $salidaDelLog;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'llamadores' => [
                'programacion' => hash('sha256', self::TOKEN_DE_PROGRAMACION),
                'funciones' => hash('sha256', self::TOKEN_DE_FUNCIONES),
                'moderacion' => hash('sha256', self::TOKEN_DE_MODERACION),
                'recaudacion' => hash('sha256', self::TOKEN_DE_RECAUDACION),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->usuarios = new RepositorioDeUsuarios($this->base);
        $usuario = Usuario::nuevo('Eva Núñez', 'eva@ejemplo.com', 'sin uso');
        $this->usuarios->agregar($usuario);
        $this->eva = $usuario->id;
        $this->base->ejecutar("UPDATE usuario SET creado_en = '2025-03-08 15:00:00' WHERE id = ?", [$this->eva]);
        $this->salidaDelLog = fopen('php://memory', 'w+');
    }

    /**
     * @param array<string, mixed> $cuerpo   el JSON, ya decodificado
     * @param array<string, mixed> $consulta los parámetros de la URL
     */
    private function pedir(string $metodo, string $ruta, array $cuerpo = [], string $llamador = 'programacion', string $token = self::TOKEN_DE_PROGRAMACION, array $consulta = []): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('cuentas', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion(
            $metodo,
            $ruta,
            $consulta,
            $cuerpo,
            ['X-Llamador' => $llamador, 'Authorization' => "Bearer {$token}", 'Accept' => 'application/json'],
            [],
        ));
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function darRol(string $id, array $cuerpo = ['rol' => 'organizador', 'otorgado_por' => self::ADMINISTRADOR]): Respuesta
    {
        return $this->pedir('POST', "/interno/usuarios/{$id}/roles", $cuerpo);
    }

    /**
     * @return list<string>
     */
    private function roles(): array
    {
        return array_map(strval(...), array_column($this->base->filas('SELECT tipo FROM rol WHERE usuario_id = ? ORDER BY tipo', [$this->eva]), 'tipo'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Respuesta $respuesta): array
    {
        return (array) json_decode($respuesta->cuerpo(), true);
    }

    private function log(): string
    {
        rewind($this->salidaDelLog);

        return (string) stream_get_contents($this->salidaDelLog);
    }

    public function testDevuelveQuienEsSinLaContrasena(): void
    {
        $respuesta = $this->pedir('GET', "/interno/usuarios/{$this->eva}");

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame([
            'id' => $this->eva,
            'nombre' => 'Eva Núñez',
            'correo' => 'eva@ejemplo.com',
            'estado' => 'activa',
            'roles' => ['espectador'],
            'creado_en' => '2025-03-08T15:00:00',
        ], self::json($respuesta));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function idsQueNoExisten(): iterable
    {
        yield 'un id al azar' => ['0f0f0f0f-0f0f-4f0f-8f0f-0f0f0f0f0f0f'];
        yield 'un id que no es UUID' => ['1'];
    }

    #[DataProvider('idsQueNoExisten')]
    public function testUnUsuarioQueNoExisteDa404(string $id): void
    {
        self::assertSame(404, $this->pedir('GET', "/interno/usuarios/{$id}")->estado());
        self::assertSame(404, $this->darRol($id)->estado());
    }

    public function testDaElRolDeOrganizadorYQuedaEnElLog(): void
    {
        $respuesta = $this->darRol($this->eva);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        self::assertSame(['espectador', 'organizador'], self::json($respuesta)['roles'] ?? null);
        self::assertSame(['espectador', 'organizador'], $this->roles());
        self::assertStringContainsString(self::ADMINISTRADOR . " le dio el rol de organizador a {$this->eva}.", $this->log());
    }

    public function testSiYaLoTieneNoCambiaNadaYSePuedeReintentar(): void
    {
        $this->darRol($this->eva);
        $otraVez = $this->darRol($this->eva);

        self::assertSame(200, $otraVez->estado());
        self::assertSame(['espectador', 'organizador'], $this->roles());
        self::assertSame(1, substr_count($this->log(), 'le dio el rol de organizador'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function pedidosInvalidos(): iterable
    {
        yield 'el rol de administrador' => [['rol' => 'administrador', 'otorgado_por' => self::ADMINISTRADOR]];
        yield 'el de moderador' => [['rol' => 'moderador', 'otorgado_por' => self::ADMINISTRADOR]];
        yield 'sin rol' => [['otorgado_por' => self::ADMINISTRADOR]];
        yield 'sin quién lo da' => [['rol' => 'organizador']];
        yield 'quién lo da no es un id' => [['rol' => 'organizador', 'otorgado_por' => 'Ana']];
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    #[DataProvider('pedidosInvalidos')]
    public function testSoloDaElRolDeOrganizadorYConQuienLoDa(array $cuerpo): void
    {
        self::assertSame(400, $this->darRol($this->eva, $cuerpo)->estado());
        self::assertSame(['espectador'], $this->roles());
    }

    public function testSoloLasLlamaProgramacionConSuToken(): void
    {
        // Funciones existe y tiene su token: lee usuarios (para avisarles por correo), pero no da roles.
        self::assertSame(200, $this->pedir('GET', "/interno/usuarios/{$this->eva}", llamador: 'funciones', token: self::TOKEN_DE_FUNCIONES)->estado());
        self::assertSame(403, $this->pedir('GET', "/interno/usuarios/{$this->eva}", llamador: 'funciones', token: 'otro-token')->estado());
        self::assertSame(403, $this->pedir('POST', "/interno/usuarios/{$this->eva}/roles", ['rol' => 'organizador', 'otorgado_por' => self::ADMINISTRADOR], 'funciones', self::TOKEN_DE_FUNCIONES)->estado());
        // Moderación lee el nombre y el correo de quien manda una solicitud (EF-01.5), pero tampoco da roles.
        self::assertSame(200, $this->pedir('GET', "/interno/usuarios/{$this->eva}", llamador: 'moderacion', token: self::TOKEN_DE_MODERACION)->estado());
        self::assertSame(403, $this->pedir('POST', "/interno/usuarios/{$this->eva}/roles", ['rol' => 'organizador', 'otorgado_por' => self::ADMINISTRADOR], 'moderacion', self::TOKEN_DE_MODERACION)->estado());
        self::assertSame(403, $this->pedir('GET', '/interno/usuarios', llamador: 'moderacion', token: self::TOKEN_DE_MODERACION, consulta: ['ids' => $this->eva])->estado());
        // Recaudación pide los nombres de una lista, para las liquidaciones (EF-02.4), y nada más.
        self::assertSame(200, $this->pedir('GET', '/interno/usuarios', llamador: 'recaudacion', token: self::TOKEN_DE_RECAUDACION, consulta: ['ids' => $this->eva])->estado());
        self::assertSame(403, $this->pedir('GET', "/interno/usuarios/{$this->eva}", llamador: 'recaudacion', token: self::TOKEN_DE_RECAUDACION)->estado());
        self::assertSame(403, $this->pedir('POST', "/interno/usuarios/{$this->eva}/roles", ['rol' => 'organizador', 'otorgado_por' => self::ADMINISTRADOR], 'recaudacion', self::TOKEN_DE_RECAUDACION)->estado());
        // Programación con un token que no es el suyo.
        self::assertSame(403, $this->pedir('GET', "/interno/usuarios/{$this->eva}", token: 'otro-token')->estado());
        self::assertSame(['espectador'], $this->roles());
    }

    public function testElRolDadoSeVeConElRolMayor(): void
    {
        $this->usuarios->agregarRol($this->eva, Rol::Administrador);

        $respuesta = $this->darRol($this->eva);

        // Un rol mayor ya incluye el de organizador: no se agrega.
        self::assertSame(['espectador', 'administrador'], $this->roles());
        self::assertSame(200, $respuesta->estado());
    }

    // ---- Varios usuarios en lote (E4-17.2 / IC-464) -----------------------------------------

    public function testProgramacionYFuncionesPuedenPedirVariosUsuariosConSuToken(): void
    {
        $elias = Usuario::nuevo('Elías Gómez', 'elias@ejemplo.com', 'sin uso');
        $this->usuarios->agregar($elias);

        $ids = "{$this->eva},{$elias->id}";

        foreach ([
            ['programacion', self::TOKEN_DE_PROGRAMACION],
            ['funciones', self::TOKEN_DE_FUNCIONES],
        ] as [$llamador, $token]) {
            $respuesta = $this->pedir('GET', '/interno/usuarios', llamador: $llamador, token: $token, consulta: ['ids' => $ids]);
            self::assertSame(200, $respuesta->estado());

            $datos = self::json($respuesta);
            self::assertArrayHasKey($this->eva, $datos);
            self::assertArrayHasKey($elias->id, $datos);

            // Sólo el nombre: el correo y los roles no salen de cuentas.
            self::assertSame(['id' => $this->eva, 'nombre' => 'Eva Núñez'], $datos[$this->eva]);
            self::assertSame(['id' => $elias->id, 'nombre' => 'Elías Gómez'], $datos[$elias->id]);
        }
    }

    public function testVariosUsuariosIgnoraIdsInexistentesOQueNoSonUuid(): void
    {
        $inexistente = Uuid::nuevo();
        $respuesta = $this->pedir('GET', '/interno/usuarios', consulta: ['ids' => " {$this->eva} , , {$inexistente}, no-es-uuid "]);

        self::assertSame(200, $respuesta->estado());
        $datos = self::json($respuesta);
        self::assertSame([$this->eva], array_keys($datos));
    }

    public function testVariosUsuariosSinIdsDevuelveObjetoVacio(): void
    {
        $vacio = $this->pedir('GET', '/interno/usuarios', consulta: ['ids' => '']);
        self::assertSame(200, $vacio->estado());
        self::assertSame('{}', $vacio->cuerpo());

        $sinParametro = $this->pedir('GET', '/interno/usuarios');
        self::assertSame(200, $sinParametro->estado());
        self::assertSame('{}', $sinParametro->cuerpo());
    }

    public function testVariosUsuariosConMasDe100IdsDevuelve400(): void
    {
        $ids = implode(',', array_map(static fn (): string => Uuid::nuevo(), range(1, 101)));
        $respuesta = $this->pedir('GET', '/interno/usuarios', consulta: ['ids' => $ids]);

        self::assertSame(400, $respuesta->estado());
        self::assertSame(['error' => 'Se piden hasta 100 usuarios por vez.'], self::json($respuesta));
    }

    public function testVariosUsuariosSinTokenOConTokenInvalidoDevuelve403(): void
    {
        self::assertSame(403, $this->pedir('GET', '/interno/usuarios', token: 'token-invalido', consulta: ['ids' => $this->eva])->estado());
        self::assertSame(403, $this->pedir('GET', '/interno/usuarios', llamador: 'desconocido', consulta: ['ids' => $this->eva])->estado());
    }
}
