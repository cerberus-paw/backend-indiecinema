<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo\Seguridad;

/**
 * Quien hace la petición, tal como lo informó nginx después de validar la sesión con cuentas.
 *
 * No es la entidad Usuario de cuentas: los demás subsistemas no tienen tabla de usuarios (E2,
 * sección 3), sólo el id, el rol y el nombre para mostrar. Sin sesión no hay Usuario: la
 * petición lleva null.
 */
final readonly class Usuario
{
    /**
     * @param ?string $nombre el nombre público, sólo para mostrar; null si nginx no lo mandó
     */
    public function __construct(
        public string $id,
        public Rol $rol,
        public ?string $nombre = null,
    ) {
    }

    /**
     * Acepta el rol como texto para poder usarlo en las plantillas: «usuario.alcanza('organizador')».
     * Un nombre de rol mal escrito falla en lugar de esconder el menú en silencio.
     */
    public function alcanza(Rol|string $minimo): bool
    {
        return $this->rol->alcanza($minimo instanceof Rol ? $minimo : Rol::from($minimo));
    }
}
