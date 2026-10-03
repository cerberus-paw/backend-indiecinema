<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\DatosDeRegistro;

/**
 * Valida en el servidor lo que manda el formulario de registro. La validación del navegador no
 * cuenta: cualquiera puede mandar el POST a mano.
 *
 * Los nombres de los campos son los de la plantilla cuentas/registro del paquete front, y se
 * valida todo lo que la plantilla pide, términos incluidos.
 */
final class FormularioDeRegistro
{
    /** Como la columna usuario.nombre. */
    private const MAXIMO_NOMBRE = 100;
    /** El largo máximo de una dirección (RFC 5321), como la columna usuario.correo. */
    private const MAXIMO_CORREO = 254;
    /** Lo que dice la ayuda del formulario. */
    private const MINIMO_CONTRASENA = 8;
    /** Argon2id no tiene límite, pero no hace falta aceptar cualquier tamaño. */
    private const MAXIMO_CONTRASENA = 128;

    /**
     * @var array<string, string|bool> lo que mandó, para volver a mostrarlo si hay errores. Las
     *                                 contraseñas no: no vuelven al navegador.
     */
    public private(set) array $valores = [];

    /** @var array<string, string> campo => qué está mal */
    public private(set) array $errores = [];

    private ?DatosDeRegistro $datos = null;

    /**
     * @param array<string, mixed> $campos el cuerpo del POST
     */
    public static function desdeCampos(array $campos): self
    {
        $formulario = new self();
        $formulario->validar($campos);

        return $formulario;
    }

    /**
     * Los datos validados, o null si hay errores.
     */
    public function datos(): ?DatosDeRegistro
    {
        return $this->datos;
    }

    /**
     * El correo estaba bien escrito pero ya es de otra cuenta: lo dice la base al guardar.
     */
    public function marcarCorreoRegistrado(): void
    {
        $this->errores['email'] = 'Ya hay una cuenta con este correo.';
        $this->datos = null;
    }

    /**
     * @param array<string, mixed> $campos
     */
    private function validar(array $campos): void
    {
        $nombre = self::texto($campos['nombre'] ?? null);
        $this->valores['nombre'] = $nombre;
        if ($nombre === '') {
            $this->errores['nombre'] = 'Falta el nombre.';
        } elseif (mb_strlen($nombre) > self::MAXIMO_NOMBRE) {
            $this->errores['nombre'] = 'El nombre puede tener hasta ' . self::MAXIMO_NOMBRE . ' caracteres.';
        }

        $correo = self::texto($campos['email'] ?? null);
        $this->valores['email'] = $correo;
        if ($correo === '') {
            $this->errores['email'] = 'Falta el correo.';
        } elseif (strlen($correo) > self::MAXIMO_CORREO || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
            // Sin FILTER_FLAG_EMAIL_UNICODE: sólo ASCII, como la columna usuario.correo.
            $this->errores['email'] = 'Escribí un correo válido, como nombre@ejemplo.com.';
        }

        // La contraseña va tal cual: los espacios también cuentan.
        $contrasena = self::cadena($campos['password'] ?? null);
        $error = self::errorDeContrasena($contrasena);
        if ($error !== null) {
            $this->errores['password'] = $error;
        }
        $repetida = self::cadena($campos['password_confirmacion'] ?? null);
        if ($repetida === '') {
            $this->errores['password_confirmacion'] = 'Repetí la contraseña.';
        } elseif (!hash_equals($contrasena, $repetida)) {
            $this->errores['password_confirmacion'] = 'Las contraseñas no coinciden.';
        }

        $this->valores['terminos'] = ($campos['terminos'] ?? null) === '1';
        if (!$this->valores['terminos']) {
            $this->errores['terminos'] = 'Para crear la cuenta tenés que aceptar los términos.';
        }

        if ($this->errores === []) {
            $this->datos = new DatosDeRegistro($nombre, $correo, $contrasena);
        }
    }

    private static function errorDeContrasena(string $contrasena): ?string
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

    /**
     * El texto en una línea, sin espacios de más ni caracteres de control, que no se ven y no
     * tienen nada que hacer en estos campos.
     */
    private static function texto(mixed $valor): string
    {
        return trim((string) preg_replace('/\p{Cc}/u', '', (string) preg_replace('/\s+/u', ' ', self::cadena($valor))));
    }

    /**
     * El valor si es texto UTF-8; si no, vacío. Un campo que llega como arreglo (nombre[]=…) se
     * toma como vacío.
     */
    private static function cadena(mixed $valor): string
    {
        return is_string($valor) && mb_check_encoding($valor, 'UTF-8') ? $valor : '';
    }
}
