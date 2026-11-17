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
 * API interna de conducta en lote para «Usuarios y roles» del administrador en cuentas (EF-13.3).
 */
final class ConductaInternaTest extends TestCase
{
    use ConBaseDePruebas;

    private const TOKEN_DE_CUENTAS = 'token-de-cuentas';
    private const TOKEN_DE_PROGRAMACION = 'token-de-programacion';

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'llamadores' => [
                'cuentas' => hash('sha256', self::TOKEN_DE_CUENTAS),
                'programacion' => hash('sha256', self::TOKEN_DE_PROGRAMACION),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    /**
     * @param array<string, string> $consulta
     */
    private function pedir(array $consulta = [], string $llamador = 'cuentas', string $token = self::TOKEN_DE_CUENTAS): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', '/interno/usuarios/conducta', $consulta, [], [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ], [], []));
    }

    public function testDevuelvePuntajeYContadoresDeVariosUsuarios(): void
    {
        $penalizado = Uuid::nuevo();
        $normal = Uuid::nuevo();
        $reciente = gmdate('Y-m-d H:i:s', strtotime('-3 days'));

        for ($i = 0; $i < 2; ++$i) {
            $reservaId = Uuid::nuevo();
            $this->base->ejecutar(
                "INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, 1, 0, 'asistio', ?)",
                [$reservaId, Uuid::nuevo(), $penalizado, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), $reciente],
            );
            $this->base->ejecutar(
                'INSERT INTO conducta (reserva_id, usuario_id, organizador_id, positiva, motivo, creada_en) VALUES (?, ?, ?, 0, ?, ?)',
                [$reservaId, $penalizado, Uuid::nuevo(), 'Falta', $reciente],
            );
        }
        for ($i = 0; $i < 4; ++$i) {
            $this->base->ejecutar(
                "INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en) VALUES (?, ?, ?, ?, 1, 0, 'ausente', ?)",
                [Uuid::nuevo(), Uuid::nuevo(), $penalizado, strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)), $reciente],
            );
        }

        $respuesta = $this->pedir(['ids' => "{$penalizado},{$normal},no-es-uuid"]);
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $json = json_decode($respuesta->cuerpo(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([
            $penalizado => ['puntaje' => 60, 'bajo_umbral' => true, 'negativas' => 2, 'ausencias' => 4],
            $normal => ['puntaje' => 100, 'bajo_umbral' => false, 'negativas' => 0, 'ausencias' => 0],
        ], $json['conductas']);
    }

    public function testSinIdsValidosDevuelveObjetoVacioYMasDeCienDa400(): void
    {
        $vacio = $this->pedir();
        self::assertSame(200, $vacio->estado());
        self::assertSame('{"conductas":{}}', $vacio->cuerpo());

        $cienYUno = implode(',', array_map(static fn (): string => Uuid::nuevo(), range(1, 101)));
        self::assertSame(400, $this->pedir(['ids' => $cienYUno])->estado());
    }

    public function testSoloLaPideCuentas(): void
    {
        self::assertSame(403, $this->pedir([], 'programacion', self::TOKEN_DE_PROGRAMACION)->estado());
        self::assertSame(403, $this->pedir([], 'cuentas', 'otro-token')->estado());
    }
}
