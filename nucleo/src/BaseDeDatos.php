<?php

declare(strict_types=1);

namespace IndieCinema\Nucleo;

use BackedEnum;
use DateTimeInterface;
use PDO;
use PDOStatement;
use Pdo\Mysql;
use Throwable;

/**
 * La conexión al esquema del subsistema, con el usuario propio que sólo lee y escribe datos.
 *
 * No hay forma de mandar SQL sin preparar: todo pasa por una consulta preparada con los valores
 * aparte, que es el control contra la inyección SQL que prometimos. La aplicación la crea recién
 * cuando un repositorio la pide, así una página que no usa la base no abre una conexión.
 */
final class BaseDeDatos
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function conectar(Configuracion $configuracion): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $configuracion->requerir('base.host'),
            $configuracion->obtener('base.puerto', 3306),
            $configuracion->requerir('base.esquema'),
        );

        return new self(new PDO($dsn, (string) $configuracion->requerir('base.usuario'), (string) $configuracion->requerir('base.clave'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Preparadas de verdad, en el servidor: los valores nunca se mezclan con el SQL, y los
            // números vuelven como números y no como texto.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Una consulta es una sola sentencia: aunque algo se colara, no se le puede encadenar otra.
            Mysql::ATTR_MULTI_STATEMENTS => false,
        ]));
    }

    /**
     * @param array<int|string, mixed> $parametros por posición (?) o por nombre (:nombre)
     *
     * @return list<array<string, mixed>>
     */
    public function filas(string $sql, array $parametros = []): array
    {
        return $this->ejecutarSentencia($sql, $parametros)->fetchAll();
    }

    /**
     * La primera fila, o null si no hay ninguna.
     *
     * @param array<int|string, mixed> $parametros
     *
     * @return array<string, mixed>|null
     */
    public function fila(string $sql, array $parametros = []): ?array
    {
        $fila = $this->ejecutarSentencia($sql, $parametros)->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * La primera columna de la primera fila, para un COUNT(*) o un EXISTS; null si no hay filas.
     *
     * @param array<int|string, mixed> $parametros
     */
    public function valor(string $sql, array $parametros = []): mixed
    {
        $valor = $this->ejecutarSentencia($sql, $parametros)->fetchColumn();

        return $valor === false ? null : $valor;
    }

    /**
     * Para INSERT, UPDATE y DELETE: devuelve cuántas filas cambió.
     *
     * @param array<int|string, mixed> $parametros
     */
    public function ejecutar(string $sql, array $parametros = []): int
    {
        return $this->ejecutarSentencia($sql, $parametros)->rowCount();
    }

    public function ultimoId(): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    /**
     * Corre $trabajo en una transacción: si termina, se confirma; si lanza una excepción, se
     * deshace y la excepción sigue. Es lo que usa, por ejemplo, el control de cupo de las
     * reservas con SELECT … FOR UPDATE. No se anidan.
     *
     * @template T
     *
     * @param callable(self): T $trabajo
     *
     * @return T
     */
    public function enTransaccion(callable $trabajo): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $resultado = $trabajo($this);
            $this->pdo->commit();

            return $resultado;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @param array<int|string, mixed> $parametros
     */
    private function ejecutarSentencia(string $sql, array $parametros): PDOStatement
    {
        $sentencia = $this->pdo->prepare($sql);
        foreach ($parametros as $clave => $valor) {
            [$valor, $tipo] = self::paraLaBase($valor);
            // PDO numera los «?» desde 1; los nombres van con los dos puntos, se escriban o no.
            $sentencia->bindValue(is_int($clave) ? $clave + 1 : ':' . ltrim($clave, ':'), $valor, $tipo);
        }
        $sentencia->execute();

        return $sentencia;
    }

    /**
     * Cada valor con su tipo: un entero mandado como texto rompe un LIMIT ?, y así los estados
     * (enumeraciones) y las fechas se pueden pasar tal cual.
     *
     * @return array{0: mixed, 1: int}
     */
    private static function paraLaBase(mixed $valor): array
    {
        return match (true) {
            $valor === null => [null, PDO::PARAM_NULL],
            is_int($valor) => [$valor, PDO::PARAM_INT],
            is_bool($valor) => [$valor, PDO::PARAM_BOOL],
            $valor instanceof BackedEnum => self::paraLaBase($valor->value),
            $valor instanceof DateTimeInterface => [$valor->format('Y-m-d H:i:s'), PDO::PARAM_STR],
            default => [(string) $valor, PDO::PARAM_STR],
        };
    }
}
