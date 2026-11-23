<?php

declare(strict_types=1);

namespace IndieCinema\Recaudacion\Servicios;

use DateTimeImmutable;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Recaudacion\Modelo\FuncionFinalizada;
use IndieCinema\Recaudacion\Modelo\ObraVotada;
use IndieCinema\Recaudacion\Modelo\Periodo;
use RuntimeException;

/**
 * Las funciones finalizadas que pide recaudación a programación por la API interna
 * (GET /interno/funciones/finalizadas), de dos maneras: las de un período, para la reportería
 * (EF-09), y las que se finalizaron desde un momento, para la tarea del reparto (EF-08.2).
 *
 * Como CobrosDeFunciones, lee la respuesta sin suponer nada: si cambió lo que manda, mejor un
 * error claro que un reporte con números a medias o un reparto de menos.
 */
final class FuncionesFinalizadas
{
    private const MONTO = '/^\d+\.\d{2}$/';

    public function __construct(private readonly ClienteInterno $cliente)
    {
    }

    /**
     * @param ?string $organizadorId sólo las de sus salas
     * @param ?string $duenoId       sólo las que proyectaron alguna obra suya
     *
     * @return array{funciones: list<FuncionFinalizada>, completas: bool} completas en false si
     *                                                                    programación cortó la lista
     *
     * @throws ExcepcionHttp    503 si programación no responde a tiempo
     * @throws RuntimeException si responde algo que no son funciones finalizadas
     */
    public function delPeriodo(Periodo $periodo, ?string $organizadorId = null, ?string $duenoId = null): array
    {
        $consulta = ['desde' => $periodo->desde->format('Y-m-d'), 'hasta' => $periodo->hasta->format('Y-m-d')];
        if ($organizadorId !== null) {
            $consulta['organizador'] = $organizadorId;
        }
        if ($duenoId !== null) {
            $consulta['dueno'] = $duenoId;
        }

        return $this->pedir($consulta);
    }

    /**
     * Las que se finalizaron desde ese momento (incluido), de la que se finalizó primero a la
     * última: es por donde sigue la tarea del reparto desde su última pasada. Van todas las del
     * sitio, sin filtrar por organizador ni por dueño.
     *
     * @return array{funciones: list<FuncionFinalizada>, completas: bool} completas en false si
     *                                                                    quedaron más para la
     *                                                                    próxima pasada
     *
     * @throws ExcepcionHttp    503 si programación no responde a tiempo
     * @throws RuntimeException si responde algo que no son funciones finalizadas
     */
    public function desdeLaFinalizacion(DateTimeImmutable $desde): array
    {
        return $this->pedir(['desde' => $desde->format('Y-m-d\TH:i:s')]);
    }

    /**
     * @param array<string, string> $consulta
     *
     * @return array{funciones: list<FuncionFinalizada>, completas: bool}
     */
    private function pedir(array $consulta): array
    {
        $datos = $this->cliente->get('programacion', '/interno/funciones/finalizadas', $consulta);
        if (!is_array($datos) || !is_array($datos['funciones'] ?? null) || !array_is_list($datos['funciones']) || !is_bool($datos['completas'] ?? null)) {
            throw new RuntimeException('programación no respondió las funciones finalizadas.');
        }

        return ['funciones' => array_map(self::funcion(...), $datos['funciones']), 'completas' => $datos['completas']];
    }

    private static function funcion(mixed $datos): FuncionFinalizada
    {
        $fecha = is_array($datos) ? self::momento($datos['fecha_hora'] ?? null) : null;
        $finalizada = is_array($datos) ? self::momento($datos['finalizada_en'] ?? null) : null;
        if (
            !is_array($datos) || $fecha === null || $finalizada === null
            || !is_string($datos['id'] ?? null) || !is_int($datos['cupo'] ?? null)
            || !is_string($datos['precio_base'] ?? null) || preg_match(self::MONTO, $datos['precio_base']) !== 1
            || !is_array($datos['sala'] ?? null) || !is_string($datos['sala']['id'] ?? null) || !is_string($datos['sala']['nombre'] ?? null)
            || !is_string($datos['organizador_id'] ?? null)
            || !is_array($datos['obras'] ?? null) || !array_is_list($datos['obras'])
            || !is_array($datos['votantes_ids'] ?? null) || !array_is_list($datos['votantes_ids'])
            || array_filter($datos['votantes_ids'], static fn (mixed $id): bool => !is_string($id)) !== []
        ) {
            throw new RuntimeException('programación respondió una función finalizada incompleta.');
        }

        /** @var list<string> $votantes */
        $votantes = $datos['votantes_ids'];

        return new FuncionFinalizada(
            $datos['id'],
            $fecha,
            $finalizada,
            $datos['cupo'],
            $datos['precio_base'],
            $datos['sala']['id'],
            $datos['sala']['nombre'],
            $datos['organizador_id'],
            array_map(static function (mixed $obra): ObraVotada {
                if (!is_array($obra) || !is_string($obra['id'] ?? null) || !is_string($obra['titulo'] ?? null)
                    || !is_string($obra['dueno_id'] ?? null) || !is_bool($obra['elegida'] ?? null) || !is_int($obra['votos'] ?? null)) {
                    throw new RuntimeException('programación respondió una obra de una función finalizada incompleta.');
                }

                // Los géneros (EF-22.18) son opcionales: una programación sin desplegar no los manda,
                // y el reporte sale igual, sin la recomendación por género.
                $generos = $obra['generos'] ?? [];
                if (!is_array($generos) || !array_is_list($generos) || array_filter($generos, is_string(...)) !== $generos) {
                    throw new RuntimeException('programación respondió una obra con los géneros mal.');
                }

                return new ObraVotada($obra['id'], $obra['titulo'], $obra['dueno_id'], $obra['elegida'], $obra['votos'], $generos);
            }, $datos['obras']),
            $votantes,
        );
    }

    /** Un momento de la API interna, en ISO 8601 sin zona; null si no es uno. */
    private static function momento(mixed $texto): ?DateTimeImmutable
    {
        $momento = is_string($texto) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $texto) : false;

        return $momento === false ? null : $momento;
    }
}
