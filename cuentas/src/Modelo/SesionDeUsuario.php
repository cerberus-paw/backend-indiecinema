<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Modelo;

use IndieCinema\Nucleo\Seguridad\Rol;

/**
 * Una sesión con lo que nginx manda de su usuario en cada pedido. Se lee de una vez, en una sola
 * consulta, porque se valida en cada petición del sitio.
 */
final readonly class SesionDeUsuario
{
    /**
     * @param list<Rol> $roles
     */
    public function __construct(
        public Sesion $sesion,
        public string $nombre,
        public EstadoCuenta $estado,
        public array $roles,
    ) {
    }

    /**
     * El rol más alto: nginx manda uno solo, y cada rol alcanza lo que alcanzan los de abajo. Null
     * si no tiene ninguno, que no debería pasar (el registro le da espectador).
     */
    public function rol(): ?Rol
    {
        $mayor = null;
        foreach ($this->roles as $rol) {
            if ($mayor === null || $rol->alcanza($mayor)) {
                $mayor = $rol;
            }
        }

        return $mayor;
    }
}
