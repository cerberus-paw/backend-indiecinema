<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\EstadoPedido;
use IndieCinema\Programacion\Modelo\PedidoDeTitulo;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Repositorios\RepositorioDePedidos;
use IndieCinema\Programacion\Servicios\ServicioDePedidos;
use LogicException;

/**
 * Alta de pedidos de títulos ausentes y adhesiones (EF-11.1 / IC-576).
 */
final class Pedidos extends Controlador
{
    private const PLANTILLA_NUEVO = 'pedir_titulo.html.twig';

    public function __construct(private readonly ServicioDePedidos $servicio)
    {
    }

    /** GET /pedidos/nuevo */
    public function nuevo(Peticion $peticion): Respuesta
    {
        $usuario = self::espectador($peticion);
        $pedidoQuery = $peticion->consulta('pedido');
        $pedidoActual = is_string($pedidoQuery) && $pedidoQuery !== ''
            ? $this->servicio->buscar($pedidoQuery, $usuario)
            : null;

        $mensajeExito = match (true) {
            $peticion->consulta('creado') === '1' => 'Tu pedido quedó registrado con tu adhesión.',
            $peticion->consulta('sumado') === '1' => 'Te sumaste al pedido.',
            $peticion->consulta('quitado') === '1' => 'Quitamos tu adhesión al pedido.',
            default => null,
        };
        $aviso = $peticion->consulta('ya_sumado') === '1' ? RepositorioDePedidos::MENSAJE_YA_SUMADO : null;

        return $this->pantalla(
            pedidoActual: $pedidoActual,
            mensajeExito: $mensajeExito,
            aviso: $aviso,
        );
    }

    /** POST /pedidos/nuevo */
    public function crear(Peticion $peticion): Respuesta
    {
        $usuario = self::espectador($peticion);
        $formulario = FormularioDePedido::desdeCampos($peticion->cuerpo());
        $datos = $formulario->datos();
        if ($datos === null) {
            return $this->pantalla(formulario: $formulario, estado: 422);
        }

        $resultado = $this->servicio->pedir($usuario, $datos);

        if (!$resultado['creado']) {
            return $this->pantalla(
                formulario: $formulario,
                parecidos: $resultado['parecidos'],
                estado: 200,
            );
        }

        $pedido = $resultado['pedido'] ?? throw new LogicException('El pedido creado no puede ser null.');

        return $this->redirigir("/pedidos/nuevo?pedido={$pedido->id}&creado=1");
    }

    /** POST /pedidos/{id}/sumarme */
    public function sumarme(Peticion $peticion): Respuesta
    {
        if ((string) $peticion->campo('quitar') === '1') {
            return $this->quitar($peticion);
        }

        $usuario = self::espectador($peticion);
        $id = (string) $peticion->parametro('id');

        try {
            $pedido = $this->servicio->sumarse($usuario, $id);
        } catch (ReglaIncumplida $regla) {
            if ($peticion->aceptaJson()) {
                return $this->json(['error' => $regla->getMessage(), 'aviso' => $regla->getMessage()], 409);
            }

            return $this->pantalla(
                pedidoActual: $this->servicio->buscar($id, $usuario),
                aviso: $regla->getMessage(),
                estado: 200,
            );
        }

        if ($peticion->aceptaJson()) {
            return $this->json([
                'ok' => true,
                'pedido_id' => $pedido->id,
                'adherido' => true,
                'adhesiones' => $pedido->adhesiones,
                'estado' => $pedido->estado->value,
            ]);
        }

        return $this->redirigir("/pedidos/nuevo?pedido={$pedido->id}&sumado=1");
    }

    /** POST /pedidos/{id}/quitar */
    public function quitar(Peticion $peticion): Respuesta
    {
        $usuario = self::espectador($peticion);
        $id = (string) $peticion->parametro('id');

        try {
            $pedido = $this->servicio->dejarDePedirlo($usuario, $id);
        } catch (ReglaIncumplida $regla) {
            if ($peticion->aceptaJson()) {
                return $this->json(['error' => $regla->getMessage(), 'aviso' => $regla->getMessage()], 409);
            }

            return $this->pantalla(
                pedidoActual: $this->servicio->buscar($id, $usuario),
                aviso: $regla->getMessage(),
                estado: 200,
            );
        }

        if ($peticion->aceptaJson()) {
            return $this->json([
                'ok' => true,
                'pedido_id' => $pedido->id,
                'adherido' => false,
                'adhesiones' => $pedido->adhesiones,
                'estado' => $pedido->estado->value,
            ]);
        }

        return $this->redirigir("/pedidos/nuevo?pedido={$pedido->id}&quitado=1");
    }

    /**
     * @param list<PedidoDeTitulo> $parecidos
     */
    private function pantalla(
        ?FormularioDePedido $formulario = null,
        array $parecidos = [],
        ?PedidoDeTitulo $pedidoActual = null,
        ?string $errorGeneral = null,
        ?string $mensajeExito = null,
        ?string $aviso = null,
        int $estado = 200,
    ): Respuesta {
        return $this->vista(self::PLANTILLA_NUEVO, [
            'reglas' => FormularioDePedido::reglas(),
            'valores' => $formulario === null ? [] : $formulario->valores,
            'errores' => $formulario === null ? [] : $formulario->errores,
            'parecidos' => array_map(self::datosDePedido(...), $parecidos),
            'pedido_actual' => $pedidoActual === null ? null : self::datosDePedido($pedidoActual),
            'error_general' => $errorGeneral,
            'mensaje_exito' => $mensajeExito,
            'aviso' => $aviso,
        ], $estado);
    }

    /**
     * @return array<string, mixed>
     */
    private static function datosDePedido(PedidoDeTitulo $pedido): array
    {
        return [
            'id' => $pedido->id,
            'titulo' => $pedido->titulo,
            'anio' => $pedido->anio,
            'director' => $pedido->director,
            'estado' => $pedido->estado->value,
            'estado_etiqueta' => $pedido->estado->etiqueta(),
            'es_activo' => $pedido->estado === EstadoPedido::Activo,
            'adhesiones' => $pedido->adhesiones,
            'adherido' => $pedido->adheridoPorUsuario,
            'obra_id' => $pedido->obraId,
            'obra_titulo' => $pedido->obraTitulo,
            'url_obra' => $pedido->obraId === null ? null : "/obras/{$pedido->obraId}",
        ];
    }

    private static function espectador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('Ruta de espectador sin usuario.');
    }
}
