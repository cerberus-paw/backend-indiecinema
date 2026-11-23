<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Controladores;

use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\EstadoPedido;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\PedidoDeTitulo;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Repositorios\RepositorioDePedidos;
use IndieCinema\Programacion\Servicios\ServicioDePedidos;
use LogicException;

/**
 * Ranking público y alta de pedidos de títulos ausentes y adhesiones (EF-11.1 / IC-576, EF-11.2 / IC-577).
 */
final class Pedidos extends Controlador
{
    private const PLANTILLA_RANKING = 'pedidos.html.twig';
    private const PLANTILLA_NUEVO = 'pedir_titulo.html.twig';
    private const PAGINAS_ALREDEDOR = 2;

    public function __construct(private readonly ServicioDePedidos $servicio)
    {
    }

    /**
     * GET /pedidos: ranking público de pedidos ordenados por adhesiones, con buscador y paginado.
     *
     * @throws ExcepcionHttp 404 si el parámetro pagina no es un entero positivo o excede la última
     */
    public function listar(Peticion $peticion): Respuesta
    {
        $paginaCruda = $peticion->consulta('pagina');
        $pagina = 1;
        if ($paginaCruda !== null && $paginaCruda !== '') {
            if (!is_string($paginaCruda) && !is_int($paginaCruda)) {
                throw ExcepcionHttp::noEncontrada();
            }
            $validada = filter_var((string) $paginaCruda, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validada === false) {
                throw ExcepcionHttp::noEncontrada();
            }
            $pagina = (int) $validada;
        }

        $texto = self::textoConsulta($peticion->consulta('q'));
        $mensajeExito = match (true) {
            $peticion->consulta('sumado') === '1' => 'Te sumaste al pedido.',
            $peticion->consulta('quitado') === '1' => 'Quitamos tu adhesión al pedido.',
            default => null,
        };

        return $this->pantallaRanking($peticion, $texto, $pagina, mensajeExito: $mensajeExito);
    }

    /** GET /pedidos/nuevo (HTML, o sugerencias de autocompletado con Accept: application/json). */
    public function nuevo(Peticion $peticion): Respuesta
    {
        if ($peticion->aceptaJson()) {
            $consulta = self::textoConsulta($peticion->consulta('q'));

            return $this->json(['sugerencias' => $this->servicio->sugerencias($consulta)])->conCabecera('Vary', 'Accept');
        }

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
                obrasCatalogo: $resultado['en_catalogo'],
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

        return $this->responderAdhesion($peticion, sumar: true);
    }

    /** POST /pedidos/{id}/quitar */
    public function quitar(Peticion $peticion): Respuesta
    {
        return $this->responderAdhesion($peticion, sumar: false);
    }

    private function responderAdhesion(Peticion $peticion, bool $sumar): Respuesta
    {
        $usuario = self::espectador($peticion);
        $id = (string) $peticion->parametro('id');
        $desdeRanking = (string) $peticion->campo('origen') === 'ranking';

        try {
            $pedido = $sumar
                ? $this->servicio->sumarse($usuario, $id)
                : $this->servicio->dejarDePedirlo($usuario, $id);
        } catch (ReglaIncumplida $regla) {
            if ($peticion->aceptaJson()) {
                return $this->json(['error' => $regla->getMessage(), 'aviso' => $regla->getMessage()], 409);
            }
            if ($desdeRanking) {
                return $this->pantallaRanking($peticion, '', 1, aviso: $regla->getMessage(), estado: 200);
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
                'adherido' => $sumar,
                'adhesiones' => $pedido->adhesiones,
                'estado' => $pedido->estado->value,
            ]);
        }

        $param = $sumar ? 'sumado=1' : 'quitado=1';

        return $desdeRanking
            ? $this->redirigir("/pedidos?{$param}")
            : $this->redirigir("/pedidos/nuevo?pedido={$pedido->id}&{$param}");
    }

    private function pantallaRanking(
        Peticion $peticion,
        string $texto,
        int $pagina,
        ?string $mensajeExito = null,
        ?string $aviso = null,
        int $estado = 200,
    ): Respuesta {
        $usuario = $peticion->usuario();
        $ranking = $this->servicio->ranking($texto !== '' ? $texto : null, $pagina, $usuario);
        $umbral = $this->servicio->umbral();

        $pedidos = array_map(
            static fn (PedidoDeTitulo $pedido): array => self::datosDePedido($pedido, $umbral),
            $ranking['pedidos'],
        );
        $tomados = array_map(
            static fn (PedidoDeTitulo $pedido): array => self::datosDePedido($pedido, $umbral),
            $ranking['tomados'],
        );

        $totalPaginas = $ranking['total_paginas'];
        $paginado = [
            'numero' => $ranking['pagina'],
            'total_paginas' => $totalPaginas,
            'total_pedidos' => $ranking['total'],
            'por_pagina' => $ranking['por_pagina'],
            'anterior' => $ranking['pagina'] > 1 ? self::urlDePagina($texto, $ranking['pagina'] - 1) : null,
            'siguiente' => $ranking['pagina'] < $totalPaginas ? self::urlDePagina($texto, $ranking['pagina'] + 1) : null,
            'paginas' => self::paginas($ranking['pagina'], $totalPaginas, $texto),
        ];

        $filtros = $texto !== '' ? ['q' => $texto] : [];

        if ($peticion->aceptaJson()) {
            return $this->json([
                'pedidos' => $pedidos,
                'tomados' => $tomados,
                'filtros' => (object) $filtros,
                'paginado' => $paginado,
                'umbral' => $umbral,
            ])->conCabecera('Vary', 'Accept');
        }

        return $this->vista(self::PLANTILLA_RANKING, [
            'pedidos' => $pedidos,
            'tomados' => $tomados,
            'filtros' => $filtros,
            'filtrada' => $texto !== '',
            'paginado' => $paginado,
            'umbral' => $umbral,
            'por_pagina' => $ranking['por_pagina'],
            'hay_sesion' => $usuario !== null,
            'mensaje_exito' => $mensajeExito,
            'aviso' => $aviso,
        ], $estado)->conCabecera('Vary', 'Accept');
    }

    /**
     * @param list<PedidoDeTitulo> $parecidos
     * @param list<Obra>           $obrasCatalogo
     */
    private function pantalla(
        ?FormularioDePedido $formulario = null,
        array $parecidos = [],
        array $obrasCatalogo = [],
        ?PedidoDeTitulo $pedidoActual = null,
        ?string $errorGeneral = null,
        ?string $mensajeExito = null,
        ?string $aviso = null,
        int $estado = 200,
    ): Respuesta {
        $umbral = $this->servicio->umbral();

        return $this->vista(self::PLANTILLA_NUEVO, [
            'reglas' => FormularioDePedido::reglas(),
            'valores' => $formulario === null ? [] : $formulario->valores,
            'errores' => $formulario === null ? [] : $formulario->errores,
            'parecidos' => array_map(static fn (PedidoDeTitulo $pedido): array => self::datosDePedido($pedido, $umbral), $parecidos),
            'obras_catalogo' => array_map(static fn (Obra $obra): array => [
                'id' => $obra->id,
                'titulo' => $obra->titulo,
                'anio' => $obra->anio,
                'director' => $obra->director,
                'url' => "/obras/{$obra->id}",
            ], $obrasCatalogo),
            'sugerencias' => $this->servicio->sugerencias(),
            'pedido_actual' => $pedidoActual === null ? null : self::datosDePedido($pedidoActual, $umbral),
            'error_general' => $errorGeneral,
            'mensaje_exito' => $mensajeExito,
            'aviso' => $aviso,
        ], $estado)->conCabecera('Vary', 'Accept');
    }

    /**
     * @return array<string, mixed>
     */
    private static function datosDePedido(PedidoDeTitulo $pedido, int $umbral): array
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
            'umbral' => $umbral,
            'faltan_para_activar' => max(0, $umbral - $pedido->adhesiones),
            'adherido' => $pedido->adheridoPorUsuario,
            'obra_id' => $pedido->obraId,
            'obra_titulo' => $pedido->obraTitulo,
            'url_obra' => $pedido->obraId === null ? null : "/obras/{$pedido->obraId}",
        ];
    }

    private static function textoConsulta(mixed $valor): string
    {
        if (!is_string($valor) || !mb_check_encoding($valor, 'UTF-8')) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\p{Cc}/u', '', $valor)));
    }

    private static function urlDePagina(string $texto, int $numero): string
    {
        $consulta = [];
        if ($texto !== '') {
            $consulta['q'] = $texto;
        }
        if ($numero > 1) {
            $consulta['pagina'] = $numero;
        }

        return $consulta === [] ? '/pedidos' : '/pedidos?' . http_build_query($consulta);
    }

    /**
     * @return list<?array{numero: int, url: string, actual: bool}>
     */
    private static function paginas(int $actual, int $total, string $texto): array
    {
        $ancho = 2 * self::PAGINAS_ALREDEDOR + 1;
        $desde = max(1, min($actual - self::PAGINAS_ALREDEDOR, $total - $ancho + 1));
        $hasta = min($total, $desde + $ancho - 1);
        $numeros = array_values(array_unique([1, ...range($desde, $hasta), $total]));
        sort($numeros);

        $paginas = [];
        $anterior = 0;
        foreach ($numeros as $numero) {
            if ($anterior > 0 && $numero > $anterior + 1) {
                $paginas[] = null;
            }
            $paginas[] = [
                'numero' => $numero,
                'url' => self::urlDePagina($texto, $numero),
                'actual' => $numero === $actual,
            ];
            $anterior = $numero;
        }

        return $paginas;
    }

    private static function espectador(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('Ruta de espectador sin usuario.');
    }
}
