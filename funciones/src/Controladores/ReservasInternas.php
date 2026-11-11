<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Controladores;

use IndieCinema\Funciones\Servicios\AvisosDeReservas;
use IndieCinema\Funciones\Servicios\ServicioDePanelDeReservas;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Uuid;

/**
 * Lo que programación le avisa a funciones de una función: que se canceló o que terminó.
 *
 * POST /interno/funciones/{id}/cancelar-reservas: programación avisa que canceló una función, y
 * funciones cancela sus reservas confirmadas y les avisa por correo a los espectadores (E4-03.4).
 * Sólo la llama programación, con su token: lo controla la ruta.
 *
 * Programación espera la respuesta 2 s (ClienteInterno), y cada aviso pide la dirección a cuentas
 * y pasa por el SMTP: con unas pocas reservas ya no llegaba. Por eso responde apenas cancela, y
 * los correos salen después (Respuesta::alTerminar). Programación manda el título y la fecha
 * para el correo, así funciones no tiene que volver a pedirle la función.
 */
final class ReservasInternas extends Controlador
{
    public function __construct(
        private readonly ServicioDePanelDeReservas $servicio,
        private readonly AvisosDeReservas $avisos,
    ) {
    }

    /**
     * @throws ExcepcionHttp 404 si el id no es un UUID; 400 si falta quién la canceló o el título
     */
    public function cancelarDeLaFuncion(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }
        $organizador = $peticion->campo('organizador_id');
        $titulo = $peticion->campo('titulo');
        $fecha = $peticion->campo('fecha');
        if (!is_string($organizador) || !Uuid::esValido($organizador)) {
            throw ExcepcionHttp::solicitudInvalida('Falta el id de quién canceló la función, en «organizador_id».');
        }
        if (!is_string($titulo) || trim($titulo) === '' || !($fecha === null || is_string($fecha))) {
            throw ExcepcionHttp::solicitudInvalida('Faltan el título de la función, en «titulo», o la fecha, en «fecha», es inválida.');
        }

        $canceladas = $this->servicio->cancelarDeLaFuncion($id, $organizador);

        return $this->json(['canceladas' => count($canceladas)])
            ->alTerminar(fn (): int => $this->avisos->funcionCancelada($canceladas, $titulo, $fecha));
    }

    /**
     * POST /interno/funciones/{id}/finalizada: programación finalizó la función (EF-04.1), y las
     * reservas confirmadas sin asistencia quedan ausentes. Programación lo pide antes de guardar
     * la función como finalizada, y si no recibe la respuesta lo repite en la próxima pasada.
     *
     * @throws ExcepcionHttp 404 si el id no es un UUID; 400 sin el organizador de la función
     */
    public function finalizada(Peticion $peticion): Respuesta
    {
        $id = (string) $peticion->parametro('id');
        if (!Uuid::esValido($id)) {
            throw ExcepcionHttp::noEncontrada();
        }
        $organizador = $peticion->campo('organizador_id');
        if (!is_string($organizador) || !Uuid::esValido($organizador)) {
            throw ExcepcionHttp::solicitudInvalida('Falta el id del organizador de la función, en «organizador_id».');
        }

        return $this->json(['ausentes' => $this->servicio->marcarAusentes($id, $organizador)]);
    }
}
