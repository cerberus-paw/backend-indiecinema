<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * /cuenta/solicitud: un espectador pide ser organizador para abrir su sala. Sigue el wireframe
 * «solicitud-de-sala» de la E2, recortado a lo que pide la E3: los datos de la sala, el contacto,
 * la presentación y las declaraciones. La ruta pide sesión, el núcleo ya controló el token CSRF
 * del POST y nginx limita cuántos se mandan por minuto.
 */
final class SolicitudDeOrganizador extends Controlador
{
    private const PLANTILLA = 'solicitud.html.twig';

    public function mostrar(Peticion $peticion): Respuesta
    {
        return $this->formulario(self::usuario($peticion), null, $peticion->consulta('enviada') === '1');
    }

    public function enviar(Peticion $peticion): Respuesta
    {
        $usuario = self::usuario($peticion);
        // Ya lo es, o tiene un rol que lo incluye: no hay nada que pedir.
        if ($usuario->alcanza(Rol::Organizador)) {
            return $this->formulario($usuario, estado: 409);
        }

        $formulario = FormularioDeSolicitud::desdeCampos($peticion->cuerpo());
        if ($formulario->datos() === null) {
            return $this->formulario($usuario, $formulario, estado: 422);
        }

        // 303 a la pantalla con el aviso: recargar no vuelve a mandar la solicitud.
        return $this->redirigir('/solicitud?enviada=1');
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide sesión: sin ella no llega acá.');
    }

    /**
     * La plantilla arma los atributos de cada campo (required, minlength, maxlength y pattern) con
     * las mismas reglas que valida FormularioDeSolicitud.
     */
    private function formulario(Usuario $usuario, ?FormularioDeSolicitud $formulario = null, bool $enviada = false, int $estado = 200): Respuesta
    {
        $datos = [
            'reglas' => FormularioDeSolicitud::REGLAS,
            'valores' => $formulario?->valores ?? [],
            'errores' => $formulario?->errores ?? [],
            'ya_es_organizador' => $usuario->alcanza(Rol::Organizador),
        ];
        // La plantilla muestra cada mensaje si la variable existe: sólo se pasan cuando hay algo
        // que decir.
        if ($formulario?->errores) {
            $datos['error_general'] = 'Revisá los campos marcados.';
        }
        if ($enviada) {
            $datos['mensaje_exito'] = 'Recibimos tu solicitud. El equipo la revisa y te responde al correo de contacto que dejaste.';
        }

        return $this->vista(self::PLANTILLA, $datos, $estado);
    }
}
