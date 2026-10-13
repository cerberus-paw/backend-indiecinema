<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Correo;

/**
 * Un correo de texto para mandar con ClienteSmtp. Las direcciones son sólo la dirección
 * (nombre@ejemplo.com), sin nombre ni corchetes.
 */
final readonly class Correo
{
    /**
     * @param ?string $responderA a quién va la respuesta si no es al remitente (Reply-To)
     */
    public function __construct(
        public string $para,
        public string $asunto,
        public string $texto,
        public ?string $responderA = null,
    ) {
    }
}
