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
     * @param array<string, mixed> $cuerpo el JSON, ya decodificado
     */
    private function pedir(string $metodo, string $ruta, array $cuerpo = [], string $llamador = 'programacion', string $token = self::TOKEN_DE_PROGRAMACION): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('cuentas', $this->salidaDelLog));

        return $aplicacion->atender(new Peticion(
            $metodo,
            $ruta,
            [],
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
        // Funciones existe y tiene su token, pero estas rutas no lo aceptan.
        self::assertSame(403, $this->pedir('GET', "/interno/usuarios/{$this->eva}", llamador: 'funciones', token: self::TOKEN_DE_FUNCIONES)->estado());
        self::assertSame(403, $this->pedir('POST', "/interno/usuarios/{$this->eva}/roles", ['rol' => 'organizador', 'otorgado_por' => self::ADMINISTRADOR], 'funciones', self::TOKEN_DE_FUNCIONES)->estado());
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
}
