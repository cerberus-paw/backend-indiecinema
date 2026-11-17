<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

/**
 * Valida la nueva contraseña y su confirmación en /cuenta/recuperar/nueva (X-02.2).
 */
final class FormularioDeNuevaContrasena
{
    /** @var array<string, string> */
    public private(set) array $errores = [];

    public private(set) string $contrasena = '';

    /**
     * @param array<string, mixed> $campos
     */
    public static function desdeCampos(array $campos): self
    {
        $formulario = new self();
        $contrasena = ValidacionDeCuenta::cadena($campos['password'] ?? null);
        $error = ValidacionDeCuenta::errorDeContrasena($contrasena);
        if ($error !== null) {
            $formulario->errores['password'] = $error;
        }
        $repetida = ValidacionDeCuenta::cadena($campos['password_confirmacion'] ?? null);
        $errorConfirmacion = ValidacionDeCuenta::errorDeConfirmacion($contrasena, $repetida);
        if ($errorConfirmacion !== null) {
            $formulario->errores['password_confirmacion'] = $errorConfirmacion;
        }
        if ($formulario->errores === []) {
            $formulario->contrasena = $contrasena;
        }

        return $formulario;
    }

    public function esValido(): bool
    {
        return $this->errores === [];
    }
}
