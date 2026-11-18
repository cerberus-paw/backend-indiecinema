<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\Sesion;
use IndieCinema\Cuentas\Servicios\ContrasenaActualIncorrecta;
use IndieCinema\Cuentas\Servicios\CorreoYaRegistrado;
use IndieCinema\Cuentas\Servicios\ServicioDePerfil;
use IndieCinema\Cuentas\Servicios\ServicioDeUsuarios;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use LogicException;

/**
 * Edición del nombre, correo y contraseña desde «Mi cuenta» (EF-15.1 / IC-563).
 */
final class Perfil extends Controlador
{
    private const PLANTILLA_DATOS = 'editar_datos.html.twig';
    private const PLANTILLA_CONTRASENA = 'cambiar_contrasena.html.twig';

    public function __construct(
        private readonly ServicioDeUsuarios $usuarios,
        private readonly ServicioDePerfil $perfil,
    ) {
    }

    public function mostrarDatos(Peticion $peticion): Respuesta
    {
        $usuario = $this->usuario($peticion);
        $cuenta = $this->usuarios->buscarPorId($usuario->id) ?? throw ExcepcionHttp::noEncontrada();

        return $this->vistaDatos(
            ['nombre' => $cuenta->nombre, 'email' => $cuenta->correo],
            correoVerificado: $cuenta->correoVerificadoEn !== null,
            guardados: $peticion->consulta('guardados') === '1',
        );
    }

    public function guardarDatos(Peticion $peticion): Respuesta
    {
        $usuario = $this->usuario($peticion);
        $cuenta = $this->usuarios->buscarPorId($usuario->id) ?? throw ExcepcionHttp::noEncontrada();
        $verificado = $cuenta->correoVerificadoEn !== null;
        $formulario = FormularioDePerfil::desdeCampos($peticion->cuerpo());

        if (!$formulario->esValido()) {
            return $this->vistaDatos($formulario->valores, $formulario->errores, $verificado, estado: 422);
        }

        try {
            $cambioCorreo = $this->perfil->actualizarDatos(
                $usuario->id,
                $formulario->nombre,
                $formulario->correo,
                $formulario->contrasenaActual,
                $peticion->cookie(Sesion::COOKIE),
            );
        } catch (ContrasenaActualIncorrecta $error) {
            $formulario->marcarContrasenaIncorrecta($error->getMessage());

            return $this->vistaDatos($formulario->valores, $formulario->errores, $verificado, estado: 422);
        } catch (CorreoYaRegistrado) {
            $formulario->marcarCorreoRegistrado();

            return $this->vistaDatos($formulario->valores, $formulario->errores, $verificado, estado: 422);
        }

        return $this->redirigir('/datos?' . ($cambioCorreo ? 'guardados=1&correo=1' : 'guardados=1'));
    }

    public function mostrarContrasena(Peticion $peticion): Respuesta
    {
        $this->usuario($peticion);

        return $this->vista(self::PLANTILLA_CONTRASENA, [
            'errores' => [],
            'actualizada' => $peticion->consulta('actualizada') === '1',
        ]);
    }

    public function guardarContrasena(Peticion $peticion): Respuesta
    {
        $usuario = $this->usuario($peticion);
        $formulario = FormularioDeCambioDeContrasena::desdeCampos($peticion->cuerpo());
        if (!$formulario->esValido()) {
            return $this->vista(self::PLANTILLA_CONTRASENA, [
                'errores' => $formulario->errores,
                'actualizada' => false,
            ], 422);
        }

        try {
            $this->perfil->cambiarContrasena(
                $usuario->id,
                $formulario->contrasenaActual,
                $formulario->contrasenaNueva,
                $peticion->cookie(Sesion::COOKIE),
            );
        } catch (ContrasenaActualIncorrecta $error) {
            $formulario->marcarContrasenaIncorrecta($error->getMessage());

            return $this->vista(self::PLANTILLA_CONTRASENA, [
                'errores' => $formulario->errores,
                'actualizada' => false,
            ], 422);
        }

        return $this->redirigir('/contrasena?actualizada=1');
    }

    /**
     * @param array<string, string> $valores
     * @param array<string, string> $errores
     */
    private function vistaDatos(
        array $valores,
        array $errores = [],
        bool $correoVerificado = true,
        bool $guardados = false,
        int $estado = 200,
    ): Respuesta {
        return $this->vista(self::PLANTILLA_DATOS, [
            'valores' => $valores,
            'errores' => $errores,
            'correo_verificado' => $correoVerificado,
            'guardados' => $guardados,
        ], $estado);
    }

    private function usuario(Peticion $peticion): \IndieCinema\Nucleo\Seguridad\Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide sesión.');
    }
}
