<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\ReglaIncumplida;
use IndieCinema\Funciones\Servicios\ServicioDeReservas;
use IndieCinema\Nucleo\Controlador;
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
            'reserva' => [
                'codigo' => $reserva->codigo,
                'cantidad' => $reserva->cantidad,
                'total' => TextoParaMostrar::monto((float) $reserva->total),
                'estado' => $reserva->estado->value,
            ],
            'funcion' => $funcion === null ? null : self::datosDeFuncion($funcion),
        ]);
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
