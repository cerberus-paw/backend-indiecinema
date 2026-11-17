<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

/**
 * Reglas comunes de correo y contraseña para registro, recuperación y edición de cuenta.
 */
final class ValidacionDeCuenta
{
    public const MAXIMO_NOMBRE = 100;
    public const MAXIMO_CORREO = 254;
    public const MINIMO_CONTRASENA = 8;
    public const MAXIMO_CONTRASENA = 128;

    private function __construct()
    {
    }

    public static function errorDeNombre(string $nombre): ?string
    {
        if ($nombre === '') {
            return 'Falta el nombre.';
        }
        if (mb_strlen($nombre) > self::MAXIMO_NOMBRE) {
            return 'El nombre puede tener hasta ' . self::MAXIMO_NOMBRE . ' caracteres.';
        }

        return null;
    }

    public static function errorDeCorreo(string $correo): ?string
    {
        if ($correo === '') {
            return 'Falta el correo.';
        }
        // Sin FILTER_FLAG_EMAIL_UNICODE: sólo ASCII, como la columna usuario.correo.
        if (strlen($correo) > self::MAXIMO_CORREO || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            return 'Escribí un correo válido, como nombre@ejemplo.com.';
        }

        return null;
    }

    public static function errorDeContrasena(string $contrasena): ?string
    {
        if ($contrasena === '') {
            return 'Falta la contraseña.';
        }
        $largo = mb_strlen($contrasena);
        if ($largo < self::MINIMO_CONTRASENA) {
            return 'La contraseña tiene que tener al menos ' . self::MINIMO_CONTRASENA . ' caracteres.';
        }
        if ($largo > self::MAXIMO_CONTRASENA) {
            return 'La contraseña puede tener hasta ' . self::MAXIMO_CONTRASENA . ' caracteres.';
        }
        if (preg_match('/\p{L}/u', $contrasena) !== 1 || preg_match('/\p{N}/u', $contrasena) !== 1) {
            return 'La contraseña tiene que tener letras y números.';
        }

        return null;
    }

    public static function errorDeConfirmacion(string $contrasena, string $repetida): ?string
    {
        if ($repetida === '') {
            return 'Repetí la contraseña.';
        }
        if (!hash_equals($contrasena, $repetida)) {
            return 'Las contraseñas no coinciden.';
        }

        return null;
    }

    /**
     * El texto en una línea, sin espacios de más ni caracteres de control.
     */
    public static function texto(mixed $valor): string
    {
        return trim((string) preg_replace('/\p{Cc}/u', '', (string) preg_replace('/\s+/u', ' ', self::cadena($valor))));
    }

    /**
     * El valor si es texto UTF-8; si no, vacío.
     */
    public static function cadena(mixed $valor): string
    {
        return is_string($valor) && mb_check_encoding($valor, 'UTF-8') ? $valor : '';
    }
}
