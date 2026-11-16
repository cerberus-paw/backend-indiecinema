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
    /** @var array<string, string|bool> */
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
        $nombre = ValidacionDeCuenta::texto($campos['nombre'] ?? null);
        $this->valores['nombre'] = $nombre;
        $errorNombre = ValidacionDeCuenta::errorDeNombre($nombre);
        if ($errorNombre !== null) {
            $this->errores['nombre'] = $errorNombre;
        }

        $correo = ValidacionDeCuenta::texto($campos['email'] ?? null);
        $this->valores['email'] = $correo;
        $errorCorreo = ValidacionDeCuenta::errorDeCorreo($correo);
        if ($errorCorreo !== null) {
            $this->errores['email'] = $errorCorreo;
        }

        // La contraseña va tal cual: los espacios también cuentan.
        $contrasena = ValidacionDeCuenta::cadena($campos['password'] ?? null);
        $errorContrasena = ValidacionDeCuenta::errorDeContrasena($contrasena);
        if ($errorContrasena !== null) {
            $this->errores['password'] = $errorContrasena;
        }
        $repetida = ValidacionDeCuenta::cadena($campos['password_confirmacion'] ?? null);
        $errorConfirmacion = ValidacionDeCuenta::errorDeConfirmacion($contrasena, $repetida);
        if ($errorConfirmacion !== null) {
            $this->errores['password_confirmacion'] = $errorConfirmacion;
        }

        $this->valores['terminos'] = ($campos['terminos'] ?? null) === '1';
        if (!$this->valores['terminos']) {
            $this->errores['terminos'] = 'Para crear la cuenta tenés que aceptar los términos.';
        }

        if ($this->errores === []) {
            $this->datos = new DatosDeRegistro($nombre, $correo, $contrasena);
        }
    }
}
