<?php

declare(strict_types=1);

namespace IndieCinema\Funciones\Servicios;

use IndieCinema\Funciones\Controladores\TextoParaMostrar;
use IndieCinema\Funciones\Modelo\FuncionDeProgramacion;
use IndieCinema\Funciones\Modelo\Reserva;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Correo\ClienteSmtp;
use IndieCinema\Nucleo\Correo\Correo;
use IndieCinema\Nucleo\Correo\CorreoNoEnviado;
use IndieCinema\Nucleo\Http\ClienteInterno;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Los correos a los espectadores: la reserva confirmada (E4-04.3), la función que reservaron se
 * canceló (E4-03.4), la que esperaban no juntó el mínimo del acuerdo de fecha (EF-03.2) y el
 * recordatorio del día antes (EF-03.3). Funciones sólo guarda el id de quien reservó: la dirección se la pide a
 * cuentas por su API interna.
 *
 * El correo es un aviso y no lo que confirma ni lo que cancela: la reserva ya quedó así. Si cuentas
 * no responde o el correo no sale, queda en el log y se sigue.
 */
final class AvisosDeReservas
{
    public function __construct(
        private readonly ClienteInterno $cliente,
        private readonly ClienteSmtp $correo,
        private readonly LoggerInterface $log,
        private readonly Configuracion $configuracion,
    ) {
    }

    /**
     * Que se va a poder armar el enlace del correo de la reserva confirmada. Se llama antes de
     * reservar: si falla después, el espectador vería un error con la reserva ya hecha.
     *
     * @throws RuntimeException si falta [sitio] url o no es http(s)
     */
    public function comprobarConfiguracion(): void
    {
        $this->urlDelSitio();
    }

    /**
     * El correo con el comprobante, apenas se confirma la reserva. Hoy es al reservar; con el pago
     * (X-01) será cuando llegue el aviso del pago verificado.
     *
     * @return bool si salió
     *
     * @throws RuntimeException si falta [sitio] url o no es http(s): es un error de configuración,
     *                          no del correo
     */
    public function reservaConfirmada(Reserva $reserva, FuncionDeProgramacion $funcion): bool
    {
        // Antes de mandar nada: sin la URL, el enlace al comprobante saldría roto.
        $comprobante = $this->urlDelSitio() . $this->configuracion->obtener('app.prefijo', '') . "/reservas/{$reserva->id}";
        try {
            [$correo, $nombre] = $this->destinatario($reserva->usuarioId);
            $this->correo->enviar(new Correo($correo, "Reserva confirmada: «{$funcion->titulo()}»", self::textoDeConfirmada($reserva, $funcion, $nombre, $comprobante)));

            return true;
        } catch (ExcepcionHttp | CorreoNoEnviado | RuntimeException $error) {
            // Sin la dirección ni el nombre: el log no guarda datos personales.
            $this->log->warning('No salió el correo de la reserva confirmada {reserva}: {motivo}', [
                'reserva' => $reserva->id,
                'motivo' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param list<Reserva> $reservas las que se cancelaron con la función
     * @param ?string       $fecha    ya escrita («Sáb 19 sep · 20:30»), o null si no tenía
     *
     * @return int a cuántos les salió el aviso
     */
    public function funcionCancelada(array $reservas, string $titulo, ?string $fecha): int
    {
        $avisados = 0;
        foreach ($reservas as $reserva) {
            try {
                [$correo, $nombre] = $this->destinatario($reserva->usuarioId);
                $this->correo->enviar(new Correo($correo, "Se canceló la función «{$titulo}»", self::texto($reserva, $nombre, $titulo, $fecha)));
                ++$avisados;
            } catch (ExcepcionHttp | CorreoNoEnviado | RuntimeException $error) {
                // Sin la dirección ni el nombre: el log no guarda datos personales.
                $this->log->warning('No salió el aviso de la cancelación de la reserva {reserva}: {motivo}', [
                    'reserva' => $reserva->id,
                    'motivo' => $error->getMessage(),
                ]);
            }
        }

        return $avisados;
    }

    /**
     * El recordatorio el día antes de la función (EF-03.3), con lo que hace falta para llegar: la
     * fecha y la hora, la sala con su dirección y el código para la puerta.
     *
     * @return bool si salió
     *
     * @throws RuntimeException si falta [sitio] url o no es http(s): es un error de configuración,
     *                          no del correo
     */
    public function recordatorio(Reserva $reserva, FuncionDeProgramacion $funcion): bool
    {
        $comprobante = $this->urlDelSitio() . $this->configuracion->obtener('app.prefijo', '') . "/reservas/{$reserva->id}";
        try {
            [$correo, $nombre] = $this->destinatario($reserva->usuarioId);
            $this->correo->enviar(new Correo($correo, "Recordatorio: «{$funcion->titulo()}»", self::textoDeRecordatorio($reserva, $funcion, $nombre, $comprobante)));

            return true;
        } catch (ExcepcionHttp | CorreoNoEnviado | RuntimeException $error) {
            $this->log->warning('No salió el recordatorio de la reserva {reserva}: {motivo}', [
                'reserva' => $reserva->id,
                'motivo' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * El correo a los que marcaron «Me sirve» cuando el acuerdo de fecha venció sin que ninguna
     * franja llegara al mínimo y la función se canceló (EF-03.2). No tenían reserva todavía: el
     * aviso es para que no la esperen.
     *
     * @param list<string> $usuariosIds
     *
     * @return int a cuántos les salió el aviso
     */
    public function acuerdoSinMinimo(array $usuariosIds, string $titulo, int $minimo): int
    {
        return $this->acuerdoCancelado($usuariosIds, $titulo, 'sin mínimo', static fn (string $nombre): string => self::textoDeSinMinimo($nombre, $titulo, $minimo));
    }

    /**
     * Lo mismo cuando la tarea canceló la función porque la franja ganadora se pisaba con otra
     * función de la sala (EF-05.5): el correo dice eso, y no que faltó gente.
     *
     * @param list<string> $usuariosIds
     *
     * @return int a cuántos les salió
     */
    public function acuerdoConHorarioOcupado(array $usuariosIds, string $titulo): int
    {
        return $this->acuerdoCancelado($usuariosIds, $titulo, 'con el horario ocupado', static fn (string $nombre): string => self::textoDeHorarioOcupado($nombre, $titulo));
    }

    /**
     * @param list<string>             $usuariosIds
     * @param callable(string): string $texto       el cuerpo, con el nombre de cada uno
     */
    private function acuerdoCancelado(array $usuariosIds, string $titulo, string $cual, callable $texto): int
    {
        $avisados = 0;
        foreach ($usuariosIds as $usuarioId) {
            try {
                [$correo, $nombre] = $this->destinatario($usuarioId);
                $this->correo->enviar(new Correo($correo, "Se canceló la función «{$titulo}»", $texto($nombre)));
                ++$avisados;
            } catch (ExcepcionHttp | CorreoNoEnviado | RuntimeException $error) {
                $this->log->warning("No salió el aviso del acuerdo {$cual} a {usuario}: {motivo}", [
                    'usuario' => $usuarioId,
                    'motivo' => $error->getMessage(),
                ]);
            }
        }

        return $avisados;
    }

    /**
     * @return array{0: string, 1: string} la dirección y el nombre
     *
     * @throws ExcepcionHttp    503 si cuentas no responde
     * @throws RuntimeException si el usuario ya no existe o cuentas responde otra cosa
     */
    private function destinatario(string $usuarioId): array
    {
        $datos = $this->cliente->get('cuentas', "/interno/usuarios/{$usuarioId}");
        if (!is_array($datos) || !is_string($datos['correo'] ?? null) || !is_string($datos['nombre'] ?? null)) {
            throw new RuntimeException('cuentas no respondió el usuario de la reserva.');
        }

        return [$datos['correo'], $datos['nombre']];
    }

    /**
     * [sitio] url sin la barra final, como https://indiecinema.com.ar.
     *
     * @throws RuntimeException si falta o no es http(s)
     */
    private function urlDelSitio(): string
    {
        return DireccionDelSitio::de($this->configuracion);
    }

    private static function textoDeConfirmada(Reserva $reserva, FuncionDeProgramacion $funcion, string $nombre, string $comprobante): string
    {
        $lugares = $reserva->cantidad === 1 ? '1 lugar' : "{$reserva->cantidad} lugares";

        return implode("\n", [
            "Hola, {$nombre}:",
            '',
            "Tu reserva para «{$funcion->titulo()}» está confirmada.",
            '',
            'Código de la reserva: ' . substr($reserva->codigo, 0, 4) . '-' . substr($reserva->codigo, 4),
            'Fecha: ' . ($funcion->fechaHora === null ? 'a confirmar' : TextoParaMostrar::fecha($funcion->fechaHora)),
            "Sala: {$funcion->salaNombre} · {$funcion->salaLocalidad}",
            "Lugares: {$lugares}",
            'Total: ' . TextoParaMostrar::monto((float) $reserva->total),
            '',
            'Mostrá el código en la puerta. El comprobante, para verlo o imprimirlo:',
            $comprobante,
            '',
            'IndieCinema',
        ]);
    }

    private static function texto(Reserva $reserva, string $nombre, string $titulo, ?string $fecha): string
    {
        $lugares = $reserva->cantidad === 1 ? '1 lugar' : "{$reserva->cantidad} lugares";

        return implode("\n", [
            "Hola, {$nombre}:",
            '',
            "El organizador canceló la función «{$titulo}»" . ($fecha === null ? '' : " del {$fecha}") . '.',
            "Tu reserva {$reserva->codigo} ({$lugares}) quedó cancelada: no tenés que hacer nada.",
            '',
            'En la cartelera de IndieCinema podés elegir otra función.',
            '',
            'IndieCinema',
        ]);
    }

    private static function textoDeRecordatorio(Reserva $reserva, FuncionDeProgramacion $funcion, string $nombre, string $comprobante): string
    {
        $lugares = $reserva->cantidad === 1 ? '1 lugar' : "{$reserva->cantidad} lugares";
        $direccion = $funcion->salaDireccion === '' ? $funcion->salaLocalidad : "{$funcion->salaDireccion}, {$funcion->salaLocalidad}";

        return implode("\n", [
            "Hola, {$nombre}:",
            '',
            "Te recordamos tu función: «{$funcion->titulo()}».",
            '',
            'Fecha: ' . ($funcion->fechaHora === null ? 'a confirmar' : TextoParaMostrar::fecha($funcion->fechaHora)),
            "Sala: {$funcion->salaNombre}",
            "Dirección: {$direccion}",
            "Lugares: {$lugares}",
            'Código de la reserva: ' . substr($reserva->codigo, 0, 4) . '-' . substr($reserva->codigo, 4),
            '',
            'Mostrá el código en la puerta. El comprobante, para verlo o imprimirlo:',
            $comprobante,
            '',
            'Si al final no podés ir, cancelá la reserva desde «Mis reservas»: así otro usa el lugar.',
            '',
            'IndieCinema',
        ]);
    }

    private static function textoDeHorarioOcupado(string $nombre, string $titulo): string
    {
        return implode("\n", [
            "Hola, {$nombre}:",
            '',
            "Marcaste que te servía una fecha para «{$titulo}», pero el horario que eligieron se superpuso con otra función de la sala.",
            'La función se canceló: no se programa y no tenés que hacer nada.',
            '',
            'En la cartelera de IndieCinema podés elegir otra función.',
            '',
            'IndieCinema',
        ]);
    }

    private static function textoDeSinMinimo(string $nombre, string $titulo, int $minimo): string
    {
        $asistentes = $minimo === 1 ? '1 asistente' : "{$minimo} asistentes";

        return implode("\n", [
            "Hola, {$nombre}:",
            '',
            "Marcaste que te servía una fecha para «{$titulo}», pero al llegar la fecha límite ninguna franja juntó el mínimo de {$asistentes}.",
            'La función se canceló: no se programa y no tenés que hacer nada.',
            '',
            'En la cartelera de IndieCinema podés elegir otra función.',
            '',
            'IndieCinema',
        ]);
    }
}
