<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Los usuarios de cuentas, pedidos por la API interna con el token de funciones
 * (GET /interno/usuarios?ids=...). Es lo que funciones usa para saber a nombre de quién está cada
 * reserva en el panel y en el CSV de la función (E4-17.2 / IC-464).
 */
final class UsuariosDeCuentas
{
    public const MAXIMO_POR_PEDIDO = 100;

    public function __construct(
        private readonly ClienteInterno $cliente,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * El nombre de cada espectador, indexado por su id. Si cuentas no responde o falla, registra
     * un aviso en el log y devuelve un arreglo vacío para que el panel y el CSV salgan igual con
     * el nombre vacío.
     *
     * @param list<string> $usuariosIds
     *
     * @return array<string, string> id => nombre
     */
    public function nombresDe(array $usuariosIds): array
    {
        $ids = array_values(array_unique(array_filter($usuariosIds, Uuid::esValido(...))));
        if ($ids === []) {
            return [];
        }

        $nombres = [];
        try {
            foreach (array_chunk($ids, self::MAXIMO_POR_PEDIDO) as $tanda) {
                $datos = $this->cliente->get('cuentas', '/interno/usuarios', ['ids' => implode(',', $tanda)]);
                if (!is_array($datos)) {
                    throw new RuntimeException('cuentas no respondió los usuarios a GET /interno/usuarios.');
                }
                foreach ($datos as $id => $usuario) {
                    if (
                        !is_string($id) || !is_array($usuario)
                        || !isset($usuario['nombre']) || !is_string($usuario['nombre'])
                    ) {
                        throw new RuntimeException('cuentas no respondió los usuarios a GET /interno/usuarios.');
                    }
                    $nombres[$id] = $usuario['nombre'];
                }
            }
        } catch (ExcepcionHttp | RuntimeException $error) {
            $this->log->warning('Sin los nombres de los espectadores: {motivo}', ['motivo' => $error->getMessage()]);

            return [];
        }

        return $nombres;
    }
}
