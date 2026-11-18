<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\EstadoObra;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\PedidoDeTitulo;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Servicios\ServicioDeObras;
use IndieCinema\Programacion\Servicios\ServicioDePedidos;
use LogicException;

/**
 * «Pedidos para programar» en el panel del organizador (EF-11.3 / IC-578): lista los pedidos
 * activos disponibles (respetando las 48 horas de prioridad para salas mejor calificadas) y permite
 * tomarlos con una obra ya cargada o desde el alta precargada.
 */
final class PedidosDelOrganizador extends Controlador
{
    private const PLANTILLA = 'pedidos_organizador.html.twig';

    public function __construct(
        private readonly ServicioDePedidos $pedidos,
        private readonly ServicioDeObras $obras,
    ) {
    }

    /** GET /organizador/pedidos */
    public function listar(Peticion $peticion): Respuesta
    {
        return $this->pantalla(
            self::organizador($peticion),
            tomado: $peticion->consulta('tomado') === '1',
        );
    }

    /** POST /organizador/pedidos/{id}/tomar */
    public function tomar(Peticion $peticion): Respuesta
    {
        $organizador = self::organizador($peticion);
        $pedidoId = (string) $peticion->parametro('id');
        $obraId = trim((string) ($peticion->campo('obra_id') ?? ''));

        if ($obraId === '') {
            return $this->pantalla(
                $organizador,
                errorGeneral: 'Elegí una obra de tu catálogo o cargá una nueva para tomar el pedido.',
                estado: 422,
            );
        }

        $obra = $this->obras->paraEditar($obraId, $organizador);

        try {
            $this->pedidos->tomarConObra($organizador, $pedidoId, $obra);
        } catch (ReglaIncumplida $regla) {
            return $this->pantalla($organizador, errorGeneral: $regla->getMessage(), estado: 422);
        }

        return $this->redirigir('/organizador/pedidos?tomado=1');
    }

    private function pantalla(
        Usuario $organizador,
        bool $tomado = false,
        ?string $errorGeneral = null,
        int $estado = 200,
    ): Respuesta {
        $disponibles = $this->pedidos->paraProgramar($organizador);
        // Sólo las aprobadas sirven para tomar un pedido (EF-07.7); las pendientes se nombran aparte,
        // para que sepa por qué la que acaba de cargar todavía no aparece.
        $todas = $this->obras->delOrganizador($organizador);
        $obras = array_values(array_filter($todas, static fn (Obra $obra): bool => $obra->estaAprobada()));
        $pendientes = array_values(array_filter($todas, static fn (Obra $obra): bool => $obra->estado === EstadoObra::Pendiente));

        return $this->vista(self::PLANTILLA, [
            'pedidos' => array_map(
                static fn (PedidoDeTitulo $pedido): array => [
                    'id' => $pedido->id,
                    'titulo' => $pedido->titulo,
                    'anio' => $pedido->anio,
                    'director' => $pedido->director,
                    'adhesiones' => $pedido->adhesiones,
                ],
                $disponibles['pedidos'],
            ),
            'tiene_prioridad' => $disponibles['tiene_prioridad'],
            'obras' => array_map(
                static fn (Obra $obra): array => [
                    'id' => $obra->id,
                    'titulo' => $obra->titulo,
                    'anio' => $obra->anio,
                ],
                $obras,
            ),
            'obras_pendientes' => array_map(static fn (Obra $obra): string => $obra->titulo, $pendientes),
            'tomado' => $tomado,
            'error_general' => $errorGeneral,
        ], $estado);
    }

    private static function organizador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('Ruta de organizador sin usuario.');
    }
}
