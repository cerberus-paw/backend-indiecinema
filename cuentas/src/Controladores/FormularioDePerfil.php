<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

/**
 * Valida la edición de nombre y correo en /cuenta/datos (EF-15.1 / IC-563).
 */
final class FormularioDePerfil
{
    /** @var array<string, string> */
    public private(set) array $valores = [];

    /** @var array<string, string> */
    public private(set) array $errores = [];

    public private(set) string $nombre = '';
    public private(set) string $correo = '';
    public private(set) string $contrasenaActual = '';

    /**
     * @param array<string, mixed> $campos
     */
    public static function desdeCampos(array $campos): self
    {
        $formulario = new self();
        $nombre = ValidacionDeCuenta::texto($campos['nombre'] ?? null);
        $correo = ValidacionDeCuenta::texto($campos['email'] ?? null);
        $formulario->valores = ['nombre' => $nombre, 'email' => $correo];

        $errorNombre = ValidacionDeCuenta::errorDeNombre($nombre);
        if ($errorNombre !== null) {
            $formulario->errores['nombre'] = $errorNombre;
        }
        $errorCorreo = ValidacionDeCuenta::errorDeCorreo($correo);
        if ($errorCorreo !== null) {
            $formulario->errores['email'] = $errorCorreo;
        }

        $formulario->contrasenaActual = ValidacionDeCuenta::cadena($campos['contrasena_actual'] ?? null);
        if ($formulario->errores === []) {
            $formulario->nombre = $nombre;
            $formulario->correo = $correo;
        }

        return $formulario;
    }

    public function esValido(): bool
    {
        return $this->errores === [];
    }

    public function marcarCorreoRegistrado(): void
    {
        $this->errores['email'] = 'Ya hay una cuenta con este correo.';
    }

    public function marcarContrasenaIncorrecta(string $mensaje): void
    {
        $this->errores['contrasena_actual'] = $mensaje;
    }
}
