<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\UsuarioEnListado;
use IndieCinema\Cuentas\Servicios\ServicioDeUsuarios;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Uuid;

/**
 * Los usuarios para los otros subsistemas, como los define la E2: quién es (GET
 * /interno/usuarios/{id}) y darle un rol (POST /interno/usuarios/{id}/roles). Los llama
 * programación desde la bandeja de solicitudes de sala: muestra quién la mandó y, al aprobarla,
 * le pide el rol de organizador. Qué subsistemas llegan lo declara cada ruta.
 */
final class UsuariosInternos extends Controlador
{
    public function __construct(private readonly ServicioDeUsuarios $servicio)
    {
    }

    /**
     * Lo que muestra la bandeja de quién mandó la solicitud. La fecha va en ISO 8601 sin zona, en
     * UTC como la guarda cuentas.
     *
     * @throws ExcepcionHttp 404 si no existe
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $usuario = $this->servicio->buscarEnListado((string) $peticion->parametro('id')) ?? throw ExcepcionHttp::noEncontrada();

        return $this->json(self::datos($usuario));
    }

    /**
     * Sólo el de organizador, el único que se gana con una solicitud: el de moderador y el de
     * administrador los da el administrador en cuentas. Si ya lo tiene no cambia nada, así el que
     * llama puede reintentar.
     *
     * @throws ExcepcionHttp 400 si no pide el rol de organizador o no dice quién lo da; 404 si el
     *                       usuario no existe
     */
    public function darRol(Peticion $peticion): Respuesta
    {
        if ($peticion->campo('rol') !== Rol::Organizador->value) {
            throw ExcepcionHttp::solicitudInvalida('Por la API interna sólo se da el rol de organizador.');
        }
        // Para el registro de auditoría: quién lo dio, aunque llegue sin su sesión.
        $otorgadoPor = $peticion->campo('otorgado_por');
        if (!is_string($otorgadoPor) || !Uuid::esValido($otorgadoPor)) {
            throw ExcepcionHttp::solicitudInvalida('Falta el id de quién da el rol, en «otorgado_por».');
        }

        $usuario = $this->servicio->darOrganizador((string) $peticion->parametro('id'), $otorgadoPor);

        return $this->json(self::datos($this->servicio->buscarEnListado($usuario->id) ?? $usuario));
    }

    /**
     * @return array<string, mixed>
     */
    private static function datos(UsuarioEnListado $usuario): array
    {
        return [
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'correo' => $usuario->correo,
            'estado' => $usuario->estado->value,
            'roles' => array_map(static fn (Rol $rol): string => $rol->value, $usuario->roles),
            'creado_en' => $usuario->creadoEn->format('Y-m-d\TH:i:s'),
        ];
    }
}
