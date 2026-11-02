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
                    'fecha_hora_inicio' => self::dentroDe(10, '20:00:00'),
                    'fecha_hora_fin' => self::dentroDe(10, '22:30:00'),
                ],
                [
                    'fecha_hora_inicio' => self::dentroDe(11, '21:00:00'),
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
                ['fecha_hora_inicio' => self::dentroDe(10, '20:00:00')],
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
                ['fecha_hora_inicio' => self::dentroDe(10, '20:00:00')],
                ['fecha_hora_inicio' => self::dentroDe(11, '20:00:00')],
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

        // Consulta pasando usuario_id de Eva: debe decir que marcó la franja 1
        $consultaEva = $this->pedirInterno('GET', '/interno/acuerdos/por-funcion/' . self::FUNCION_ID . '?usuario_id=' . self::USUARIO_EVA);
        self::assertSame(200, $consultaEva->estado());
        $datosEva = json_decode($consultaEva->cuerpo(), true);
        self::assertSame([$franja1Id], $datosEva['franjas_del_usuario']);
        self::assertSame(1, $datosEva['franjas'][0]['votos']);
        self::assertSame(0, $datosEva['franjas'][1]['votos']);

        // Eva marca también la franja 2: «Podés marcar más de una» (E3-13.F7)
        $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdoId}/votar", [
            'franja_id' => $franja2Id,
            'usuario_id' => self::USUARIO_EVA,
        ]);

        $datosDos = json_decode($this->pedirInterno('GET', '/interno/acuerdos/por-funcion/' . self::FUNCION_ID . '?usuario_id=' . self::USUARIO_EVA)->cuerpo(), true);
        self::assertSame([$franja1Id, $franja2Id], $datosDos['franjas_del_usuario']);
        self::assertSame(1, $datosDos['franjas'][0]['votos']);
        self::assertSame(1, $datosDos['franjas'][1]['votos']);

        // Y quita la 1: queda sólo la 2
        $quitar = $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdoId}/quitar-voto", [
            'franja_id' => $franja1Id,
            'usuario_id' => self::USUARIO_EVA,
        ]);
        self::assertSame(200, $quitar->estado());

        $datosQuitado = json_decode($this->pedirInterno('GET', '/interno/acuerdos/por-funcion/' . self::FUNCION_ID . '?usuario_id=' . self::USUARIO_EVA)->cuerpo(), true);
        self::assertSame([$franja2Id], $datosQuitado['franjas_del_usuario']);
        self::assertSame(0, $datosQuitado['franjas'][0]['votos']);
        self::assertSame(1, $datosQuitado['franjas'][1]['votos']);
    }

    /**
     * E3-13.F7: marcar dos veces la misma franja (un doble clic) cuenta una sola vez.
     */
    public function testMarcarDosVecesLaMismaFranjaCuentaUnaSola(): void
    {
        $acuerdo = json_decode($this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 2,
            'franjas' => [['fecha_hora_inicio' => self::dentroDe(10, '20:00:00')], ['fecha_hora_inicio' => self::dentroDe(11, '20:00:00')]],
        ])->cuerpo(), true);
        $franjaId = $acuerdo['franjas'][0]['id'];

        foreach ([1, 2] as $vez) {
            $voto = $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdo['id']}/votar", ['franja_id' => $franjaId, 'usuario_id' => self::USUARIO_EVA]);
            self::assertSame(200, $voto->estado(), "Marcar por {$vez}.ª vez");
        }

        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM voto_acuerdo WHERE franja_id = ?', [$franjaId]));
    }

    /**
     * E3-13.F7: no se quita una marca de una franja de otro acuerdo, ni con el acuerdo cerrado.
     */
    public function testQuitarElVotoPideLaFranjaDelAcuerdoYElAcuerdoAbierto(): void
    {
        $acuerdo = json_decode($this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 2,
            'franjas' => [['fecha_hora_inicio' => self::dentroDe(10, '20:00:00')], ['fecha_hora_inicio' => self::dentroDe(11, '20:00:00')]],
        ])->cuerpo(), true);
        $franjaId = $acuerdo['franjas'][0]['id'];
        $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdo['id']}/votar", ['franja_id' => $franjaId, 'usuario_id' => self::USUARIO_EVA]);

        $ajena = $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdo['id']}/quitar-voto", ['franja_id' => Uuid::nuevo(), 'usuario_id' => self::USUARIO_EVA]);
        self::assertSame(422, $ajena->estado());

        $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdo['id']}/cerrar-sin-minimo");
        $cerrado = $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdo['id']}/quitar-voto", ['franja_id' => $franjaId, 'usuario_id' => self::USUARIO_EVA]);
        self::assertSame(422, $cerrado->estado());
        self::assertSame(1, $this->base->valor('SELECT COUNT(*) FROM voto_acuerdo WHERE franja_id = ?', [$franjaId]));
    }

    public function testConfirmarFranjaGanadoraCuandoAlcanzaElMinimo(): void
    {
        $crear = $this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 2,
            'franjas' => [
                ['fecha_hora_inicio' => self::dentroDe(10, '20:00:00')],
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
                ['fecha_hora_inicio' => self::dentroDe(10, '20:00:00')],
            ],
        ]);
        $acuerdo = json_decode($crear->cuerpo(), true);
        $acuerdoId = $acuerdo['id'];

        $cierre = $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdoId}/cerrar-sin-minimo");
        self::assertSame(200, $cierre->estado());
        $datosCierre = json_decode($cierre->cuerpo(), true);
        self::assertSame('sin_minimo', $datosCierre['estado']);
    }

    /**
     * «2026-11-12 20:00:00»: a tantos días de hoy. Con fechas fijas, las pruebas empezaron a
     * fallar cuando esas fechas pasaron, y ahora una franja pasada se rechaza (E3-13.F2).
     */
    private static function dentroDe(int $dias, string $hora): string
    {
        return (new \DateTimeImmutable("+{$dias} days"))->format('Y-m-d') . " {$hora}";
    }

    public function testRechazaUnaFranjaQueYaPaso(): void
    {
        $respuesta = $this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 2,
            'franjas' => [
                ['fecha_hora_inicio' => self::dentroDe(10, '20:00:00')],
                ['fecha_hora_inicio' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d') . ' 20:00:00'],
            ],
        ]);

        self::assertSame(422, $respuesta->estado());
        self::assertSame(0, $this->base->valor('SELECT COUNT(*) FROM acuerdo_fecha WHERE funcion_id = ?', [self::FUNCION_ID]));
    }

    public function testRechazaUnSegundoAcuerdoAbiertoPeroNoDespuesDeCerrarSinMinimo(): void
    {
        $propuesta = [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 2,
            'franjas' => [['fecha_hora_inicio' => self::dentroDe(10, '20:00:00')]],
        ];
        $primero = json_decode($this->pedirInterno('POST', '/interno/acuerdos', $propuesta)->cuerpo(), true);

        self::assertSame(422, $this->pedirInterno('POST', '/interno/acuerdos', $propuesta)->estado());

        $this->pedirInterno('POST', "/interno/acuerdos/{$primero['id']}/cerrar-sin-minimo");
        self::assertSame(201, $this->pedirInterno('POST', '/interno/acuerdos', $propuesta)->estado());
    }

    /**
     * E3-13.F4: con la franja ganadora ya empezada no se confirma, y el acuerdo sigue abierto.
     */
    public function testNoConfirmaUnaFranjaGanadoraQueYaEmpezo(): void
    {
        $acuerdo = json_decode($this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 1,
            'franjas' => [['fecha_hora_inicio' => self::dentroDe(10, '20:00:00')]],
        ])->cuerpo(), true);
        $franjaId = $acuerdo['franjas'][0]['id'];
        $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdo['id']}/votar", ['franja_id' => $franjaId, 'usuario_id' => self::USUARIO_EVA]);
        // Pasan los días: la franja ya empezó.
        $this->base->ejecutar('UPDATE acuerdo_franja SET fecha_hora_inicio = ? WHERE id = ?', [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $franjaId]);

        $respuesta = $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdo['id']}/confirmar");

        self::assertSame(422, $respuesta->estado());
        self::assertSame('abierto', $this->base->valor('SELECT estado FROM acuerdo_fecha WHERE id = ?', [$acuerdo['id']]));
    }

    /**
     * E3-13.F5: después de cerrar sin mínimo y proponer franjas nuevas, el voto del espectador en
     * el acuerdo nuevo es el que la ficha le marca. Antes la fila quedaba con el acuerdo viejo.
     */
    public function testElVotoEnUnAcuerdoNuevoQuedaComoElVotoDelUsuario(): void
    {
        $propuesta = [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 3,
            'franjas' => [['fecha_hora_inicio' => self::dentroDe(10, '20:00:00')], ['fecha_hora_inicio' => self::dentroDe(11, '20:00:00')]],
        ];
        $primero = json_decode($this->pedirInterno('POST', '/interno/acuerdos', $propuesta)->cuerpo(), true);
        $this->pedirInterno('POST', "/interno/acuerdos/{$primero['id']}/votar", ['franja_id' => $primero['franjas'][0]['id'], 'usuario_id' => self::USUARIO_EVA]);
        $this->pedirInterno('POST', "/interno/acuerdos/{$primero['id']}/cerrar-sin-minimo");

        $segundo = json_decode($this->pedirInterno('POST', '/interno/acuerdos', $propuesta)->cuerpo(), true);
        $nueva = $segundo['franjas'][1]['id'];
        $voto = $this->pedirInterno('POST', "/interno/acuerdos/{$segundo['id']}/votar", ['franja_id' => $nueva, 'usuario_id' => self::USUARIO_EVA]);
        self::assertSame(200, $voto->estado());

        $acuerdo = json_decode($this->pedirInterno('GET', '/interno/acuerdos/por-funcion/' . self::FUNCION_ID . '?usuario_id=' . self::USUARIO_EVA)->cuerpo(), true);
        self::assertSame($segundo['id'], $acuerdo['id']);
        self::assertSame([$nueva], $acuerdo['franjas_del_usuario']);
        self::assertSame(1, $acuerdo['franjas'][1]['votos']);
    }

    /**
     * E3-13.F8: «Me sirve» se marca hasta las 23:59 del día anterior a la primera franja, así que
     * una franja de hoy no se acepta.
     */
    public function testNoAceptaUnaFranjaDeHoy(): void
    {
        $respuesta = $this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 2,
            'franjas' => [
                ['fecha_hora_inicio' => (new \DateTimeImmutable('today'))->format('Y-m-d') . ' 23:59:00'],
                ['fecha_hora_inicio' => self::dentroDe(11, '20:00:00')],
            ],
        ]);

        self::assertSame(422, $respuesta->estado());
    }

    /**
     * E3-13.F8: la fecha límite sale de la primera franja, como en el wireframe.
     */
    public function testElAcuerdoDiceSuFechaLimite(): void
    {
        $this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => 2,
            'franjas' => [['fecha_hora_inicio' => self::dentroDe(11, '20:00:00')], ['fecha_hora_inicio' => self::dentroDe(10, '21:00:00')]],
        ]);

        $acuerdo = json_decode($this->pedirInterno('GET', '/interno/acuerdos/por-funcion/' . self::FUNCION_ID)->cuerpo(), true);

        self::assertSame(self::dentroDe(9, '23:59:59'), $acuerdo['fecha_limite']);
        self::assertTrue($acuerdo['votacion_abierta']);
    }

    /**
     * E3-13.F8: pasada la fecha límite no se marca más, y si ninguna franja llegó al mínimo el
     * acuerdo se cierra sin mínimo para que el organizador decida.
     */
    public function testPasadaLaFechaLimiteSinMinimoElAcuerdoSeCierra(): void
    {
        $acuerdo = $this->acuerdoConLaPrimeraFranjaYaEmpezada(minimo: 2, votoDeEva: false);

        $voto = $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdo['id']}/votar", ['franja_id' => $acuerdo['franjas'][1]['id'], 'usuario_id' => self::USUARIO_EVA]);
        self::assertSame(422, $voto->estado());

        $leido = json_decode($this->pedirInterno('GET', '/interno/acuerdos/por-funcion/' . self::FUNCION_ID)->cuerpo(), true);
        self::assertSame('sin_minimo', $leido['estado']);
        self::assertFalse($leido['votacion_abierta']);
    }

    /**
     * E3-13.F8: con el mínimo alcanzado, el acuerdo sigue abierto después de la fecha límite: el
     * organizador todavía tiene que confirmar la franja.
     */
    public function testPasadaLaFechaLimiteConElMinimoSigueAbierto(): void
    {
        $this->acuerdoConLaPrimeraFranjaYaEmpezada(minimo: 1, votoDeEva: true);

        $leido = json_decode($this->pedirInterno('GET', '/interno/acuerdos/por-funcion/' . self::FUNCION_ID)->cuerpo(), true);

        self::assertSame('abierto', $leido['estado']);
        self::assertFalse($leido['votacion_abierta']);
    }

    /**
     * Un acuerdo con dos franjas cuya primera se corre a una hora atrás: su fecha límite ya pasó.
     *
     * @return array<string, mixed> el acuerdo como lo devolvió el alta
     */
    private function acuerdoConLaPrimeraFranjaYaEmpezada(int $minimo, bool $votoDeEva): array
    {
        $acuerdo = json_decode($this->pedirInterno('POST', '/interno/acuerdos', [
            'funcion_id' => self::FUNCION_ID,
            'minimo_asistentes' => $minimo,
            'franjas' => [['fecha_hora_inicio' => self::dentroDe(10, '20:00:00')], ['fecha_hora_inicio' => self::dentroDe(11, '20:00:00')]],
        ])->cuerpo(), true);
        if ($votoDeEva) {
            $this->pedirInterno('POST', "/interno/acuerdos/{$acuerdo['id']}/votar", ['franja_id' => $acuerdo['franjas'][0]['id'], 'usuario_id' => self::USUARIO_EVA]);
        }
        $this->base->ejecutar(
            'UPDATE acuerdo_franja SET fecha_hora_inicio = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $acuerdo['franjas'][0]['id']],
        );

        return $acuerdo;
    }
}
