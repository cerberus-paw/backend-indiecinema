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
 * GET /interno/embudo (EF-22.13): hasta qué paso llegó cada espectador en cada función, para el
 * embudo de los reportes de recaudación.
 */
final class EmbudoInternoTest extends TestCase
{
    use ConBaseDePruebas;

    private const TOKEN_DE_RECAUDACION = 'token-de-recaudacion';
    private const TOKEN_DE_PROGRAMACION = 'token-de-programacion';
    private const RUTA = '/interno/embudo';

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'llamadores' => [
                'recaudacion' => hash('sha256', self::TOKEN_DE_RECAUDACION),
                'programacion' => hash('sha256', self::TOKEN_DE_PROGRAMACION),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    /**
     * @param array<string, string> $consulta
     */
    private function pedir(array $consulta, string $llamador = 'recaudacion', string $token = self::TOKEN_DE_RECAUDACION): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', self::RUTA, $consulta, [], [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ], [], []));
    }

    /** Una reserva y, si se pide, la calificación de quien la hizo; devuelve el id de la reserva. */
    private function reserva(string $funcion, string $usuario, string $estado, string $total, bool $califico = false): string
    {
        $id = Uuid::nuevo();
        $this->base->ejecutar(
            'INSERT INTO reserva (id, funcion_id, usuario_id, codigo, cantidad, total, estado, creada_en, vence_en) VALUES (?, ?, ?, ?, 1, ?, ?, NOW(), ?)',
            [$id, $funcion, $usuario, strtoupper(substr(str_replace('-', '', $id), 0, 8)), $total, $estado, $estado === 'pendiente_pago' ? date('Y-m-d H:i:s', time() + 600) : null],
        );
        if ($califico) {
            $this->base->ejecutar(
                'INSERT INTO calificacion (id, funcion_id, usuario_id, reserva_id, puntaje, comentario, sala_id, organizador_id, creada_en) VALUES (?, ?, ?, ?, 5, NULL, ?, ?, NOW())',
                [Uuid::nuevo(), $funcion, $usuario, $id, Uuid::nuevo(), Uuid::nuevo()],
            );
        }

        return $id;
    }

    public function testDiceHastaQuePasoLlegoCadaEspectadorYCuantoPago(): void
    {
        $funcion = Uuid::nuevo();
        $otra = Uuid::nuevo();
        [$ana, $beto, $caro, $dani, $eli] = [Uuid::nuevo(), Uuid::nuevo(), Uuid::nuevo(), Uuid::nuevo(), Uuid::nuevo()];
        // Ana reservó y se le venció el pago; después no volvió.
        $this->reserva($funcion, $ana, 'cancelada', '3000.00');
        // Beto pagó y no fue.
        $this->reserva($funcion, $beto, 'ausente', '3000.00');
        // Caro probó dos veces: la primera se cayó y la segunda, de dos entradas, la pagó y fue.
        $this->reserva($funcion, $caro, 'cancelada', '3000.00');
        $this->reserva($funcion, $caro, 'asistio', '6000.00');
        // Dani fue y calificó.
        $this->reserva($funcion, $dani, 'asistio', '3000.00', true);
        // Eli está pagando ahora: todavía no pagó.
        $this->reserva($otra, $eli, 'pendiente_pago', '3000.00');

        $respuesta = $this->pedir(['funciones' => "{$funcion},{$otra}," . Uuid::nuevo()]);

        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());
        $funciones = json_decode($respuesta->cuerpo(), true)['funciones'];
        $porUsuario = array_column($funciones[$funcion], null, 'usuario_id');
        self::assertSame(['llego_a' => 'reservo', 'total' => '3000.00'], array_diff_key($porUsuario[$ana], ['usuario_id' => 0]));
        self::assertSame(['llego_a' => 'pago', 'total' => '3000.00'], array_diff_key($porUsuario[$beto], ['usuario_id' => 0]));
        self::assertSame(['llego_a' => 'fue', 'total' => '6000.00'], array_diff_key($porUsuario[$caro], ['usuario_id' => 0]));
        self::assertSame(['llego_a' => 'califico', 'total' => '3000.00'], array_diff_key($porUsuario[$dani], ['usuario_id' => 0]));
        self::assertSame([['usuario_id' => $eli, 'llego_a' => 'reservo', 'total' => '3000.00']], $funciones[$otra]);
        // La que no tiene reservas no viene.
        self::assertCount(2, $funciones);
    }

    public function testPideIdsValidosYLaPideSoloRecaudacion(): void
    {
        self::assertSame(400, $this->pedir([])->estado());
        self::assertSame(400, $this->pedir(['funciones' => 'no-es-uuid'])->estado());
        self::assertSame(403, $this->pedir(['funciones' => Uuid::nuevo()], 'programacion', self::TOKEN_DE_PROGRAMACION)->estado());
    }
}
