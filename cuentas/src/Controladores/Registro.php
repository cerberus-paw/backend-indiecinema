<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;

/**
 * El registro de usuarios, en /cuenta/registro. El núcleo ya controló el token CSRF del POST
 * antes de llegar acá.
 */
final class Registro extends Controlador
{
    private const PLANTILLA = 'registro.html.twig';

    public function mostrar(Peticion $peticion): Respuesta
    {
        return $this->formulario(null);
    }

    public function registrar(Peticion $peticion): Respuesta
    {
        $formulario = FormularioDeRegistro::desdeCampos($peticion->cuerpo());
        if ($formulario->datos() === null) {
            return $this->formulario($formulario, 422);
        }

        // Los datos están bien, pero el alta del usuario todavía no está: llega en la subtarea
        // siguiente. Mientras tanto vuelve el formulario con lo que cargó.
        return $this->formulario($formulario);
    }

    private function formulario(?FormularioDeRegistro $formulario, int $estado = 200): Respuesta
    {
        $datos = [
            'valores' => $formulario?->valores ?? [],
            'errores' => $formulario?->errores ?? [],
        ];
        // La plantilla muestra el mensaje si la variable existe, aunque sea null: sólo se pasa
        // cuando hay errores.
        if ($formulario?->errores) {
            $datos['error_general'] = 'Revisá los campos marcados.';
        }

        return $this->vista(self::PLANTILLA, $datos, $estado);
    }
}
