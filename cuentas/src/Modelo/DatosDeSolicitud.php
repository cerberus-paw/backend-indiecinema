<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

/**
 * Lo que manda un espectador para que le den el rol de organizador, ya validado. El teléfono es
 * optativo: null si no lo dejó.
 */
final readonly class DatosDeSolicitud
{
    public function __construct(
        public string $sala,
        public string $localidad,
        public string $correo,
        public ?string $telefono,
        public string $presentacion,
    ) {
    }
}
