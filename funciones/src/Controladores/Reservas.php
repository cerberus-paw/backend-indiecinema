<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Modelo\EstadoReserva;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Funciones\Servicios\AvisosDeReservas;
use IndieCinema\Funciones\Servicios\ServicioDeReservas;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Usuario;
use LogicException;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
    public function __construct(
        private readonly ServicioDeReservas $servicio,
        private readonly AvisosDeReservas $avisos,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * GET /reservas: «Mis reservas» (E4-04.1), con ?pagina=. Una página que no existe es 404, como
     * en el panel del administrador. Si programación no responde, o responde algo que no son
     * funciones, la lista sale igual, con los códigos y sin los datos de las funciones.
     */
    public function mias(Peticion $peticion): Respuesta
    {
        $espectador = self::usuario($peticion);
        $numero = filter_var($peticion->consulta('pagina', '1'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($numero === false) {
            throw ExcepcionHttp::noEncontrada();
        }
        [$reservas, $total] = $this->servicio->pagina($espectador, $numero);
        $paginas = max(1, (int) ceil($total / ServicioDeReservas::POR_PAGINA));
        if ($numero > $paginas) {
            throw ExcepcionHttp::noEncontrada();
        }
        try {
            $funciones = $this->servicio->funcionesDe($reservas);
            $sinProgramacion = false;
        } catch (ExcepcionHttp) {
            // ClienteInterno ya dejó en el log que programación no respondió.
            $funciones = [];
            $sinProgramacion = true;
        } catch (RuntimeException $error) {
            $this->log->error('Sin las funciones de «Mis reservas»: {mensaje}', ['mensaje' => $error->getMessage()]);
            $funciones = [];
            $sinProgramacion = true;
        }

        return $this->vista('mis_reservas.html.twig', [
            'reservas' => array_map(static function (Reserva $reserva) use ($funciones, $sinProgramacion): array {
                $funcion = $funciones[$reserva->funcionId] ?? null;

                return [
                    ...self::datosDeReserva($reserva, $funcion),
                    'estado_etiqueta' => $reserva->estado->etiqueta(),
                    // Sin programación no se sabe si empezó: el botón queda en el comprobante.
                    'cancelable' => !$sinProgramacion && ServicioDeReservas::seCancela($reserva, $funcion),
                    'funcion' => $funcion === null ? null : self::datosDeFuncion($funcion),
                ];
            }, $reservas),
            'sin_programacion' => $sinProgramacion,
            'paginado' => [
                'numero' => $numero,
                'total_paginas' => $paginas,
                'total' => $total,
                'anterior' => $numero > 1 ? self::urlDePagina($numero - 1) : null,
                'siguiente' => $numero < $paginas ? self::urlDePagina($numero + 1) : null,
                'paginas' => array_map(
                    static fn (int $n): array => ['numero' => $n, 'url' => self::urlDePagina($n), 'actual' => $n === $numero],
                    range(1, $paginas),
                ),
            ],
        ]);
    }

    /**
     * Relativa a la página: así no depende del prefijo de funciones, que pone nginx.
     */
    private static function urlDePagina(int $numero): string
    {
        return $numero > 1 ? "?pagina={$numero}" : '?';
    }

    public function formulario(Peticion $peticion): Respuesta
    {
        $funcion = $this->servicio->funcion((string) $peticion->parametro('id'));

        return $this->pantalla($funcion, ['cantidad' => '1']);
    }

    public function reservar(Peticion $peticion): Respuesta
    {
        $funcion = $this->servicio->funcion((string) $peticion->parametro('id'));
        $enJson = $peticion->aceptaJson();
        $valor = $peticion->campo('cantidad');
        $valor = is_string($valor) ? trim($valor) : '';

        // Entero y dentro del cupo, como el min y el max del campo: el navegador controla lo mismo.
        $cantidad = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $funcion->cupo]]);
        if ($cantidad === false) {
            $mensaje = "Ingresá entre 1 y {$funcion->cupo} lugares.";

            return $enJson
                ? $this->json(['errores' => ['cantidad' => $mensaje], ...$this->lugaresDe($funcion)], 422)
                : $this->pantalla($funcion, ['cantidad' => $valor], ['cantidad' => $mensaje], estado: 422);
        }

        // Un error de configuración, antes de guardar nada.
        $this->avisos->comprobarConfiguracion();
        try {
            $reserva = $this->servicio->reservar(self::usuario($peticion), $funcion->id, $cantidad);
        } catch (ReglaIncumplida $error) {
            // El mensaje de la regla es para el espectador («Quedan 2 lugares.»), no interno.
            return $enJson
                ? $this->json(['error' => $error->getMessage(), ...$this->lugaresDe($funcion)], 422)
                : $this->pantalla($funcion, ['cantidad' => $valor], error: $error->getMessage(), estado: 422);
        }

        // 201 con el link al comprobante: lo mismo que la redirección, sin recargar (E4-05.1).
        if ($enJson) {
            return $this->json([
                'reserva' => self::datosDeReserva($reserva, $funcion),
                'url' => $this->ruta("/reservas/{$reserva->id}"),
                ...$this->lugaresDe($funcion),
            ], 201);
        }
        // Ya confirmada: si el correo no sale, la reserva queda igual y el aviso queda en el log.
        $this->avisos->reservaConfirmada($reserva, $funcion);

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
        $enJson = $peticion->aceptaJson();
        try {
            $reserva = $this->servicio->cancelar($usuario, $id);
        } catch (ReglaIncumplida $error) {
            // Por ejemplo, empezó la función o registraron la asistencia con la pantalla abierta.
            [$reserva, $funcion] = $this->servicio->delEspectador($usuario, $id);

            return $enJson
                ? $this->json(['error' => $error->getMessage(), 'reserva' => self::datosDeReserva($reserva, $funcion)], 422)
                : $this->pantallaDeCancelacion($reserva, $funcion, $error->getMessage(), 422);
        }

        // Sin los lugares libres: después de cancelar se va al comprobante, que no los muestra, y
        // traerlos costaría otro pedido interno a programación para un dato que nadie mira.
        if ($enJson) {
            return $this->json([
                'reserva' => ['id' => $reserva->id, 'estado' => $reserva->estado->value, 'cancelable' => false],
                'url' => $this->ruta("/reservas/{$reserva->id}?cancelada=1"),
            ]);
        }

        // 303: recargar no la vuelve a mandar.
        return $this->redirigir("/reservas/{$reserva->id}?cancelada=1");
    }

    /**
     * Los lugares que quedan después de reservar, para que la ficha actualice el número sin
     * volver a preguntar (E4-05.1).
     *
     * @return array{lugares_libres: int}
     */
    private function lugaresDe(FuncionDeProgramacion $funcion): array
    {
        return ['lugares_libres' => $this->servicio->lugaresLibres($funcion)];
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
