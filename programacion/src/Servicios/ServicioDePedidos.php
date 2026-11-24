<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Correo\Correo;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use IndieCinema\Programacion\Modelo\DatosDePedido;
use IndieCinema\Programacion\Modelo\EstadoPedido;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\PedidoDeTitulo;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDePedidos;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Alta, ranking y programación de pedidos de títulos ausentes y adhesiones (EF-11).
 */
final class ServicioDePedidos
{
    /** Para tomar un pedido con una obra que la moderación todavía no aprobó (EF-07.7). */
    public const MENSAJE_OBRA_SIN_APROBAR = '«%s» todavía no la aprobó la moderación: vas a poder tomar el pedido con ella cuando esté en el catálogo.';

    public const UMBRAL_POR_OMISION = 5;
    public const POR_PAGINA_POR_OMISION = 12;
    public const PROMEDIO_PRIORIDAD = 4.0;
    public const HORAS_PRIORIDAD = RepositorioDePedidos::HORAS_PRIORIDAD;
    private const MAXIMO_POR_PAGINA = 100;

    public function __construct(
        private readonly RepositorioDePedidos $pedidos,
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
        private readonly ?ServicioDeSalas $salas = null,
        private readonly ?CalificacionesDeFunciones $calificaciones = null,
        private readonly ?UsuariosDeCuentas $usuarios = null,
        private readonly ?ClienteSmtp $correo = null,
        private readonly ?RepositorioDeObras $obras = null,
    ) {
    }

    public function umbral(): int
    {
        return max(1, (int) $this->configuracion->obtener('pedidos.umbral', self::UMBRAL_POR_OMISION));
    }

    public function porPagina(): int
    {
        return max(1, min(self::MAXIMO_POR_PAGINA, (int) $this->configuracion->obtener('pedidos.por_pagina', self::POR_PAGINA_POR_OMISION)));
    }

    /**
     * Ranking paginado de pedidos abiertos y activos, junto con los últimos pedidos tomados.
     *
     * @return array{pedidos: list<PedidoDeTitulo>, total: int, pagina: int, total_paginas: int, por_pagina: int, tomados: list<PedidoDeTitulo>}
     *
     * @throws ExcepcionHttp 404 si la página es menor a 1 o supera la última
     */
    public function ranking(?string $texto, int $pagina, ?Usuario $usuario = null): array
    {
        if ($pagina < 1) {
            throw ExcepcionHttp::noEncontrada();
        }

        $porPagina = $this->porPagina();
        $resultado = $this->pedidos->ranking($texto, $pagina, $porPagina, $usuario?->id);
        $totalPaginas = max(1, (int) ceil($resultado['total'] / $porPagina));
        if ($pagina > 1 && $pagina > $totalPaginas) {
            throw ExcepcionHttp::noEncontrada();
        }

        return [
            'pedidos' => $resultado['pedidos'],
            'total' => $resultado['total'],
            'pagina' => $pagina,
            'total_paginas' => $totalPaginas,
            'por_pagina' => $porPagina,
            'tomados' => $this->pedidos->tomadosRecientes(usuarioId: $usuario?->id),
        ];
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
     * Si ya existe un pedido parecido (mismo título sin distinguir mayúsculas, tildes ni signos
     * o espacios de más, y sin distinguir el año), no duplica sino que lo devuelve para ofrecer
     * sumarse; si la obra ya está en el catálogo aprobado, la devuelve aparte. Si no existe en
     * ninguno de los dos, crea el pedido con la adhesión inicial del espectador (EF-22.6).
     *
     * @return array{creado: bool, pedido: ?PedidoDeTitulo, parecidos: list<PedidoDeTitulo>, en_catalogo: list<Obra>}
     */
    public function pedir(Usuario $usuario, DatosDePedido $datos): array
    {
        $parecidos = $this->pedidos->buscarParecidos($datos->titulo, $usuario->id);
        $enCatalogo = $this->obras?->buscarAprobadasPorTituloNormalizado($datos->titulo) ?? [];
        if ($parecidos !== [] || $enCatalogo !== []) {
            return ['creado' => false, 'pedido' => null, 'parecidos' => $parecidos, 'en_catalogo' => $enCatalogo];
        }

        $pedido = $this->pedidos->crear(Uuid::nuevo(), $datos, $usuario->id, $this->umbral());
        $this->log->info('El usuario {usuario} registró el pedido {pedido}.', [
            'pedido' => $pedido->id,
            'usuario' => $usuario->id,
        ]);

        return ['creado' => true, 'pedido' => $pedido, 'parecidos' => [], 'en_catalogo' => []];
    }

    /**
     * Sugerencias para el autocompletado al pedir un título: combina obras aprobadas del catálogo
     * y pedidos abiertos o activos existentes (EF-22.6).
     *
     * @return list<array{tipo: string, id: string, titulo: string, normalizado: string, anio: ?int, director: string, adhesiones: ?int, url: string}>
     */
    public function sugerencias(string $consulta = ''): array
    {
        $obras = $this->obras?->sugerenciasPorTitulo($consulta, 8) ?? [];
        $pedidos = $this->pedidos->sugerencias($consulta, 8);
        $lista = [];

        foreach ($obras as $obra) {
            $lista[] = [
                'tipo' => 'obra',
                'id' => $obra['id'],
                'titulo' => $obra['titulo'],
                'normalizado' => $obra['titulo_normalizado'],
                'anio' => $obra['anio'],
                'director' => $obra['director'],
                'adhesiones' => null,
                'url' => "/obras/{$obra['id']}",
            ];
        }

        foreach ($pedidos as $pedido) {
            $lista[] = [
                'tipo' => 'pedido',
                'id' => $pedido['id'],
                'titulo' => $pedido['titulo'],
                'normalizado' => $pedido['titulo_normalizado'],
                'anio' => $pedido['anio'],
                'director' => $pedido['director'],
                'adhesiones' => $pedido['adhesiones'],
                'url' => "/pedidos/nuevo?pedido={$pedido['id']}",
            ];
        }

        return $lista;
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

    /**
     * Si el organizador tiene alguna sala con promedio >= 4.0 en las calificaciones, accede 48
     * horas antes a los pedidos recién activados (EF-11.3 / EF-04.3).
     */
    public function tienePrioridad(Usuario $organizador): bool
    {
        if ($this->salas === null || $this->calificaciones === null) {
            return false;
        }
        $salas = $this->salas->delOrganizador($organizador);
        if ($salas === []) {
            return false;
        }

        $ids = array_map(static fn (Sala $sala): string => $sala->id, $salas);
        $resumen = $this->calificaciones->resumen([
            'salas' => array_slice($ids, 0, CalificacionesDeFunciones::MAXIMO_POR_PEDIDO),
        ]);
        if ($resumen === null) {
            return false;
        }

        foreach ($ids as $salaId) {
            if (($resumen['salas'][$salaId]['promedio'] ?? 0.0) >= self::PROMEDIO_PRIORIDAD) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{pedidos: list<PedidoDeTitulo>, tiene_prioridad: bool}
     */
    public function paraProgramar(Usuario $organizador, ?DateTimeImmutable $ahora = null): array
    {
        $tienePrioridad = $this->tienePrioridad($organizador);
        $limite = self::limiteDePrioridad($ahora);

        return [
            'pedidos' => $this->pedidos->activosParaProgramar($tienePrioridad, $limite),
            'tiene_prioridad' => $tienePrioridad,
        ];
    }

    /**
     * Comprueba que el pedido exista, siga activo y sea visible/tomable para el organizador antes
     * de abrir el formulario de alta de obra precargada o de procesar el alta.
     *
     * @throws ExcepcionHttp   404 si no es UUID o el pedido no existe
     * @throws ReglaIncumplida si ya fue tomado, sigue abierto o está en ventana de prioridad
     */
    public function paraTomar(Usuario $organizador, string $pedidoId, ?DateTimeImmutable $ahora = null): PedidoDeTitulo
    {
        $pedido = $this->buscar($pedidoId, $organizador);
        if ($pedido->estado === EstadoPedido::Tomado) {
            throw new ReglaIncumplida(RepositorioDePedidos::MENSAJE_PEDIDO_TOMADO);
        }
        if ($pedido->estado !== EstadoPedido::Activo) {
            throw new ReglaIncumplida(RepositorioDePedidos::MENSAJE_NO_ACTIVO);
        }
        if (!$this->tienePrioridad($organizador) && $pedido->activadoEn !== null && $pedido->activadoEn > self::limiteDePrioridad($ahora)) {
            throw new ReglaIncumplida(RepositorioDePedidos::MENSAJE_EN_PRIORIDAD);
        }

        return $pedido;
    }

    /**
     * Vincula una obra del organizador al pedido activo, lo pasa a «tomado» y avisa por correo a
     * quienes se sumaron (EF-11.3).
     *
     * Sólo con una obra aprobada (EF-07.7): una pendiente o rechazada no está en el catálogo, así
     * que el correo llevaría a quienes se sumaron a una ficha que da 404.
     *
     * @throws ExcepcionHttp   404 si el pedido no existe o la obra no es del organizador
     * @throws ReglaIncumplida si la obra no está aprobada, o no cumple estado activo o prioridad de 48 horas
     */
    public function tomarConObra(Usuario $organizador, string $pedidoId, Obra $obra, ?DateTimeImmutable $ahora = null): PedidoDeTitulo
    {
        if (!Uuid::esValido($pedidoId) || $obra->duenoId !== $organizador->id) {
            throw ExcepcionHttp::noEncontrada();
        }
        if (!$obra->estaAprobada()) {
            throw new ReglaIncumplida(sprintf(self::MENSAJE_OBRA_SIN_APROBAR, $obra->titulo));
        }

        $tienePrioridad = $this->tienePrioridad($organizador);
        $tomado = $this->pedidos->tomar($pedidoId, $obra->id, $organizador->id, $tienePrioridad, self::limiteDePrioridad($ahora));

        $this->log->info('El organizador {organizador} tomó el pedido {pedido} con la obra {obra}.', [
            'organizador' => $organizador->id,
            'pedido' => $tomado->id,
            'obra' => $obra->id,
        ]);

        $this->avisarAdherentes($tomado, $obra);

        return $tomado;
    }

    private function avisarAdherentes(PedidoDeTitulo $pedido, Obra $obra): void
    {
        if ($this->usuarios === null || $this->correo === null) {
            return;
        }

        $baseUrl = rtrim((string) $this->configuracion->obtener('sitio.url', ''), '/');
        $enlace = "{$baseUrl}/obras/{$obra->id}";
        $asunto = "Ya está en IndieCinema: {$obra->titulo}";
        $anioPedido = $pedido->anio === null ? '' : " ({$pedido->anio})";
        $cuerpo = implode("\n", [
            "El título «{$pedido->titulo}»{$anioPedido} que pediste en IndieCinema fue tomado por una sala para programar.",
            '',
            "Obra en el catálogo: {$obra->titulo} ({$obra->director})",
            "Podés ver su ficha y sus próximas funciones acá: {$enlace}",
        ]);

        foreach ($this->pedidos->usuariosAdheridos($pedido->id) as $usuarioId) {
            try {
                $destinatario = $this->usuarios->buscar($usuarioId);
            } catch (Throwable $error) {
                $this->log->warning('No se pudo consultar a cuentas para avisar del pedido {pedido} al usuario {usuario}: {error}', [
                    'pedido' => $pedido->id,
                    'usuario' => $usuarioId,
                    'error' => $error->getMessage(),
                ]);
                continue;
            }
            if ($destinatario === null || $destinatario->correo === '') {
                continue;
            }
            try {
                $this->correo->enviar(new Correo($destinatario->correo, $asunto, $cuerpo));
            } catch (Throwable $error) {
                $this->log->warning('No se pudo enviar el aviso del pedido {pedido} al usuario {usuario}: {error}', [
                    'pedido' => $pedido->id,
                    'usuario' => $usuarioId,
                    'error' => $error->getMessage(),
                ]);
            }
        }
    }

    private static function limiteDePrioridad(?DateTimeImmutable $ahora): DateTimeImmutable
    {
        $instante = ($ahora ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));

        return $instante->modify('-' . self::HORAS_PRIORIDAD . ' hours');
    }
}
