<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Los lugares ocupados de las funciones, que pide al subsistema funciones por su API interna
 * (E4-02): el cupo es de programación, pero las reservas son de funciones.
 *
 * Es un dato de más, no lo que hace a la página: si funciones no responde, la cartelera y la
 * ficha se muestran igual, sin los lugares (E4-02.3). Por eso no corta con un 503 como el resto
 * de la API interna: devuelve null y la página sigue. Lo mismo si responde algo que no se
 * entiende, que además queda en el log como error, porque es un problema nuestro.
 */
final class OcupacionDeFunciones
{
    private ?ClienteInterno $cliente = null;

    public function __construct(
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * Lo más que acepta GET /interno/ocupacion en un pedido (OcupacionInterna::MAXIMO_POR_PEDIDO).
     * Una página de la cartelera nunca llega, pero la ficha de una sala muestra todas sus funciones.
     */
    public const MAXIMO_POR_PEDIDO = 100;

    /**
     * Los de varias funciones, para una lista de tarjetas: en un solo pedido, o de a 100 si son más.
     *
     * @param list<string> $funcionesIds
     *
     * @return ?array<string, int> funcion_id => lugares ocupados; null si funciones no responde
     */
    public function deVarias(array $funcionesIds): ?array
    {
        $ids = array_values(array_unique(array_filter($funcionesIds, Uuid::esValido(...))));
        if ($ids === []) {
            return [];
        }

        return $this->pedir(function (ClienteInterno $cliente) use ($ids): array {
            $todos = [];
            foreach (array_chunk($ids, self::MAXIMO_POR_PEDIDO) as $tanda) {
                $datos = $cliente->get('funciones', '/interno/ocupacion', ['funciones' => implode(',', $tanda)]);
                $ocupados = is_array($datos) ? ($datos['lugares_ocupados'] ?? null) : null;
                if (!is_array($ocupados) || array_filter($ocupados, is_int(...)) !== $ocupados) {
                    throw new RuntimeException('funciones no respondió los lugares ocupados a GET /interno/ocupacion.');
                }
                $todos += $ocupados;
            }

            return $todos;
        });
    }

    /**
     * Los lugares ocupados de una función y, si se pasa usuario, los que ya tiene reservados ese
     * espectador en ella (IC-463).
     *
     * @return ?array{ocupados: int, del_usuario: int} null si funciones no responde
     */
    public function deUnaParaUsuario(string $funcionId, ?string $usuarioId = null): ?array
    {
        if (!Uuid::esValido($funcionId)) {
            return null;
        }

        $parametros = [];
        if ($usuarioId !== null && Uuid::esValido($usuarioId)) {
            $parametros['usuario'] = $usuarioId;
        }

        return $this->pedir(function (ClienteInterno $cliente) use ($funcionId, $parametros): array {
            $datos = $cliente->get('funciones', "/interno/funciones/{$funcionId}/ocupacion", $parametros);
            $ocupados = is_array($datos) ? ($datos['lugares_ocupados'] ?? null) : null;
            if (!is_int($ocupados)) {
                throw new RuntimeException('funciones no respondió los lugares ocupados a GET /interno/funciones/{id}/ocupacion.');
            }
            $delUsuario = $datos['lugares_del_usuario'] ?? 0;

            return [
                'ocupados' => $ocupados,
                'del_usuario' => is_int($delUsuario) ? $delUsuario : 0,
            ];
        });
    }

    /**
     * Los de una función, para su ficha.
     *
     * @return ?int null si funciones no responde
     */
    public function deUna(string $funcionId): ?int
    {
        $ocupacion = $this->deUnaParaUsuario($funcionId);

        return $ocupacion !== null ? $ocupacion['ocupados'] : null;
    }

    /**
     * @template T
     *
     * @param callable(ClienteInterno): T $pedido
     *
     * @return ?T
     */
    private function pedir(callable $pedido): mixed
    {
        $cliente = $this->cliente();
        if ($cliente === null) {
            return null;
        }
        try {
            return $pedido($cliente);
        } catch (ExcepcionHttp) {
            // ClienteInterno ya dejó en el log que funciones no respondió.
            return null;
        } catch (RuntimeException $error) {
            $this->log->error('Sin los lugares libres: {mensaje}', ['mensaje' => $error->getMessage()]);

            return null;
        }
    }

    /**
     * El cliente, armado recién cuando hace falta. Sin la dirección de funciones en config.ini no
     * hay a quién preguntar, y las páginas se muestran sin los lugares, como si no respondiera:
     * así las pruebas de programación que no tienen nada que ver con las reservas no necesitan
     * configurarlo.
     */
    private function cliente(): ?ClienteInterno
    {
        if ($this->configuracion->obtener('subsistemas.funciones') === null || $this->configuracion->obtener('interno.token') === null) {
            return null;
        }

        return $this->cliente ??= ClienteInterno::desdeConfiguracion($this->configuracion, $this->log);
    }
}
