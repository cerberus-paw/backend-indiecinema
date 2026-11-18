<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Modelo\Calificacion;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Servicios\ServicioDeCalificaciones;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * GET y POST /reservas/{id}/calificar (EF-04.2): el espectador que asistió califica la función.
 * Las reglas (la reserva suya, con asistencia, la función finalizada, dentro de los 30 días y una
 * sola vez) las controla el servicio; acá se valida lo que se escribió.
 */
final class Calificaciones extends Controlador
{
    public function __construct(private readonly ServicioDeCalificaciones $servicio)
    {
    }

    public function formulario(Peticion $peticion): Respuesta
    {
        [$reserva, $funcion] = $this->servicio->paraCalificar(self::usuario($peticion), (string) $peticion->parametro('id'));

        return $this->pantalla($reserva, $funcion);
    }

    public function calificar(Peticion $peticion): Respuesta
    {
        $usuario = self::usuario($peticion);
        $id = (string) $peticion->parametro('id');
        // Antes de validar: si ya no puede calificar, no tiene sentido decirle que corrija el puntaje.
        [$reserva, $funcion] = $this->servicio->paraCalificar($usuario, $id);

        $puntaje = $peticion->campo('puntaje');
        $puntaje = is_string($puntaje) ? trim($puntaje) : '';
        $comentario = $peticion->campo('comentario');
        $comentario = is_string($comentario) ? trim($comentario) : '';
        $valores = ['puntaje' => $puntaje, 'comentario' => $comentario];

        $errores = [];
        $numero = filter_var($puntaje, FILTER_VALIDATE_INT, ['options' => ['min_range' => Calificacion::PUNTAJE_MINIMO, 'max_range' => Calificacion::PUNTAJE_MAXIMO]]);
        if ($numero === false) {
            $errores['puntaje'] = 'Elegí un puntaje del ' . Calificacion::PUNTAJE_MINIMO . ' al ' . Calificacion::PUNTAJE_MAXIMO . '.';
        }
        if (mb_strlen($comentario) > Calificacion::COMENTARIO_MAXIMO) {
            $errores['comentario'] = 'El comentario puede tener hasta ' . Calificacion::COMENTARIO_MAXIMO . ' caracteres.';
        }
        if ($errores !== []) {
            return $this->pantalla($reserva, $funcion, $valores, $errores, 422);
        }

        try {
            $this->servicio->calificar($usuario, $id, (int) $numero, $comentario);
        } catch (ReglaIncumplida $error) {
            // La calificó mientras tanto, desde otra pestaña o con otra reserva de la misma función.
            return $this->pantalla($reserva, $funcion, $valores, ['puntaje' => $error->getMessage()], 422);
        }

        // 303: recargar no la vuelve a mandar.
        return $this->redirigir('/reservas?calificada=1');
    }

    /**
     * @param array<string, string> $valores
     * @param array<string, string> $errores
     */
    private function pantalla(Reserva $reserva, FuncionDeProgramacion $funcion, array $valores = [], array $errores = [], int $estado = 200): Respuesta
    {
        return $this->vista('calificar.html.twig', [
            'reserva' => ['id' => $reserva->id, 'codigo' => $reserva->codigo],
            'funcion' => [
                'titulo' => $funcion->titulo(),
                'url' => "/cartelera/{$funcion->id}",
                'fecha' => $funcion->fechaHora === null ? null : TextoParaMostrar::fecha($funcion->fechaHora),
                'sala' => $funcion->salaNombre,
                'localidad' => $funcion->salaLocalidad,
            ],
            'valores' => $valores,
            'errores' => $errores,
            'maximo_comentario' => Calificacion::COMENTARIO_MAXIMO,
        ], $estado);
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        return $peticion->usuario() ?? throw new LogicException('La ruta pide sesión: el middleware no dejó pasar el pedido sin usuario.');
    }
}
