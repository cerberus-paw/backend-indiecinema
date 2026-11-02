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
 * Pruebas de integración de la API interna de acuerdos de fecha (E3-13).
 */
final class AcuerdosInternosTest extends TestCase
{
    use ConBaseDePruebas;

    private const TOKEN_PROGRAMACION = 'token-de-programacion';
    private const FUNCION_ID = '11111111-1111-4111-8111-111111111111';
    private const USUARIO_EVA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const USUARIO_ELIAS = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private Configuracion $configuracion;
    private BaseDeDatos $base;

    protected function setUp(): void
    {
        $hashTokenProgramacion = hash('sha256', self::TOKEN_PROGRAMACION);
        $this->configuracion = new Configuracion([
            'app' => ['subsistema' => 'funciones', 'prefijo' => '/funcion', 'entorno' => 'desarrollo'],
            'base' => self::baseDePruebas(),
            'nginx' => ['secreto' => 'secreto'],
            'subsistemas' => ['programacion' => 'http://programacion:8080'],
            'interno' => ['token' => 'token-de-funciones'],
            'llamadores' => ['programacion' => $hashTokenProgramacion],
        ]);
        $this->base = self::baseConTablasNuevas($this->configuracion);
    }

    /**
     * @param array<string, mixed> $cuerpo
     */
    private function pedirInterno(string $metodo, string $ruta, array $cuerpo = []): Respuesta
    {
        $aplicacion = new Aplicacion(dirname(__DIR__), $this->configuracion, new Log('funciones', fopen('php://memory', 'w')));

        $cabeceras = [
            'X-Llamador' => 'programacion',
            'Authorization' => 'Bearer ' . self::TOKEN_PROGRAMACION,
            'Content-Type' => 'application/json',
        ];

        parse_str((string) parse_url($ruta, PHP_URL_QUERY), $consulta);
        $json = $cuerpo === [] ? '' : json_encode($cuerpo, JSON_THROW_ON_ERROR);

        return $aplicacion->atender(new Peticion(
            $metodo,
            (string) parse_url($ruta, PHP_URL_PATH),
            $consulta,
            $cuerpo,
            $cabeceras,
            [],
            [],
        ));
    }

    public function testProgramacionCreaUnAcuerdoConFranjasYMinimoDeAsistentes(): void
    {
        $respuesta = $this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 20,
            'franjas' => [
                [
                    'fecha_hora_inicio' => '2026-10-25 20:00:00',
                    'fecha_hora_fin' => '2026-10-25 22:30:00',
                ],
                [
                    'fecha_hora_inicio' => '2026-10-26 21:00:00',
                    'fecha_hora_fin' => null,
                ],
            ],
        ]);

        self::assertSame(201, $respuesta->estado(), $respuesta->cuerpo());
        $datos = json_decode($respuesta->cuerpo(), true);
        self::assertIsArray($datos);
        self::assertSame(self::FUNCION_ID, $datos['funcion_id']);
        self::assertSame(20, $datos['minimo_asistentes']);
        self::assertSame('abierto', $datos['estado']);
        self::assertCount(2, $datos['franjas']);

        // Verificamos que se guardó en la base de datos
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM acuerdo_fecha WHERE id = ?', [$datos['id']]));
        self::assertSame(2, $this->base->valor('SELECT COUNT(*) FROM acuerdo_franja WHERE acuerdo_id = ?', [$datos['id']]));
    }

    public function testRechazaMinimoDeAsistentesInvalido(): void
    {
        $respuesta = $this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 0,
            'franjas' => [
                ['fecha_hora_inicio' => '2026-10-25 20:00:00'],
            ],
        ]);

        self::assertSame(422, $respuesta->estado());
    }

    public function testRechazaAcuerdoSinFranjas(): void
    {
        $respuesta = $this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 15,
            'franjas' => [],
        ]);

        self::assertSame(422, $respuesta->estado());
    }

    public function testConsultaDeAcuerdoPorFuncionConFranjasYVotos(): void
    {
        $crear = $this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 2,
            'franjas' => [
                ['fecha_hora_inicio' => '2026-10-25 20:00:00'],
                ['fecha_hora_inicio' => '2026-10-26 20:00:00'],
            ],
        ]);
        $acuerdo = json_decode($crear->cuerpo(), true);
        $acuerdoId = $acuerdo['id'];
        $franja1Id = $acuerdo['franjas'][0]['id'];
        $franja2Id = $acuerdo['franjas'][1]['id'];

        // Eva vota franja 1
        $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdoId}/votar", [
            'franja_id' => $franja1Id,
            'usuario_id' => self::USUARIO_EVA,
        ]);

        // Consulta pasando usuario_id de Eva: debe decir que votó franja 1
        $consultaEva = $this->pedirInterno('GET', '/interno/acuerdos/por-funcion/' . self::FUNCION_ID . '?usuario_id=' . self::USUARIO_EVA);
        self::assertSame(200, $consultaEva->estado());
        $datosEva = json_decode($consultaEva->cuerpo(), true);
        self::assertSame($franja1Id, $datosEva['voto_usuario_franja_id']);
        self::assertSame(1, $datosEva['franjas'][0]['votos']);
        self::assertSame(0, $datosEva['franjas'][1]['votos']);

        // Eva cambia su voto a franja 2
        $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdoId}/votar", [
            'franja_id' => $franja2Id,
            'usuario_id' => self::USUARIO_EVA,
        ]);

        $consultaEvaCambio = $this->pedirInterno('GET', '/interno/acuerdos/por-funcion/' . self::FUNCION_ID . '?usuario_id=' . self::USUARIO_EVA);
        $datosCambio = json_decode($consultaEvaCambio->cuerpo(), true);
        self::assertSame($franja2Id, $datosCambio['voto_usuario_franja_id']);
        self::assertSame(0, $datosCambio['franjas'][0]['votos']);
        self::assertSame(1, $datosCambio['franjas'][1]['votos']);
    }

    public function testConfirmarFranjaGanadoraCuandoAlcanzaElMinimo(): void
    {
        $crear = $this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 2,
            'franjas' => [
                ['fecha_hora_inicio' => '2026-10-25 20:00:00'],
            ],
        ]);
        $acuerdo = json_decode($crear->cuerpo(), true);
        $acuerdoId = $acuerdo['id'];
        $franjaId = $acuerdo['franjas'][0]['id'];

        // Intentar confirmar sin alcanzar el mínimo debe fallar (422)
        $fallo = $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdoId}/confirmar");
        self::assertSame(422, $fallo->estado());

        // Eva y Elías votan
        $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdoId}/votar", [
            'franja_id' => $franjaId,
            'usuario_id' => self::USUARIO_EVA,
        ]);
        $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdoId}/votar", [
            'franja_id' => $franjaId,
            'usuario_id' => self::USUARIO_ELIAS,
        ]);

        // Ahora sí alcanza el mínimo (2 votos >= 2 requeridos)
        $exito = $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdoId}/confirmar");
        self::assertSame(200, $exito->estado());
        $datosConfirmados = json_decode($exito->cuerpo(), true);
        self::assertSame('confirmado', $datosConfirmados['estado']);
        self::assertSame($franjaId, $datosConfirmados['franja_ganadora_id']);
    }

    public function testCerrarAcuerdoSinMinimo(): void
    {
        $crear = $this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 20,
            'franjas' => [
                ['fecha_hora_inicio' => '2026-10-25 20:00:00'],
            ],
        ]);
        $acuerdo = json_decode($crear->cuerpo(), true);
        $acuerdoId = $acuerdo['id'];

        $cierre = $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdoId}/cerrar-sin-minimo");
        self::assertSame(200, $cierre->estado());
        $datosCierre = json_decode($cierre->cuerpo(), true);
        self::assertSame('sin_minimo', $datosCierre['estado']);
    }
}
