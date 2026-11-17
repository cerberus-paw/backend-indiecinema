<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Uuid;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Puntaje de conducta de los espectadores desde funciones, por su API interna (EF-13.3).
 *
 * Es un dato informativo de la tabla del administrador: si funciones no está configurado o no
 * responde, devuelve null y la columna muestra «—» sin romper «Usuarios y roles».
 */
final class ConductaDeFunciones
{
    private const MAXIMO_POR_PEDIDO = 100;

    private ?ClienteInterno $cliente = null;

    public function __construct(
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @param list<string> $usuariosIds
     *
     * @return ?array<string, array{puntaje: int, bajo_umbral: bool, negativas: int, ausencias: int}> null si funciones no responde o no está configurado
     */
    public function deVarios(array $usuariosIds): ?array
    {
        $cliente = $this->cliente();
        if ($cliente === null) {
            return null;
        }
        $ids = array_values(array_unique(array_filter($usuariosIds, Uuid::esValido(...))));
        if ($ids === []) {
            return [];
        }

        try {
            $conductas = [];
            foreach (array_chunk($ids, self::MAXIMO_POR_PEDIDO) as $tanda) {
                $datos = $cliente->get('funciones', '/interno/usuarios/conducta', ['ids' => implode(',', $tanda)]);
                $porUsuario = is_array($datos) ? ($datos['conductas'] ?? null) : null;
                if (!is_array($porUsuario)) {
                    throw new RuntimeException('funciones no respondió las conductas a GET /interno/usuarios/conducta.');
                }
                foreach ($porUsuario as $id => $conducta) {
                    if (
                        !is_string($id) || !Uuid::esValido($id) || !is_array($conducta)
                        || !is_int($conducta['puntaje'] ?? null)
                        || !is_bool($conducta['bajo_umbral'] ?? null)
                        || !is_int($conducta['negativas'] ?? null)
                        || !is_int($conducta['ausencias'] ?? null)
                    ) {
                        throw new RuntimeException('funciones devolvió un formato inesperado en GET /interno/usuarios/conducta.');
                    }
                    $conductas[$id] = [
                        'puntaje' => $conducta['puntaje'],
                        'bajo_umbral' => $conducta['bajo_umbral'],
                        'negativas' => $conducta['negativas'],
                        'ausencias' => $conducta['ausencias'],
                    ];
                }
            }

            return $conductas;
        } catch (ExcepcionHttp) {
            // ClienteInterno ya dejó en el log que funciones no respondió.
            return null;
        } catch (RuntimeException $error) {
            $this->log->error('Sin la conducta de usuarios desde funciones: {mensaje}', ['mensaje' => $error->getMessage()]);

            return null;
        }
    }

    private function cliente(): ?ClienteInterno
    {
        if ($this->configuracion->obtener('subsistemas.funciones') === null || $this->configuracion->obtener('interno.token') === null) {
            return null;
        }

        return $this->cliente ??= ClienteInterno::desdeConfiguracion($this->configuracion, $this->log);
    }
}
