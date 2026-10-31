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
     * Los de varias funciones en un solo pedido, para una página de tarjetas.
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
            $datos = $cliente->get('funciones', '/interno/ocupacion', ['funciones' => implode(',', $ids)]);
            $ocupados = is_array($datos) ? ($datos['lugares_ocupados'] ?? null) : null;
            if (!is_array($ocupados) || array_filter($ocupados, is_int(...)) !== $ocupados) {
                throw new RuntimeException('funciones no respondió los lugares ocupados a GET /interno/ocupacion.');
            }

            return $ocupados;
        });
    }

    /**
     * Los de una función, para su ficha.
     *
     * @return ?int null si funciones no responde
     */
    public function deUna(string $funcionId): ?int
    {
        if (!Uuid::esValido($funcionId)) {
            return null;
        }

        return $this->pedir(function (ClienteInterno $cliente) use ($funcionId): int {
            $datos = $cliente->get('funciones', "/interno/funciones/{$funcionId}/ocupacion");
            $ocupados = is_array($datos) ? ($datos['lugares_ocupados'] ?? null) : null;
            if (!is_int($ocupados)) {
                throw new RuntimeException('funciones no respondió los lugares ocupados a GET /interno/funciones/{id}/ocupacion.');
            }

            return $ocupados;
        });
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
