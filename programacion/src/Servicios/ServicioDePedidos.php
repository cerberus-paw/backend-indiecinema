<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDePedido;
use IndieCinema\Programacion\Modelo\PedidoDeTitulo;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Repositorios\RepositorioDePedidos;
use Psr\Log\LoggerInterface;

/**
 * Alta de pedidos de títulos ausentes en el catálogo y adhesiones de los espectadores (EF-11.1).
 */
final class ServicioDePedidos
{
    public const UMBRAL_POR_OMISION = 5;

    public function __construct(
        private readonly RepositorioDePedidos $pedidos,
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    public function umbral(): int
    {
        return max(1, (int) $this->configuracion->obtener('pedidos.umbral', self::UMBRAL_POR_OMISION));
    }

    /**
     * @throws ExcepcionHttp 404 si el identificador no es UUID o el pedido no existe
     */
    public function buscar(string $id, ?Usuario $usuario = null): PedidoDeTitulo
    {
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $this->pedidos->buscar($id, $usuario?->id) ?? throw ExcepcionHttp::noEncontrada();
    }

    /**
     * Si ya existe un pedido parecido (mismo título sin distinguir tildes ni mayúsculas y mismo
     * año), no duplica sino que lo devuelve para ofrecer sumarse; si no, crea el pedido con la
     * adhesión inicial del espectador.
     *
     * @return array{creado: bool, pedido: ?PedidoDeTitulo, parecidos: list<PedidoDeTitulo>}
     */
    public function pedir(Usuario $usuario, DatosDePedido $datos): array
    {
        $parecidos = $this->pedidos->buscarParecidos($datos->titulo, $datos->anio, $usuario->id);
        if ($parecidos !== []) {
            return ['creado' => false, 'pedido' => null, 'parecidos' => $parecidos];
        }

        $pedido = $this->pedidos->crear(Uuid::nuevo(), $datos, $usuario->id, $this->umbral());
        $this->log->info('El usuario {usuario} registró el pedido {pedido}.', [
            'pedido' => $pedido->id,
            'usuario' => $usuario->id,
        ]);

        return ['creado' => true, 'pedido' => $pedido, 'parecidos' => []];
    }

    /**
     * @throws ExcepcionHttp   404 si el pedido no existe o no es UUID
     * @throws ReglaIncumplida si el usuario ya estaba adherido o el pedido ya fue tomado
     */
    public function sumarse(Usuario $usuario, string $pedidoId): PedidoDeTitulo
    {
        if (!Uuid::esValido($pedidoId)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $this->pedidos->sumarAdhesion($pedidoId, $usuario->id, $this->umbral());
    }

    /**
     * @throws ExcepcionHttp   404 si el pedido no existe o no es UUID
     * @throws ReglaIncumplida si el pedido ya fue tomado
     */
    public function dejarDePedirlo(Usuario $usuario, string $pedidoId): PedidoDeTitulo
    {
        if (!Uuid::esValido($pedidoId)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $this->pedidos->quitarAdhesion($pedidoId, $usuario->id);
    }
}
