<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Programacion\Modelo\DatosDePedido;
use IndieCinema\Programacion\Modelo\EstadoPedido;
use IndieCinema\Programacion\Modelo\PedidoDeTitulo;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use LogicException;
use PDOException;

/**
 * Persistencia de pedidos de títulos ausentes y sus adhesiones (EF-11.1 / IC-576).
 * La clave primaria (pedido_id, usuario_id) de adhesion_pedido evita adhesiones duplicadas aunque
 * lleguen dos pedidos simultáneos.
 */
final class RepositorioDePedidos
{
    private const CLAVE_DUPLICADA = 1062;
    public const MENSAJE_YA_SUMADO = 'Ya te sumaste a este pedido.';
    public const MENSAJE_PEDIDO_TOMADO = 'Este pedido ya fue tomado por una sala.';

    private const COLUMNAS = 'p.id, p.titulo, p.anio, p.director, p.creado_por, p.estado, p.activado_en,
                              p.obra_id, o.titulo AS obra_titulo, p.tomado_por, p.tomado_en, p.creado_en,
                              (SELECT COUNT(*) FROM adhesion_pedido a WHERE a.pedido_id = p.id) AS adhesiones';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function buscar(string $id, ?string $usuarioId = null): ?PedidoDeTitulo
    {
        [$selectAdherido, $parametrosAdherido] = self::expresionAdherido($usuarioId);
        $fila = $this->base->fila(
            'SELECT ' . self::COLUMNAS . ", {$selectAdherido}
             FROM pedido_titulo p
             LEFT JOIN obra o ON o.id = p.obra_id
             WHERE p.id = ?",
            [...$parametrosAdherido, $id],
        );

        return $fila === null ? null : self::pedido($fila);
    }

    /**
     * Pedidos existentes con título parecido (la intercalación utf8mb4_0900_ai_ci compara sin tildes
     * ni distinguir mayúsculas) y el mismo año de estreno.
     *
     * @return list<PedidoDeTitulo>
     */
    public function buscarParecidos(string $titulo, int $anio, ?string $usuarioId = null): array
    {
        [$selectAdherido, $parametrosAdherido] = self::expresionAdherido($usuarioId);
        $filas = $this->base->filas(
            'SELECT ' . self::COLUMNAS . ", {$selectAdherido}
             FROM pedido_titulo p
             LEFT JOIN obra o ON o.id = p.obra_id
             WHERE p.titulo = ? AND p.anio = ?
             ORDER BY (p.estado = 'tomado') ASC, adhesiones DESC, p.creado_en ASC, p.id ASC",
            [...$parametrosAdherido, trim($titulo), $anio],
        );

        return array_map(self::pedido(...), $filas);
    }

    /**
     * Registra un pedido nuevo junto con la adhesión inicial de quien lo creó, en la misma
     * transacción. Si el umbral de activación es 1, nace ya en estado «activo».
     */
    public function crear(string $id, DatosDePedido $datos, string $usuarioId, int $umbral): PedidoDeTitulo
    {
        $estadoInicial = $umbral <= 1 ? EstadoPedido::Activo->value : EstadoPedido::Abierto->value;

        $this->base->enTransaccion(static function (BaseDeDatos $base) use ($id, $datos, $usuarioId, $estadoInicial, $umbral): void {
            $base->ejecutar(
                'INSERT INTO pedido_titulo (id, titulo, anio, director, creado_por, estado, activado_en, creado_en)
                 VALUES (?, ?, ?, ?, ?, ?, ' . ($umbral <= 1 ? 'UTC_TIMESTAMP()' : 'NULL') . ', UTC_TIMESTAMP())',
                [$id, $datos->titulo, $datos->anio, $datos->director, $usuarioId, $estadoInicial],
            );
            $base->ejecutar(
                'INSERT INTO adhesion_pedido (pedido_id, usuario_id, creada_en) VALUES (?, ?, UTC_TIMESTAMP())',
                [$id, $usuarioId],
            );
        });

        return $this->buscar($id, $usuarioId) ?? throw new LogicException('No se pudo leer el pedido recién creado.');
    }

    /**
     * Suma la adhesión del usuario al pedido y, en la misma transacción, lo pasa a «activo» si
     * alcanzó el umbral configurado. Si ya estaba sumado, la clave primaria lo frena y lanza
     * ReglaIncumplida para que el controlador muestre el aviso en lugar de un 500.
     *
     * @throws ExcepcionHttp   404 si el pedido no existe
     * @throws ReglaIncumplida si el pedido ya fue tomado o el usuario ya estaba adherido
     */
    public function sumarAdhesion(string $pedidoId, string $usuarioId, int $umbral): PedidoDeTitulo
    {
        $this->base->enTransaccion(static function (BaseDeDatos $base) use ($pedidoId, $usuarioId, $umbral): void {
            $fila = $base->fila('SELECT id, estado FROM pedido_titulo WHERE id = ? FOR UPDATE', [$pedidoId]);
            if ($fila === null) {
                throw ExcepcionHttp::noEncontrada();
            }
            if ((string) $fila['estado'] === EstadoPedido::Tomado->value) {
                throw new ReglaIncumplida(self::MENSAJE_PEDIDO_TOMADO);
            }

            try {
                $base->ejecutar(
                    'INSERT INTO adhesion_pedido (pedido_id, usuario_id, creada_en) VALUES (?, ?, UTC_TIMESTAMP())',
                    [$pedidoId, $usuarioId],
                );
            } catch (PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) === self::CLAVE_DUPLICADA) {
                    throw new ReglaIncumplida(self::MENSAJE_YA_SUMADO, previous: $error);
                }
                throw $error;
            }

            $total = (int) $base->valor('SELECT COUNT(*) FROM adhesion_pedido WHERE pedido_id = ?', [$pedidoId]);
            if ((string) $fila['estado'] === EstadoPedido::Abierto->value && $total >= $umbral) {
                $base->ejecutar(
                    "UPDATE pedido_titulo
                     SET estado = 'activo', activado_en = COALESCE(activado_en, UTC_TIMESTAMP())
                     WHERE id = ?",
                    [$pedidoId],
                );
            }
        });

        return $this->buscar($pedidoId, $usuarioId) ?? throw ExcepcionHttp::noEncontrada();
    }

    /**
     * Quita la adhesión del usuario si el pedido aún no fue tomado por ninguna sala.
     *
     * @throws ExcepcionHttp   404 si el pedido no existe
     * @throws ReglaIncumplida si el pedido ya fue tomado
     */
    public function quitarAdhesion(string $pedidoId, string $usuarioId): PedidoDeTitulo
    {
        $this->base->enTransaccion(static function (BaseDeDatos $base) use ($pedidoId, $usuarioId): void {
            $fila = $base->fila('SELECT id, estado FROM pedido_titulo WHERE id = ? FOR UPDATE', [$pedidoId]);
            if ($fila === null) {
                throw ExcepcionHttp::noEncontrada();
            }
            if ((string) $fila['estado'] === EstadoPedido::Tomado->value) {
                throw new ReglaIncumplida(self::MENSAJE_PEDIDO_TOMADO);
            }

            $base->ejecutar(
                'DELETE FROM adhesion_pedido WHERE pedido_id = ? AND usuario_id = ?',
                [$pedidoId, $usuarioId],
            );
        });

        return $this->buscar($pedidoId, $usuarioId) ?? throw ExcepcionHttp::noEncontrada();
    }

    /**
     * Ranking de pedidos abiertos o activos ordenados por cantidad de adhesiones (EF-11.2).
     * Los pedidos ya tomados salen del ranking principal.
     *
     * @return array{pedidos: list<PedidoDeTitulo>, total: int}
     */
    public function ranking(?string $texto, int $pagina, int $porPagina, ?string $usuarioId = null): array
    {
        $condiciones = ["p.estado IN ('abierto', 'activo')"];
        $parametrosFiltro = [];

        if ($texto !== null && trim($texto) !== '') {
            $patron = Like::contiene(trim($texto));
            $condiciones[] = "(p.titulo LIKE ? ESCAPE '!' OR p.director LIKE ? ESCAPE '!')";
            $parametrosFiltro[] = $patron;
            $parametrosFiltro[] = $patron;
        }

        $where = implode(' AND ', $condiciones);
        $total = (int) $this->base->valor(
            "SELECT COUNT(*) FROM pedido_titulo p WHERE {$where}",
            $parametrosFiltro,
        );
        if ($total === 0) {
            return ['pedidos' => [], 'total' => 0];
        }

        $offset = max(0, ($pagina - 1) * $porPagina);
        [$selectAdherido, $parametrosAdherido] = self::expresionAdherido($usuarioId);
        $filas = $this->base->filas(
            'SELECT ' . self::COLUMNAS . ", {$selectAdherido}
             FROM pedido_titulo p
             LEFT JOIN obra o ON o.id = p.obra_id
             WHERE {$where}
             ORDER BY adhesiones DESC, p.creado_en ASC, p.id ASC
             LIMIT ? OFFSET ?",
            [...$parametrosAdherido, ...$parametrosFiltro, $porPagina, $offset],
        );

        return ['pedidos' => array_map(self::pedido(...), $filas), 'total' => $total];
    }

    /**
     * Últimos pedidos ya tomados por una sala cuya obra sigue activa en el catálogo (EF-11).
     *
     * @return list<PedidoDeTitulo>
     */
    public function tomadosRecientes(int $limite = 6, ?string $usuarioId = null): array
    {
        [$selectAdherido, $parametrosAdherido] = self::expresionAdherido($usuarioId);
        $filas = $this->base->filas(
            'SELECT ' . self::COLUMNAS . ", {$selectAdherido}
             FROM pedido_titulo p
             INNER JOIN obra o ON o.id = p.obra_id
             WHERE p.estado = 'tomado' AND o.dada_de_baja_en IS NULL
             ORDER BY p.tomado_en DESC, p.creado_en DESC, p.id ASC
             LIMIT ?",
            [...$parametrosAdherido, max(1, $limite)],
        );

        return array_map(self::pedido(...), $filas);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function expresionAdherido(?string $usuarioId): array
    {
        if ($usuarioId === null) {
            return ['0 AS adherido', []];
        }

        return ['EXISTS(SELECT 1 FROM adhesion_pedido au WHERE au.pedido_id = p.id AND au.usuario_id = ?) AS adherido', [$usuarioId]];
    }

    /**
     * @param array<string, mixed> $fila
     */
    private static function pedido(array $fila): PedidoDeTitulo
    {
        return new PedidoDeTitulo(
            (string) $fila['id'],
            (string) $fila['titulo'],
            (int) $fila['anio'],
            (string) $fila['director'],
            (string) $fila['creado_por'],
            EstadoPedido::from((string) $fila['estado']),
            (int) $fila['adhesiones'],
            (bool) $fila['adherido'],
            self::fecha($fila['creado_en']),
            $fila['activado_en'] === null ? null : self::fecha($fila['activado_en']),
            $fila['obra_id'] === null ? null : (string) $fila['obra_id'],
            $fila['obra_titulo'] === null ? null : (string) $fila['obra_titulo'],
            $fila['tomado_por'] === null ? null : (string) $fila['tomado_por'],
            $fila['tomado_en'] === null ? null : self::fecha($fila['tomado_en']),
        );
    }

    private static function fecha(mixed $valor): DateTimeImmutable
    {
        return new DateTimeImmutable((string) $valor, new DateTimeZone('UTC'));
    }
}
