<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

/**
 * Valida en el servidor lo que manda el formulario de ingreso: que estén el correo y la
 * contraseña. Si son de una cuenta lo decide el servicio.
 *
 * Los nombres de los campos son los de la plantilla cuentas/ingresar del paquete front.
 */
final class FormularioDeIngreso
{
    /** @var array<string, string> lo que mandó, para volver a mostrarlo; la contraseña no */
    public private(set) array $valores = [];

    /** @var array<string, string> campo => qué está mal */
    public private(set) array $errores = [];

    public private(set) string $correo = '';

    public private(set) string $contrasena = '';

    /**
     * @param array<string, mixed> $campos el cuerpo del POST
     */
    public static function desdeCampos(array $campos): self
    {
        $formulario = new self();
        $formulario->correo = trim(self::cadena($campos['email'] ?? null));
        $formulario->valores['email'] = $formulario->correo;
        if ($formulario->correo === '') {
            $formulario->errores['email'] = 'Falta el correo.';
        }
        // La contraseña va tal cual, como se registró.
        $formulario->contrasena = self::cadena($campos['password'] ?? null);
        if ($formulario->contrasena === '') {
            $formulario->errores['password'] = 'Falta la contraseña.';
        }

        return $formulario;
    }

    public function esValido(): bool
    {
        return $this->errores === [];
    }

    /**
     * El valor si es texto UTF-8; si no, vacío. Un campo que llega como arreglo se toma como vacío.
     */
    private static function cadena(mixed $valor): string
    {
        return is_string($valor) && mb_check_encoding($valor, 'UTF-8') ? $valor : '';
    }
}
