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
 * La API interna de solicitudes de sala de punta a punta (EF-07.6): la aplicación entera con las
 * rutas de moderación, la autorización por token y un MySQL de verdad. La llama cuentas, para el
 * bloque «Mi solicitud de sala» de «Mi cuenta».
 */
final class SolicitudesInternasTest extends TestCase
{
    use ConBaseDePruebas;

    private const USUARIA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const TOKEN_DE_CUENTAS = 'token-de-cuentas';
    private const TOKEN_DE_PROGRAMACION = 'token-de-programacion';

    private BaseDeDatos $base;
    private Configuracion $configuracion;

    protected function setUp(): void
    {
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'moderacion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto-de-nginx'],
            'llamadores' => [
                'cuentas' => hash('sha256', self::TOKEN_DE_CUENTAS),
                'programacion' => hash('sha256', self::TOKEN_DE_PROGRAMACION),
            ],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    private function pedir(string $usuario = self::USUARIA, string $llamador = 'cuentas', string $token = self::TOKEN_DE_CUENTAS): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('moderacion', fopen('php://memory', 'w')));

        return $aplicacion->atender(new Peticion('GET', "/interno/solicitudes/ultima/{$usuario}", [], [], [
            'X-Llamador' => $llamador,
            'Authorization' => "Bearer {$token}",
        ]));
    }

    /**
     * Una solicitud de la usuaria, como la deja la bandeja.
     *
     * @param array<string, mixed> $columnas además de las de una pendiente
     */
    private function solicitud(string $sala, string $enviada, array $columnas = []): void
    {
        $columnas += ['id' => Uuid::nuevo(), 'sala_id' => Uuid::nuevo(), 'usuario_id' => self::USUARIA, 'nombre' => $sala, 'enviada_en' => $enviada];
        $this->base->ejecutar(
            'INSERT INTO solicitud_sala (' . implode(', ', array_keys($columnas)) . ", descripcion, direccion, localidad, capacidad,
                peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones)
             VALUES (" . implode(', ', array_fill(0, count($columnas), '?')) . ", 'Un galpón.', 'Calle 1', 'Luján', 40, 2, 120, 15)",
            array_values($columnas),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Respuesta $respuesta): array
    {
        self::assertSame(200, $respuesta->estado(), $respuesta->cuerpo());

        return (array) json_decode($respuesta->cuerpo(), true);
    }

    public function testDevuelveLaUltimaConElMotivoDeLaResolucion(): void
    {
        $this->solicitud('La Vieja', '2026-09-01 10:00:00', ['estado' => 'aprobada', 'motivo' => 'Bien.', 'resuelta_en' => '2026-09-02 10:00:00']);
        $this->solicitud('El Galpón', '2026-10-01 10:00:00', ['estado' => 'rechazada', 'motivo' => 'Falta la póliza.', 'resuelta_en' => '2026-10-03 15:30:00']);
        $this->solicitud('De otra', '2026-11-01 10:00:00', ['usuario_id' => Uuid::nuevo()]);

        self::assertSame(
            ['estado' => 'rechazada', 'sala' => 'El Galpón', 'motivo' => 'Falta la póliza.', 'fecha' => '2026-10-03T15:30:00+00:00'],
            self::json($this->pedir()),
        );
    }

    /**
     * En revisión manual no está resuelta: el motivo que ve es el de la revisión.
     */
    public function testEnRevisionManualDevuelveElMotivoDeLaRevision(): void
    {
        $this->solicitud('El Galpón', '2026-10-01 10:00:00', [
            'estado' => 'en_revision_manual',
            'revision_motivo' => 'Coordinamos una visita.',
            'revision_por' => Uuid::nuevo(),
            'revision_en' => '2026-10-02 12:00:00',
        ]);

        self::assertSame(
            ['estado' => 'en_revision_manual', 'sala' => 'El Galpón', 'motivo' => 'Coordinamos una visita.', 'fecha' => '2026-10-02T12:00:00+00:00'],
            self::json($this->pedir()),
        );
    }

    public function testUnaPendienteNoTieneMotivoYLaFechaEsLaDelEnvio(): void
    {
        $this->solicitud('El Galpón', '2026-10-01 10:00:00');

        self::assertSame(
            ['estado' => 'pendiente', 'sala' => 'El Galpón', 'motivo' => null, 'fecha' => '2026-10-01T10:00:00+00:00'],
            self::json($this->pedir()),
        );
    }

    /**
     * EF-22.10: la devuelta trae el motivo de la devolución, su fecha y hasta cuándo tiene para
     * completarla.
     */
    public function testUnaDevueltaTraeElMotivoYLaFechaLimite(): void
    {
        $this->solicitud('El Galpón', '2026-10-01 10:00:00', [
            'estado' => 'devuelta',
            'devolucion_motivo' => 'La póliza venció.',
            'devuelta_por' => Uuid::nuevo(),
            'devuelta_en' => '2026-10-02 12:00:00',
            'devolucion_vence' => '2026-10-17',
            'devolucion_faltantes' => 'seguro',
        ]);

        self::assertSame(
            ['estado' => 'devuelta', 'sala' => 'El Galpón', 'motivo' => 'La póliza venció.', 'fecha' => '2026-10-02T12:00:00+00:00', 'vence' => '2026-10-17'],
            self::json($this->pedir()),
        );
    }

    public function testUnaVencidaTraeElMotivoYLaFechaDelCierre(): void
    {
        $this->solicitud('El Galpón', '2026-10-01 10:00:00', [
            'estado' => 'vencida',
            'motivo' => 'Venció el plazo para completar la documentación.',
            'resuelta_en' => '2026-10-18 06:45:00',
            'devolucion_motivo' => 'La póliza venció.',
            'devuelta_por' => Uuid::nuevo(),
            'devuelta_en' => '2026-10-02 12:00:00',
            'devolucion_vence' => '2026-10-17',
            'devolucion_faltantes' => 'seguro',
        ]);

        self::assertSame(
            ['estado' => 'vencida', 'sala' => 'El Galpón', 'motivo' => 'Venció el plazo para completar la documentación.', 'fecha' => '2026-10-18T06:45:00+00:00'],
            self::json($this->pedir()),
        );
    }

    public function testSinSolicitudesEs404(): void
    {
        self::assertSame(404, $this->pedir()->estado());
        self::assertSame(404, $this->pedir('no-es-un-id')->estado());
    }

    public function testSoloLaLlamaCuentas(): void
    {
        $this->solicitud('El Galpón', '2026-10-01 10:00:00');

        self::assertSame(403, $this->pedir(llamador: 'programacion', token: self::TOKEN_DE_PROGRAMACION)->estado());
        self::assertSame(403, $this->pedir(token: 'otro-token')->estado());
    }
}
