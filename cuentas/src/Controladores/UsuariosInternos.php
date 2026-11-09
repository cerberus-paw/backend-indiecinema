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
 * /interno/usuarios/{id}), varios usuarios a la vez (GET /interno/usuarios?ids=…) y darle un
 * rol (POST /interno/usuarios/{id}/roles). Los llama programación desde la bandeja de
 * solicitudes de sala y funciones para los nombres de los espectadores en el panel y en el CSV de
 * reservas (IC-464). Qué subsistemas llegan lo declara cada ruta.
 */
final class UsuariosInternos extends Controlador
{
    /** Más que una página del listado de reservas (hasta 100): un pedido más largo es un error de quien llama. */
    public const MAXIMO_POR_PEDIDO = 100;

    public function __construct(private readonly ServicioDeUsuarios $servicio)
    {
    }

    /**
     * Varios usuarios por su id, en un solo pedido (E4-17.2 / IC-464): un objeto JSON indexado por
     * id con el nombre de cada uno. Sólo el nombre, que es lo que muestran el panel y el CSV de
     * reservas: el correo y los roles no le hacen falta a quien llama, y no salen de cuentas.
     */
    public function varios(Peticion $peticion): Respuesta
    {
        $lista = $peticion->consulta('ids');
        if (!is_string($lista) || trim($lista) === '') {
            return $this->json((object) []);
        }

        $partes = array_values(array_unique(array_filter(
            array_map(trim(...), explode(',', $lista)),
            static fn (string $id): bool => $id !== '',
        )));
        if (count($partes) > self::MAXIMO_POR_PEDIDO) {
            return $this->json(['error' => 'Se piden hasta ' . self::MAXIMO_POR_PEDIDO . ' usuarios por vez.'], 400);
        }

        $ids = array_values(array_filter($partes, Uuid::esValido(...)));
        if ($ids === []) {
            return $this->json((object) []);
        }

        return $this->json((object) array_map(
            static fn (UsuarioEnListado $usuario): array => ['id' => $usuario->id, 'nombre' => $usuario->nombre],
            $this->servicio->buscarVarios($ids),
        ));
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
