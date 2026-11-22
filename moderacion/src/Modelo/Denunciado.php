<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * Lo denunciado, como lo da el subsistema dueño (EF-07.8): con qué se lo nombra, de quién es (que
 * no lo denuncia ni resuelve sus denuncias) y si está publicado (sólo eso se denuncia). Mientras
 * exista, sus denuncias se resuelven aunque ya no esté publicado (retirado, suspendido por otra
 * causa): Q15.
 */
final readonly class Denunciado
{
    /**
     * @param string               $url        la ficha del sitio donde se ve
     * @param array<string, mixed> $datos      lo que ve el moderador, según el tipo
     * @param bool                 $sancionado si ya se hizo lo que resuelve sus denuncias (la obra
     *                                         rechazada, la sala suspendida por denuncia, el
     *                                         comentario oculto): se cierran sin repetirlo (Q14)
     */
    public function __construct(
        public TipoDenuncia $tipo,
        public string $id,
        public string $titulo,
        public string $duenoId,
        public bool $publicado,
        public string $url,
        public array $datos = [],
        public bool $sancionado = false,
    ) {
    }

    /**
     * La sala donde pasa (EF-22.11): la misma sala, o la de la función. Null en obras y
     * comentarios.
     */
    public function salaId(): ?string
    {
        return match ($this->tipo) {
            TipoDenuncia::Sala => $this->id,
            TipoDenuncia::Funcion => (string) $this->datos['sala_id'],
            default => null,
        };
    }

    public function esDe(string $usuarioId): bool
    {
        return hash_equals($this->duenoId, $usuarioId);
    }
}
