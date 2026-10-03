<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Servicios\CorreoYaRegistrado;
use IndieCinema\Cuentas\Servicios\ServicioDeRegistro;
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

    public function __construct(private readonly ServicioDeRegistro $servicio)
    {
    }

    public function mostrar(Peticion $peticion): Respuesta
    {
        return $this->formulario(null, $peticion->consulta('creada') === '1'
            ? 'Ya podés iniciar sesión con tu correo y tu contraseña.'
            : null);
    }

    public function registrar(Peticion $peticion): Respuesta
    {
        $formulario = FormularioDeRegistro::desdeCampos($peticion->cuerpo());
        $datos = $formulario->datos();
        if ($datos === null) {
            return $this->formulario($formulario, estado: 422);
        }

        try {
            $this->servicio->registrar($datos);
        } catch (CorreoYaRegistrado) {
            // Hasta que exista la verificación por correo se avisa en pantalla, aunque así se
            // pueda averiguar qué correos tienen cuenta: después se avisará por mail.
            $formulario->marcarCorreoRegistrado();

            return $this->formulario($formulario, estado: 422);
        }

        // 303 al formulario con el aviso: recargar no vuelve a mandar el registro.
        return $this->redirigir('/registro?creada=1');
    }

    private function formulario(?FormularioDeRegistro $formulario, ?string $exito = null, int $estado = 200): Respuesta
    {
        $datos = [
            'valores' => $formulario?->valores ?? [],
            'errores' => $formulario?->errores ?? [],
        ];
        // La plantilla muestra cada mensaje si la variable existe, aunque sea null: sólo se pasan
        // cuando hay algo que decir.
        if ($formulario?->errores) {
            $datos['error_general'] = 'Revisá los campos marcados.';
        }
        if ($exito !== null) {
            $datos['mensaje_exito'] = $exito;
        }

        return $this->vista(self::PLANTILLA, $datos, $estado);
    }
}
