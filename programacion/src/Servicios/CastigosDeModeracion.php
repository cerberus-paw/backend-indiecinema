<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Las salas castigadas por las reglas de las denuncias (EF-22.11), que son de moderación: sus
 * funciones van al final de la cartelera. Si moderación no responde, no se castiga a nadie y la
 * cartelera sale igual.
 */
final class CastigosDeModeracion
{
    private ?ClienteInterno $cliente = null;

    public function __construct(
        private readonly Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return list<string> las salas castigadas hoy; ninguna si moderación no responde
     */
    public function salas(): array
    {
        // Sin moderación en config.ini, como las calificaciones sin funciones: sin castigo.
        if ($this->configuracion->obtener('subsistemas.moderacion') === null || $this->configuracion->obtener('interno.token') === null) {
            return [];
        }
        try {
            $datos = ($this->cliente ??= ClienteInterno::desdeConfiguracion($this->configuracion, $this->log))->get('moderacion', '/interno/denuncias/castigadas');
            $salas = is_array($datos) ? ($datos['salas'] ?? null) : null;
            if (!is_array($salas) || !array_is_list($salas) || array_filter($salas, is_string(...)) !== $salas) {
                throw new RuntimeException('moderación no respondió las salas castigadas.');
            }

            return $salas;
        } catch (ExcepcionHttp) {
            // ClienteInterno ya dejó en el log que moderación no respondió.
            return [];
        } catch (RuntimeException $error) {
            $this->log->error('Sin las salas castigadas: {mensaje}', ['mensaje' => $error->getMessage()]);

            return [];
        }
    }
}
