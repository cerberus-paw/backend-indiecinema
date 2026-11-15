<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

use RuntimeException;

/**
 * Una obra del catálogo, que es de programación, como la devuelve su API interna (EF-07.7): lo
 * que el moderador revisa antes de aprobarla o rechazarla.
 */
final readonly class ObraDeProgramacion
{
    /**
     * @param list<string> $generos     los nombres
     * @param string       $estado      pendiente, aprobada o rechazada
     * @param ?string      $creadaEn    en la hora del sitio, como la manda programación
     */
    public function __construct(
        public string $id,
        public string $titulo,
        public string $tipo,
        public int $anio,
        public string $director,
        public int $duracion,
        public string $sinopsis,
        public array $generos,
        public string $origenCopia,
        public bool $afiche,
        public string $duenoId,
        public string $estado,
        public ?string $motivo,
        public ?string $creadaEn,
    ) {
    }

    /**
     * Lee la respuesta sin suponer nada: si falta un campo, programación cambió lo que manda.
     *
     * @throws RuntimeException
     */
    public static function desdeJson(mixed $datos): self
    {
        if (
            !is_array($datos) || !is_string($datos['id'] ?? null) || !is_string($datos['titulo'] ?? null)
            || !is_string($datos['tipo'] ?? null) || !is_int($datos['anio'] ?? null) || !is_string($datos['director'] ?? null)
            || !is_int($datos['duracion'] ?? null) || !is_string($datos['sinopsis'] ?? null) || !is_array($datos['generos'] ?? null)
            || !is_string($datos['origen_copia'] ?? null) || !is_bool($datos['afiche'] ?? null) || !is_string($datos['dueno_id'] ?? null)
            || !is_string($datos['estado'] ?? null)
        ) {
            throw new RuntimeException('programación no respondió una obra a su API interna.');
        }

        return new self(
            $datos['id'],
            $datos['titulo'],
            $datos['tipo'],
            $datos['anio'],
            $datos['director'],
            $datos['duracion'],
            $datos['sinopsis'],
            array_values(array_map(strval(...), $datos['generos'])),
            $datos['origen_copia'],
            $datos['afiche'],
            $datos['dueno_id'],
            $datos['estado'],
            is_string($datos['motivo'] ?? null) ? $datos['motivo'] : null,
            is_string($datos['creada_en'] ?? null) ? $datos['creada_en'] : null,
        );
    }

    public function estaPendiente(): bool
    {
        return $this->estado === 'pendiente';
    }
}
