<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use IndieCinema\Nucleo\Aplicacion;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Log;
use IndieCinema\Nucleo\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * La moderación del período para los reportes del administrador (EF-22.4), de punta a punta: la
 * aplicación con las rutas de moderación, la autorización por token y un MySQL de verdad.
 */
final class ResumenInternoTest extends TestCase
{
    use ConBaseDePruebas;

    private const TOKEN_DE_RECAUDACION = 'token-de-recaudacion';
    private const TOKEN_DE_CUENTAS = 'token-de-cuentas';
    private const SALA = '10000000-0000-4000-8000-000000000001';
    private const FUNCION = '30000000-0000-4000-8000-000000000001';

    private BaseDeDatos $base;
    private Configuracion $configuracion;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'moderacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'llamadores' => [
                'recaudacion' => hash('sha256', self::TOKEN_DE_RECAUDACION),
                'cuentas' => hash('sha256', self::TOKEN_DE_CUENTAS),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    /**
     * @param array<string, string> $consulta
     */
    private function pedir(array $consulta, string $llamador = 'recaudacion', string $token = self::TOKEN_DE_RECAUDACION): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('moderacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', '/interno/resumen', $consulta, [], [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ]));
    }

    private function denuncia(string $tipo, string $objeto, string $creada, string $estado = 'abierta', string $gravedad = 'alta'): void
    {
        $cerrada = $estado === 'abierta' ? [null, null, null, null] : [$estado === 'resuelta' ? 'suspender' : null, 'Visto', Uuid::nuevo(), $creada];
        $categoria = ['alta' => 'ofensivo', 'media' => 'falsa', 'baja' => 'otro'][$gravedad];
        $this->base->ejecutar(
            'INSERT INTO denuncia (id, tipo, objeto_id, titulo, usuario_id, categoria, gravedad, creada_en, estado, accion, motivo, resuelta_por, resuelta_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Uuid::nuevo(), $tipo, $objeto, 'Lo denunciado', Uuid::nuevo(), $categoria, $gravedad, $creada, $estado, ...$cerrada],
        );
    }

    private function solicitud(string $enviada, string $estado = 'pendiente'): void
    {
        $this->base->ejecutar(
            "INSERT INTO solicitud_sala (id, sala_id, usuario_id, nombre, enviada_en, estado, resuelta_en, motivo, descripcion, direccion, localidad, capacidad,
                peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones)
             VALUES (?, ?, ?, 'Una sala', ?, ?, ?, ?, 'Un galpón.', 'Calle 1', 'Luján', 40, 2, 120, 15)",
            [Uuid::nuevo(), Uuid::nuevo(), Uuid::nuevo(), $enviada, $estado, $estado === 'pendiente' ? null : $enviada, $estado === 'pendiente' ? null : 'Está todo en orden.'],
        );
    }

    public function testCuentaLasDenunciasYLasSolicitudesDelPeriodoYLasSuspendidasDeHoy(): void
    {
        $this->denuncia('sala', self::SALA, '2026-11-05 12:00:00');
        $this->denuncia('sala', self::SALA, '2026-11-06 12:00:00');
        $this->denuncia('funcion', self::FUNCION, '2026-11-07 12:00:00', 'desestimada');
        // Fuera del período.
        $this->denuncia('sala', self::SALA, '2026-10-31 12:00:00');
        $this->solicitud('2026-11-10 12:00:00');
        $this->solicitud('2026-11-11 12:00:00', 'aprobada');
        $this->solicitud('2026-12-01 12:00:00');
        $this->base->ejecutar(
            "INSERT INTO suspension (id, sala_id, causa, motivo, desde) VALUES (?, ?, 'denuncia', 'Insultos', '2026-11-06 13:00:00')",
            [Uuid::nuevo(), self::SALA],
        );

        $respuesta = $this->pedir(['desde' => '2026-11-01 03:00:00', 'hasta' => '2026-12-01 03:00:00']);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $json = json_decode($respuesta->cuerpo(), true);
        // En el orden del ENUM de tipo: primero las de salas.
        self::assertSame([
            ['tipo' => 'sala', 'objeto_id' => self::SALA, 'estado' => 'abierta', 'gravedad' => 'alta', 'cantidad' => 2],
            ['tipo' => 'funcion', 'objeto_id' => self::FUNCION, 'estado' => 'desestimada', 'gravedad' => 'alta', 'cantidad' => 1],
        ], $json['denuncias']);
        self::assertSame([['sala_id' => self::SALA, 'causa' => 'denuncia', 'desde' => '2026-11-06 13:00:00']], $json['suspendidas']);
        $solicitudes = $json['solicitudes'];
        ksort($solicitudes);
        self::assertSame(['aprobada' => 1, 'pendiente' => 1], $solicitudes);
    }

    /**
     * EF-22.11: cada grupo lleva su gravedad, y las de un mismo objeto y estado se separan por
     * ella, de más a menos grave.
     */
    public function testLasDenunciasSeAgrupanTambienPorGravedad(): void
    {
        $this->denuncia('sala', self::SALA, '2026-11-05 12:00:00', gravedad: 'baja');
        $this->denuncia('sala', self::SALA, '2026-11-06 12:00:00', gravedad: 'media');
        $this->denuncia('sala', self::SALA, '2026-11-07 12:00:00', gravedad: 'media');
        $this->denuncia('sala', self::SALA, '2026-11-08 12:00:00', gravedad: 'alta');

        $json = json_decode($this->pedir(['desde' => '2026-11-01 03:00:00', 'hasta' => '2026-12-01 03:00:00'])->cuerpo(), true);

        self::assertSame([
            ['tipo' => 'sala', 'objeto_id' => self::SALA, 'estado' => 'abierta', 'gravedad' => 'alta', 'cantidad' => 1],
            ['tipo' => 'sala', 'objeto_id' => self::SALA, 'estado' => 'abierta', 'gravedad' => 'media', 'cantidad' => 2],
            ['tipo' => 'sala', 'objeto_id' => self::SALA, 'estado' => 'abierta', 'gravedad' => 'baja', 'cantidad' => 1],
        ], $json['denuncias']);
    }

    public function testConFechasQueNoSirvenDa400(): void
    {
        self::assertSame(400, $this->pedir([])->estado());
        self::assertSame(400, $this->pedir(['desde' => '2026-11-01', 'hasta' => '2026-12-01'])->estado());
        self::assertSame(400, $this->pedir(['desde' => '2026-12-01 00:00:00', 'hasta' => '2026-11-01 00:00:00'])->estado());
    }

    public function testSoloLaPideRecaudacion(): void
    {
        self::assertSame(403, $this->pedir(['desde' => '2026-11-01 00:00:00', 'hasta' => '2026-12-01 00:00:00'], 'cuentas', self::TOKEN_DE_CUENTAS)->estado());
    }
}
