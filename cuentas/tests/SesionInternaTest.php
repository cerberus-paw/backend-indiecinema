<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Cuentas\Modelo\Usuario;
use IndieCinema\Cuentas\Repositorios\RepositorioDeSesiones;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Cuentas\Servicios\ServicioDeSesiones;
use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Seguridad\Rol;
use PHPUnit\Framework\TestCase;

/**
 * GET /interno/sesion de punta a punta, como la llama el auth_request de nginx: con su token y la
 * cookie del pedido original, contra un MySQL de verdad.
 */
final class SesionInternaTest extends TestCase
{
    use ConBaseDePruebas;

    private const TOKEN_DE_NGINX = 'token-de-nginx';
    private const TOKEN_DE_PROGRAMACION = 'token-de-programacion';

    private Configuracion $configuracion;
    private BaseDeDatos $base;
    private RepositorioDeUsuarios $usuarios;
    private ServicioDeSesiones $sesiones;
    private string $mariana;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'cuentas', 'prefijo' => '/cuenta', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'llamadores' => [
                'nginx' => hash('sha256', self::TOKEN_DE_NGINX),
                'programacion' => hash('sha256', self::TOKEN_DE_PROGRAMACION),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
        $this->usuarios = new RepositorioDeUsuarios($this->base);
        $this->sesiones = new ServicioDeSesiones(new RepositorioDeSesiones($this->base));
        $usuario = Usuario::nuevo('Mariana Rossi Núñez', 'mariana@ejemplo.com', password_hash('butaca 2026', PASSWORD_ARGON2ID));
        $this->usuarios->agregar($usuario);
        $this->mariana = $usuario->id;
    }

    private function pedir(?string $cookie, string $llamador = 'nginx', string $token = self::TOKEN_DE_NGINX): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('cuentas', fopen('php://memory', 'w+')));

        return $aplicacion->atender(new Peticion(
            'GET',
            '/interno/sesion',
            [],
            [],
            ['X-Llamador' => $llamador, 'Authorization' => "Bearer {$token}"],
            $cookie === null ? [] : ['sesion' => $cookie],
        ));
    }

    /**
     * @return array{string, string, string} id, rol y nombre
     */
    private static function identidad(Respuesta $respuesta): array
    {
        return [
            (string) $respuesta->cabecera('X-Usuario-Id'),
            (string) $respuesta->cabecera('X-Rol'),
            (string) $respuesta->cabecera('X-Usuario-Nombre'),
        ];
    }

    public function testConUnaCookieValidaDevuelveElUsuarioYElRol(): void
    {
        $identificador = $this->sesiones->abrir($this->mariana)->identificador;

        $respuesta = $this->pedir($identificador);

        self::assertSame(200, $respuesta->estado());
        self::assertSame([$this->mariana, 'espectador', 'Mariana%20Rossi%20N%C3%BA%C3%B1ez'], self::identidad($respuesta));
        self::assertSame('Mariana Rossi Núñez', rawurldecode(self::identidad($respuesta)[2]));
    }

    public function testConVariosRolesMandaElMasAlto(): void
    {
        $this->usuarios->agregarRol($this->mariana, Rol::Organizador);
        $identificador = $this->sesiones->abrir($this->mariana)->identificador;

        self::assertSame('organizador', self::identidad($this->pedir($identificador))[1]);
    }

    public function testSinCookieDevuelveLasCabecerasVacias(): void
    {
        $respuesta = $this->pedir(null);

        self::assertSame(200, $respuesta->estado());
        self::assertSame(['', '', ''], self::identidad($respuesta));
    }

    public function testConUnaCookieQueNoEsDeNingunaSesionTambienVacias(): void
    {
        $this->sesiones->abrir($this->mariana);

        self::assertSame(['', '', ''], self::identidad($this->pedir('cualquier cosa')));
        self::assertSame(['', '', ''], self::identidad($this->pedir(str_repeat('a', 64))));
    }

    public function testUnaSesionVencidaOBorradaDejaDeServirEnElPedidoSiguiente(): void
    {
        $vencida = $this->sesiones->abrir($this->mariana)->identificador;
        $borrada = $this->sesiones->abrir($this->mariana)->identificador;
        $this->base->ejecutar('UPDATE sesion SET vence_en = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE huella = ?', [hash('sha256', $vencida)]);
        $this->base->ejecutar('DELETE FROM sesion WHERE huella = ?', [hash('sha256', $borrada)]);

        self::assertSame(['', '', ''], self::identidad($this->pedir($vencida)));
        self::assertSame(['', '', ''], self::identidad($this->pedir($borrada)));
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM sesion'));
    }

    public function testUnaCuentaQueNoEstaActivaNoTieneSesion(): void
    {
        $identificador = $this->sesiones->abrir($this->mariana)->identificador;
        // Sin pasar por el repositorio, que también borraría las sesiones.
        $this->base->ejecutar("UPDATE usuario SET estado = 'suspendida' WHERE id = ?", [$this->mariana]);

        self::assertSame(['', '', ''], self::identidad($this->pedir($identificador)));
    }

    public function testElUsoSeAnotaComoMuchoCadaCincoMinutos(): void
    {
        $identificador = $this->sesiones->abrir($this->mariana)->identificador;
        $this->base->ejecutar('UPDATE sesion SET usada_en = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        $antes = $this->base->valor('SELECT usada_en FROM sesion');

        $this->pedir($identificador);
        self::assertSame($antes, $this->base->valor('SELECT usada_en FROM sesion'));

        $this->base->ejecutar('UPDATE sesion SET usada_en = UTC_TIMESTAMP() - INTERVAL 6 MINUTE');
        $this->pedir($identificador);
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM sesion WHERE usada_en >= UTC_TIMESTAMP() - INTERVAL 1 MINUTE'));
    }

    public function testSoloLaPuedeLlamarNginxConSuToken(): void
    {
        $identificador = $this->sesiones->abrir($this->mariana)->identificador;

        self::assertSame(403, $this->pedir($identificador, token: 'otro-token')->estado());
        // Programación tiene un token válido, pero la ruta no la acepta como llamador.
        $respuesta = $this->pedir($identificador, 'programacion', self::TOKEN_DE_PROGRAMACION);
        self::assertSame(403, $respuesta->estado());
        self::assertNull($respuesta->cabecera('X-Usuario-Id'));
    }
}
