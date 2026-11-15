<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Modelo;

/**
 * Lo denunciado, como lo da el subsistema dueño (EF-07.8): con qué se lo nombra, de quién es (que
 * no lo denuncia ni resuelve sus denuncias) y si está publicado (sólo eso se denuncia).
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
     * Si todavía se puede hacer algo con él: está publicado, o ya se hizo lo que resuelve sus
     * denuncias.
     */
    public function seResuelve(): bool
    {
        return $this->publicado || $this->sancionado;
    }

    public function esDe(string $usuarioId): bool
    {
        return hash_equals($this->duenoId, $usuarioId);
    }
}
