<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use DateTimeImmutable;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use RuntimeException;

/**
 * Las funciones de programación, pedidas por la API interna con el token de funciones
 * (GET /interno/funciones/{id}). Es lo único que funciones sabe de una función: la votación de
 * horario y las reservas la piden acá.
 */
final class FuncionesDeProgramacion
{
    public function __construct(private readonly ClienteInterno $cliente)
    {
    }

    /**
     * @return ?FuncionDeProgramacion null si no existe, o si su sala se dio de baja
     *
     * @throws ExcepcionHttp    503 si programación no responde a tiempo
     * @throws RuntimeException si responde algo que no es una función: el contrato se rompió
     */
    public function buscar(string $id): ?FuncionDeProgramacion
    {
        // Sin pedir nada si no tiene forma de id: tampoco podría colar otra ruta.
        if (!Uuid::esValido($id)) {
            return null;
        }
        $datos = $this->cliente->get('programacion', "/interno/funciones/{$id}");

        return $datos === null ? null : self::funcion($datos);
    }

    /**
     * Lee la respuesta sin suponer nada: si falta un campo o tiene otro tipo, es que programación
     * cambió lo que manda, y mejor un error claro que una función a medias.
     */
    private static function funcion(mixed $datos): FuncionDeProgramacion
    {
        $sala = is_array($datos) ? ($datos['sala'] ?? null) : null;
        if (
            !is_array($datos) || !is_array($sala)
            || !is_string($datos['id'] ?? null) || !is_string($datos['estado'] ?? null)
            || !(is_string($datos['fecha_hora'] ?? null) || (array_key_exists('fecha_hora', $datos) && $datos['fecha_hora'] === null))
            || !is_numeric($datos['precio_base'] ?? null) || !is_int($datos['cupo'] ?? null)
            || !is_array($datos['titulos'] ?? null) || !array_is_list($datos['titulos'])
            || !is_bool($datos['titulos_elegidos'] ?? null) || !is_string($datos['organizador_id'] ?? null)
            || !is_string($sala['id'] ?? null) || !is_string($sala['nombre'] ?? null)
            || !is_string($sala['localidad'] ?? null) || !is_bool($sala['habilitada'] ?? null)
        ) {
            throw new RuntimeException('programacion no respondió una función a GET /interno/funciones/{id}.');
        }

        return new FuncionDeProgramacion(
            $datos['id'],
            $datos['estado'],
            $datos['fecha_hora'] === null ? null : new DateTimeImmutable($datos['fecha_hora']),
            (float) $datos['precio_base'],
            $datos['cupo'],
            array_map(strval(...), $datos['titulos']),
            $datos['titulos_elegidos'],
            $sala['id'],
            $sala['nombre'],
            $sala['localidad'],
            $sala['habilitada'],
            $datos['organizador_id'],
        );
    }
}
