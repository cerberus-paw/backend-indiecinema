<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

/**
 * Valida el cambio de contraseña en /cuenta/contrasena (EF-15.1 / IC-563).
 */
final class FormularioDeCambioDeContrasena
{
    /** @var array<string, string> */
    public private(set) array $errores = [];

    public private(set) string $contrasenaActual = '';
    public private(set) string $contrasenaNueva = '';

    /**
     * @param array<string, mixed> $campos
     */
    public static function desdeCampos(array $campos): self
    {
        $formulario = new self();
        $actual = ValidacionDeCuenta::cadena($campos['contrasena_actual'] ?? null);
        if ($actual === '') {
            $formulario->errores['contrasena_actual'] = 'Falta tu contraseña actual.';
        }

        $nueva = FormularioDeNuevaContrasena::desdeCampos($campos);
        $formulario->errores = [...$formulario->errores, ...$nueva->errores];

        if ($formulario->errores === []) {
            $formulario->contrasenaActual = $actual;
            $formulario->contrasenaNueva = $nueva->contrasena;
        }

        return $formulario;
    }

    public function esValido(): bool
    {
        return $this->errores === [];
    }

    public function marcarContrasenaIncorrecta(string $mensaje): void
    {
        $this->errores['contrasena_actual'] = $mensaje;
    }
}
