<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * La ocupación de las funciones para programación (E4-02.1) de punta a punta: la aplicación entera
 * con las rutas de funciones, la autorización por token de la API interna y un MySQL de verdad.
 */
final class OcupacionInternaTest extends TestCase
{
    use ConBaseDePruebas;

    private const TOKEN_DE_PROGRAMACION = 'token-de-programacion';
    private const TOKEN_DE_CUENTAS = 'token-de-cuentas';
    private const FUNCION = '12121212-1212-4212-8212-121212121212';
    private const OTRA = '34343434-3434-4434-8434-343434343434';
    private const SIN_RESERVAS = '56565656-5656-4656-8656-565656565656';

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'llamadores' => [
                'programacion' => hash('sha256', self::TOKEN_DE_PROGRAMACION),
                'cuentas' => hash('sha256', self::TOKEN_DE_CUENTAS),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);

        // Dos confirmadas y una con asistencia ocupan lugares; la cancelada no.
        $this->reserva(self::FUNCION, 2, 'confirmada');
        $this->reserva(self::FUNCION, 3, 'asistio');
        $this->reserva(self::FUNCION, 4, 'cancelada');
        $this->reserva(self::OTRA, 1, 'confirmada');
    }

    private function reserva(string $funcion, int $cantidad, string $estado): void
    {
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [Uuid::nuevo(), $funcion, Uuid::nuevo(), strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), $cantidad, $cantidad * 3500, $estado],
        );
    }

    /**
     * @param array<string, string> $consulta
     */
    private function pedir(string $ruta, array $consulta = [], string $llamador = 'programacion', string $token = self::TOKEN_DE_PROGRAMACION): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', $ruta, $consulta, [], [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ], [], []));
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Respuesta $respuesta): array
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());

        return json_decode($respuesta->cuerpo(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testDeUnaFuncionCuentaLasConfirmadasYLasConAsistencia(): void
    {
        self::assertSame(
            ['funcion_id' => self::FUNCION, 'lugares_ocupados' => 5],
            self::json($this->pedir('/interno/funciones/' . self::FUNCION . '/ocupacion')),
        );
    }

    public function testDeUnaFuncionConUsuarioDevuelveTambienLugaresDelUsuario(): void
    {
        $usuario = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [Uuid::nuevo(), self::FUNCION, $usuario, 'ABCD1234', 2, 7000, 'confirmada'],
        );
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
            [Uuid::nuevo(), self::FUNCION, $usuario, 'ABCD5678', 1, 3500, 'cancelada'],
        );

        $json = self::json($this->pedir('/interno/funciones/' . self::FUNCION . '/ocupacion', ['usuario' => $usuario]));
        self::assertSame(self::FUNCION, $json['funcion_id']);
        self::assertSame(7, $json['lugares_ocupados']);
        self::assertSame(2, $json['lugares_del_usuario']);
    }

    public function testUnaFuncionSinReservasTieneCeroLugaresOcupados(): void
    {
        self::assertSame(0, self::json($this->pedir('/interno/funciones/' . self::SIN_RESERVAS . '/ocupacion'))['lugares_ocupados']);
    }

    public function testUnIdQueNoEsUuidEs404(): void
    {
        self::assertSame(404, $this->pedir('/interno/funciones/123/ocupacion')->estado());
    }

    public function testDeVariasEnUnSoloPedidoConLasQueNoTienenReservas(): void
    {
        $ocupacion = self::json($this->pedir('/interno/ocupacion', ['funciones' => implode(',', [self::FUNCION, self::OTRA, self::SIN_RESERVAS, 'no-es-un-id'])]));

        self::assertSame([self::FUNCION => 5, self::OTRA => 1, self::SIN_RESERVAS => 0], $ocupacion['lugares_ocupados']);
    }

    public function testDeVariasSinIdsDevuelveUnObjetoVacio(): void
    {
        $respuesta = $this->pedir('/interno/ocupacion');

        self::assertSame(200, $respuesta->estado());
        self::assertSame('{"lugares_ocupados":{}}', $respuesta->cuerpo());
    }

    public function testMasDeCienFuncionesEs400(): void
    {
        $ids = array_map(static fn (): string => Uuid::nuevo(), range(1, 101));

        self::assertSame(400, $this->pedir('/interno/ocupacion', ['funciones' => implode(',', $ids)])->estado());
    }

    public function testSoloLaPideProgramacion(): void
    {
        self::assertSame(403, $this->pedir('/interno/funciones/' . self::FUNCION . '/ocupacion', [], 'cuentas', self::TOKEN_DE_CUENTAS)->estado());
        self::assertSame(403, $this->pedir('/interno/ocupacion', [], 'cuentas', self::TOKEN_DE_CUENTAS)->estado());
        self::assertSame(403, $this->pedir('/interno/ocupacion', [], 'programacion', 'otro-token')->estado());
    }
}
