<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

use SensitiveParameter;

/**
 * Lo que devuelve abrir una sesión: el identificador para la cookie, que no se guarda en ningún
 * lado, y la sesión, que tiene el vencimiento de la cookie.
 */
final readonly class SesionAbierta
{
    public function __construct(
        #[SensitiveParameter] public string $identificador,
        public Sesion $sesion,
    ) {
    }
}
