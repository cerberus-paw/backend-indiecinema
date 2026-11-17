<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

/**
 * Valida el correo ingresado en /cuenta/recuperar (X-02.2).
 */
final class FormularioDeRecuperacion
{
    /** @var array<string, string> */
    public private(set) array $valores = [];

    /** @var array<string, string> */
    public private(set) array $errores = [];

    public private(set) string $correo = '';

    /**
     * @param array<string, mixed> $campos
     */
    public static function desdeCampos(array $campos): self
    {
        $formulario = new self();
        $formulario->correo = ValidacionDeCuenta::texto($campos['email'] ?? null);
        $formulario->valores['email'] = $formulario->correo;
        $error = ValidacionDeCuenta::errorDeCorreo($formulario->correo);
        if ($error !== null) {
            $formulario->errores['email'] = $error;
        }

        return $formulario;
    }

    public function esValido(): bool
    {
        return $this->errores === [];
    }
}
