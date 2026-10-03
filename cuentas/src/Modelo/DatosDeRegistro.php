<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

use SensitiveParameter;

/**
 * Lo que manda el visitante al registrarse, ya validado. La contraseña está en texto plano sólo
 * hasta que el servicio la convierte en hash; SensitiveParameter la deja afuera de las trazas.
 */
final readonly class DatosDeRegistro
{
    public function __construct(
        public string $nombre,
        public string $correo,
        #[SensitiveParameter] public string $contrasena,
    ) {
    }
}
