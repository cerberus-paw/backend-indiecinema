<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Servicios\ServicioDeReservas;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;

/**
 * La reserva de una función, como el wireframe de la E2 (reserva-pago-comprobante) sin el paso del
 * pago: la reserva se confirma sin cobrar.
 *
 * El formulario está en la ficha de la función, que es de programación, y llega acá por POST con
 * el token CSRF de la cookie del sitio. La página de GET /reservar/{id} es la misma con los
 * errores, y la que se ve si se llega escribiendo la dirección.
 */
final class Reservas extends Controlador
{
    public function __construct(private readonly ServicioDeReservas $servicio)
    {
    }

    public function formulario(Peticion $peticion): Respuesta
    {
        $funcion = $this->servicio->funcion((string) $peticion->parametro('id'));

        return $this->pantalla($funcion, ['cantidad' => '1']);
    }

    public function reservar(Peticion $peticion): Respuesta
    {
        $funcion = $this->servicio->funcion((string) $peticion->parametro('id'));
        $valor = $peticion->campo('cantidad');
        $valor = is_string($valor) ? trim($valor) : '';

        // Entero y dentro del cupo, como el min y el max del campo: el navegador controla lo mismo.
        $cantidad = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $funcion->cupo]]);
        if ($cantidad === false) {
            return $this->pantalla($funcion, ['cantidad' => $valor], ['cantidad' => "Ingresá entre 1 y {$funcion->cupo} lugares."], estado: 422);
        }

        try {
            $reserva = $this->servicio->reservar(self::usuario($peticion), $funcion->id, $cantidad);
        } catch (ReglaIncumplida $error) {
            return $this->pantalla($funcion, ['cantidad' => $valor], error: $error->getMessage(), estado: 422);
        }

        // 303: recargar la confirmación no vuelve a reservar.
        return $this->redirigir("/reservas/{$reserva->id}");
    }

    public function confirmada(Peticion $peticion): Respuesta
    {
        [$reserva, $funcion] = $this->servicio->delEspectador(self::usuario($peticion), (string) $peticion->parametro('id'));

        return $this->vista('reserva_confirmada.html.twig', [
            'reserva' => self::datosDeReserva($reserva, $funcion),
            'funcion' => $funcion === null ? null : self::datosDeFuncion($funcion),
            // El aviso al volver de cancelarla: sólo si de verdad quedó cancelada.
            'cancelada' => $peticion->consulta('cancelada') === '1' && $reserva->estado === EstadoReserva::Cancelada,
        ]);
    }

    /**
     * La pantalla que confirma la cancelación (E4-01.5). Si ya no se puede cancelar es 404, como
     * la de cancelar una función: el comprobante no muestra el botón, así que sólo se llega
     * escribiendo la dirección.
     */
    public function confirmarCancelacion(Peticion $peticion): Respuesta
    {
        [$reserva, $funcion] = $this->servicio->delEspectador(self::usuario($peticion), (string) $peticion->parametro('id'));
        if (!ServicioDeReservas::seCancela($reserva, $funcion)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $this->pantallaDeCancelacion($reserva, $funcion);
    }

    public function cancelar(Peticion $peticion): Respuesta
    {
        $usuario = self::usuario($peticion);
        $id = (string) $peticion->parametro('id');
        try {
            $reserva = $this->servicio->cancelar($usuario, $id);
        } catch (ReglaIncumplida $error) {
            // Por ejemplo, empezó la función o registraron la asistencia con la pantalla abierta.
            [$reserva, $funcion] = $this->servicio->delEspectador($usuario, $id);

            return $this->pantallaDeCancelacion($reserva, $funcion, $error->getMessage(), 422);
        }

        // 303: recargar no la vuelve a mandar.
        return $this->redirigir("/reservas/{$reserva->id}?cancelada=1");
    }

    private function pantallaDeCancelacion(Reserva $reserva, ?FuncionDeProgramacion $funcion, ?string $error = null, int $estado = 200): Respuesta
    {
        $datos = [
            'reserva' => self::datosDeReserva($reserva, $funcion),
            'funcion' => $funcion === null ? null : self::datosDeFuncion($funcion),
        ];
        // La plantilla lo muestra si la variable existe: sólo se pasa cuando hay algo que decir.
        if ($error !== null) {
            $datos['error_general'] = $error;
        }

        return $this->vista('cancelar_reserva.html.twig', $datos, $estado);
    }

    /**
     * @return array{id: string, codigo: string, cantidad: int, total: string, estado: string, cancelable: bool}
     */
    private static function datosDeReserva(Reserva $reserva, ?FuncionDeProgramacion $funcion): array
    {
        return [
            'id' => $reserva->id,
            'codigo' => $reserva->codigo,
            'cantidad' => $reserva->cantidad,
            'total' => TextoParaMostrar::monto((float) $reserva->total),
            'estado' => $reserva->estado->value,
            'cancelable' => ServicioDeReservas::seCancela($reserva, $funcion),
        ];
    }

    /**
     * @param array<string, string> $valores
     * @param array<string, string> $errores
     */
    private function pantalla(FuncionDeProgramacion $funcion, array $valores, array $errores = [], ?string $error = null, int $estado = 200): Respuesta
    {
        $datos = [
            'funcion' => self::datosDeFuncion($funcion),
            // Si ya no se puede, la página lo dice en lugar del formulario.
            'motivo' => $funcion->motivoParaNoReservar(),
            'reglas' => ['cantidad' => ['minimo' => 1, 'maximo' => $funcion->cupo]],
            'valores' => $valores,
            'errores' => $errores,
        ];
        // La plantilla lo muestra si la variable existe: sólo se pasa cuando hay algo que decir.
        if ($error !== null) {
            $datos['error_general'] = $error;
        }

        return $this->vista('reservar.html.twig', $datos, $estado);
    }

    /**
     * @return array{id: string, titulo: string, url: string, fecha: ?string, precio: string, sala: string, localidad: string}
     */
    private static function datosDeFuncion(FuncionDeProgramacion $funcion): array
    {
        return [
            'id' => $funcion->id,
            'titulo' => $funcion->titulo(),
            // La ficha es de programación: va sin el prefijo de funciones.
            'url' => "/cartelera/{$funcion->id}",
            'fecha' => $funcion->fechaHora === null ? null : TextoParaMostrar::fecha($funcion->fechaHora),
            'precio' => TextoParaMostrar::monto($funcion->precioBase),
            'sala' => $funcion->salaNombre,
            'localidad' => $funcion->salaLocalidad,
        ];
    }

    private static function usuario(Peticion $peticion): Usuario
    {
        // La ruta pide sesión: si llegó acá, hay usuario.
        return $peticion->usuario() ?? throw new LogicException('Ruta de espectador sin usuario.');
    }
}
