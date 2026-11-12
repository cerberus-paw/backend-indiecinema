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
 * (GET /interno/funciones/{id}, o GET /interno/funciones?ids=… para varias). Es lo único que
 * funciones sabe de una función: la votación de horario, las reservas y «Mis reservas» la piden
 * acá.
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
     * Las de una página de «Mis reservas» (E4-04.1) en un solo pedido. Las que no existen o cuya
     * sala se dio de baja no vienen; los ids que no tienen forma de id ni se piden.
     *
     * @param list<string> $ids hasta 20: los que entran en una página
     *
     * @return array<string, FuncionDeProgramacion> con el id como clave
     *
     * @throws ExcepcionHttp    503 si programación no responde a tiempo
     * @throws RuntimeException si responde algo que no son funciones: el contrato se rompió
     */
    public function buscarVarias(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, Uuid::esValido(...))));
        if ($ids === []) {
            return [];
        }
        $datos = $this->cliente->get('programacion', '/interno/funciones', ['ids' => implode(',', $ids)]);
        if (!is_array($datos)) {
            throw new RuntimeException('programacion no respondió las funciones a GET /interno/funciones.');
        }

        $funciones = [];
        foreach ($datos as $id => $funcion) {
            $funcion = self::funcion($funcion);
            if ($funcion->id !== $id) {
                throw new RuntimeException('programacion respondió una función con otro id a GET /interno/funciones.');
            }
            $funciones[$funcion->id] = $funcion;
        }

        return $funciones;
    }

    /**
     * Las programadas que empiezan después de $desde y hasta $hasta, de la más próxima a la última
     * (EF-03.3).
     *
     * @return list<FuncionDeProgramacion>
     *
     * @throws ExcepcionHttp    503 si programación no responde a tiempo
     * @throws RuntimeException si responde algo que no son funciones: el contrato se rompió
     */
    public function proximas(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $datos = $this->cliente->get('programacion', '/interno/funciones/proximas', [
            'desde' => $desde->format('Y-m-d\\TH:i:s'),
            'hasta' => $hasta->format('Y-m-d\\TH:i:s'),
        ]);
        if (!is_array($datos) || !array_is_list($datos)) {
            throw new RuntimeException('programacion no respondió las funciones a GET /interno/funciones/proximas.');
        }

        return array_map(self::funcion(...), $datos);
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
            || !is_array($datos['obras_ids'] ?? null) || !array_is_list($datos['obras_ids'])
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
            array_map(strval(...), $datos['obras_ids']),
            // Llegó con el recordatorio (EF-03.3): un programación anterior no la manda.
            is_string($sala['direccion'] ?? null) ? $sala['direccion'] : '',
        );
    }
}
