<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;
use RuntimeException;

/**
 * Dos meses de funciones que ya pasaron, para que los reportes de recaudación tengan algo que
 * cruzar en la demo (EF-09.4): con un sistema recién cargado no hay ninguna función finalizada. Son
 * de las salas y las obras de DatosDePrueba, que se cargan antes.
 *
 * Programación cuenta su parte de la historia: cuándo y dónde fue cada función, entre qué obras se
 * votó, cuántos votaron y qué ganó. Lo que se vendió, quiénes fueron y qué les pareció lo carga
 * funciones con su propio historial (funciones/herramientas/historial-de-prueba.php), que pide
 * estas funciones por la API interna. Armado para que aparezca cada sugerencia del reporte:
 *
 * - El Alambique da funciones los miércoles y los sábados: los miércoles van pocos (funciones
 *   los llena poco) y los sábados, casi todos.
 * - Los sábados impares se da «Metrópolis» con fecha fija; los pares se vota y gana «Nosferatu».
 * - «El gabinete del Dr. Caligari» pierde todas sus votaciones con más de un tercio de los votos y
 *   no se proyecta nunca: lo que el público pidió y no vio.
 * - La Trocha da dos obras los viernes, sin votación; el Eureka, una los domingos cada dos semanas.
 *
 * Los votantes son espectadores inventados, con un id de la serie 00000008-…: funciones usa la
 * misma serie para los que compran, así una parte de los que votaron también reservó.
 */
final class HistorialDePrueba
{
    /** Las semanas hacia atrás. */
    public const SEMANAS = 8;

    private const ALAMBIQUE = '00000001-0000-4000-8000-000000000001';
    private const TROCHA = '00000001-0000-4000-8000-000000000002';
    private const EUREKA = '00000001-0000-4000-8000-000000000003';

    private const METROPOLIS = '00000002-0000-4000-8000-000000000001';
    private const NOSFERATU = '00000002-0000-4000-8000-000000000002';
    private const VIAJE_A_LA_LUNA = '00000002-0000-4000-8000-000000000003';
    private const CALIGARI = '00000002-0000-4000-8000-000000000004';
    private const MAQUINISTA = '00000002-0000-4000-8000-000000000005';
    private const POTEMKIN = '00000002-0000-4000-8000-000000000006';
    private const MUERTOS_VIVIENTES = '00000002-0000-4000-8000-000000000007';
    private const SHERLOCK = '00000002-0000-4000-8000-000000000008';
    private const HOMBRE_DE_LA_CAMARA = '00000002-0000-4000-8000-000000000009';
    private const FANTASMA = '00000002-0000-4000-8000-000000000010';
    private const VIAJE_IMPOSIBLE = '00000002-0000-4000-8000-000000000011';
    private const EL_PIBE = '00000002-0000-4000-8000-000000000012';

    private function __construct(
        private readonly RepositorioDeSalas $salas,
        private readonly RepositorioDeFunciones $funciones,
        private readonly RepositorioDeVotos $votos,
    ) {
    }

    /**
     * Como DatosDePrueba: en desarrollo, siempre; en producción, sólo con la confirmación.
     *
     * @throws RuntimeException en producción, sin la confirmación
     */
    public static function para(
        Configuracion $configuracion,
        RepositorioDeSalas $salas,
        RepositorioDeFunciones $funciones,
        RepositorioDeVotos $votos,
        bool $confirmadoEnProduccion = false,
    ): self {
        if (!$configuracion->enDesarrollo() && !$confirmadoEnProduccion) {
            throw new RuntimeException(
                'Esto es producción: el historial de prueba se carga acá sólo con la confirmación explícita, '
                . 'agregando --confirmar-produccion al comando.',
            );
        }

        return new self($salas, $funciones, $votos);
    }

    /**
     * El id de la función n del historial (de 1 en adelante). Funciones los recorre así.
     */
    public static function idDeFuncion(int $numero): string
    {
        return sprintf('00000005-0000-4000-8000-%012d', $numero);
    }

    /** El espectador inventado n de la serie que comparten los votantes y los compradores. */
    public static function idDeEspectador(int $numero): string
    {
        return sprintf('00000008-0000-4000-8000-%012d', $numero);
    }

    /**
     * Las funciones del historial, de la más vieja a la más nueva, con las fechas contadas hacia
     * atrás desde $hoy (que no entra). Cada una con sus votos: obra_id => cuántos.
     *
     * @return list<array{id: string, sala_id: string, fecha: DateTimeImmutable, precio: float, cupo: int, elegidas: list<string>, votos: array<string, int>}>
     */
    public static function plan(DateTimeImmutable $hoy): array
    {
        $hoy = $hoy->setTime(0, 0);
        $funciones = [];
        for ($semana = self::SEMANAS; $semana >= 1; --$semana) {
            $impar = $semana % 2 === 1;
            // Miércoles en el Alambique: se vota entre tres y gana una de las dos primeras.
            $total = 18 + ($semana % 3) * 3;
            $ganadora = $impar ? self::SHERLOCK : self::MAQUINISTA;
            $funciones[] = self::funcion(self::ALAMBIQUE, self::antes($hoy, 'wednesday', $semana, '20:30'), 2500.0, 80, [$ganadora], [
                $ganadora => $total - intdiv($total * 37, 100) - intdiv($total, 5),
                ($impar ? self::MAQUINISTA : self::SHERLOCK) => intdiv($total, 5),
                self::CALIGARI => intdiv($total * 37, 100),
            ]);
            // Sábados en el Alambique: «Metrópolis» con fecha fija o una votación que gana «Nosferatu».
            if ($impar) {
                $funciones[] = self::funcion(self::ALAMBIQUE, self::antes($hoy, 'saturday', $semana, '21:00'), 2500.0, 80, [self::METROPOLIS]);
            } else {
                $total = 30 + $semana;
                $funciones[] = self::funcion(self::ALAMBIQUE, self::antes($hoy, 'saturday', $semana, '21:00'), 2500.0, 80, [self::NOSFERATU], [
                    self::NOSFERATU => $total - intdiv($total * 35, 100) - intdiv($total, 6),
                    self::CALIGARI => intdiv($total * 35, 100),
                    self::EL_PIBE => intdiv($total, 6),
                ]);
            }
            // Viernes en La Trocha, que da dos obras por función: sin votación, alternando.
            $funciones[] = self::funcion(self::TROCHA, self::antes($hoy, 'friday', $semana, '21:00'), 2200.0, 120, $impar
                ? [self::POTEMKIN, self::HOMBRE_DE_LA_CAMARA]
                : [self::VIAJE_A_LA_LUNA, self::FANTASMA]);
            // Domingos en el Eureka, cada dos semanas: se vota entre dos.
            if (!$impar) {
                $funciones[] = self::funcion(self::EUREKA, self::antes($hoy, 'sunday', $semana, '19:00'), 1800.0, 60, [self::MUERTOS_VIVIENTES], [
                    self::MUERTOS_VIVIENTES => 9,
                    self::VIAJE_IMPOSIBLE => 6,
                ]);
            }
        }
        usort($funciones, static fn (array $a, array $b): int => $a['fecha'] <=> $b['fecha']);

        return array_map(
            static fn (array $funcion, int $indice): array => ['id' => self::idDeFuncion($indice + 1), ...$funcion],
            $funciones,
            array_keys($funciones),
        );
    }

    /**
     * Agrega las funciones que falten, buscándolas por su id: correrlo de nuevo no duplica nada, y
     * una que ya estaba conserva sus fechas. Las salas tienen que estar: son de DatosDePrueba.
     *
     * @return array{agregadas: int, yaEstaban: int}
     *
     * @throws RuntimeException si faltan las salas de los datos de prueba
     */
    public function cargar(DateTimeImmutable $hoy = new DateTimeImmutable('today')): array
    {
        foreach ([self::ALAMBIQUE, self::TROCHA, self::EUREKA] as $salaId) {
            if ($this->salas->buscar($salaId) === null) {
                throw new RuntimeException('Faltan las salas de prueba: primero herramientas/datos-de-prueba.php.');
            }
        }
        $agregadas = 0;
        $plan = self::plan($hoy);
        foreach ($plan as $funcion) {
            if ($this->funciones->buscar($funcion['id']) !== null) {
                continue;
            }
            if ($funcion['votos'] === []) {
                // Con fecha fija: se guarda finalizada, con sus obras ya elegidas.
                $this->funciones->agregar(new Funcion($funcion['id'], $funcion['sala_id'], $funcion['elegidas'], $funcion['precio'], $funcion['cupo'], EstadoFuncion::Finalizada, $funcion['fecha']));
            } else {
                // Con votación, como en la web: primero todas candidatas, los votos, y después la
                // función finalizada con la ganadora elegida (guardar() marca sólo ésa).
                $this->funciones->agregar(new Funcion($funcion['id'], $funcion['sala_id'], array_keys($funcion['votos']), $funcion['precio'], $funcion['cupo']));
                $votante = 0;
                foreach ($funcion['votos'] as $obraId => $cuantos) {
                    for ($voto = 0; $voto < $cuantos; ++$voto) {
                        $this->votos->votar($funcion['id'], self::idDeEspectador(++$votante), (string) $obraId);
                    }
                }
                $this->funciones->guardar(new Funcion($funcion['id'], $funcion['sala_id'], $funcion['elegidas'], $funcion['precio'], $funcion['cupo'], EstadoFuncion::Finalizada, $funcion['fecha']));
            }
            ++$agregadas;
        }

        return ['agregadas' => $agregadas, 'yaEstaban' => count($plan) - $agregadas];
    }

    /**
     * El día de la semana $dia de hace $semanas semanas, contando desde $hoy (que no entra).
     */
    private static function antes(DateTimeImmutable $hoy, string $dia, int $semanas, string $hora): DateTimeImmutable
    {
        [$horas, $minutos] = array_map(intval(...), explode(':', $hora));

        return $hoy->modify("last {$dia}")->modify('-' . ($semanas - 1) . ' weeks')->setTime($horas, $minutos);
    }

    /**
     * @param list<string>       $elegidas
     * @param array<string, int> $votos
     *
     * @return array{sala_id: string, fecha: DateTimeImmutable, precio: float, cupo: int, elegidas: list<string>, votos: array<string, int>}
     */
    private static function funcion(string $salaId, DateTimeImmutable $fecha, float $precio, int $cupo, array $elegidas, array $votos = []): array
    {
        return ['sala_id' => $salaId, 'fecha' => $fecha, 'precio' => $precio, 'cupo' => $cupo, 'elegidas' => $elegidas, 'votos' => $votos];
    }
}
