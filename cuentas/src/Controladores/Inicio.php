<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Controladores;

use IndieCinema\Cuentas\Modelo\UsuarioEnListado;
use IndieCinema\Cuentas\Repositorios\RepositorioDeSeguimientos;
use IndieCinema\Cuentas\Servicios\SalasDeProgramacion;
use IndieCinema\Cuentas\Servicios\ServicioDeUsuarios;
use IndieCinema\Cuentas\Servicios\SolicitudesDeModeracion;
use IndieCinema\Nucleo\Controlador;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Http\Peticion;
use IndieCinema\Nucleo\Http\Respuesta;
use IndieCinema\Nucleo\Seguridad\Rol;
use LogicException;

/**
 * GET /cuenta/: «Mi cuenta», como el wireframe mi-cuenta-mis-datos (E4-10.13): la tarjeta con el
 * nombre, desde cuándo tiene la cuenta y los roles, y «Mis datos» con el nombre y el correo, sólo
 * de lectura (editarlos y cambiar la contraseña todavía no tienen ruta). Los datos salen de la
 * cuenta y no de las cabeceras de nginx, que traen sólo el nombre y el mayor rol.
 *
 * Si el usuario mandó alguna solicitud de sala, «Mi solicitud de sala» muestra en qué quedó la
 * última (EF-07.6), según moderación.
 */
final class Inicio extends Controlador
{
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public function __construct(
        private readonly ServicioDeUsuarios $usuarios,
        private readonly SolicitudesDeModeracion $solicitudes,
        private readonly RepositorioDeSeguimientos $seguimientos,
        private readonly SalasDeProgramacion $salas,
    ) {
    }

    /**
     * @throws ExcepcionHttp 404 si la cuenta de la sesión ya no existe
     */
    public function mostrar(Peticion $peticion): Respuesta
    {
        $usuario = $peticion->usuario() ?? throw new LogicException('La ruta pide sesión: sin ella no llega acá.');
        $cuenta = $this->usuarios->buscarEnListado($usuario->id) ?? throw ExcepcionHttp::noEncontrada();
        $registro = $this->usuarios->buscarPorId($usuario->id);
        $verificado = $registro?->correoVerificadoEn !== null;

        $datos = self::datosDeMiCuenta($cuenta, $verificado, $peticion->consulta('reenviado') === '1');
        // La plantilla muestra el bloque si la variable existe: sin solicitud, o si moderación no
        // responde, no se pasa.
        $solicitud = $this->solicitudes->ultimaDe($cuenta->id);
        if ($solicitud !== null) {
            $datos['solicitud_de_sala'] = [
                'estado' => $solicitud['estado'],
                'sala' => $solicitud['sala'],
                'motivo' => $solicitud['motivo'],
                'fecha' => $solicitud['fecha']->format('d/m/Y'),
            ];
        }

        $datos['seguidos'] = $this->seguidos($cuenta->id);

        return $this->vista('mi_cuenta.html.twig', $datos);
    }

    /**
     * «Lo que seguís» (EF-15.4): las salas, con su nombre y localidad desde programación, y los
     * organizadores, con el nombre de su cuenta. Lo que ya no existe no se muestra. Si
     * programación no responde, las salas no se listan y la página lo avisa.
     *
     * @return array{salas: list<array{id: string, nombre: string, localidad: string}>, organizadores: list<array{id: string, nombre: string}>, salas_sin_datos: bool}
     */
    private function seguidos(string $usuarioId): array
    {
        $ids = $this->seguimientos->deUsuario($usuarioId);
        $datosDeSalas = $this->salas->deVarias($ids['sala']);
        $salas = [];
        foreach ($ids['sala'] as $id) {
            if (isset($datosDeSalas[$id])) {
                $salas[] = ['id' => $id] + $datosDeSalas[$id];
            }
        }
        $cuentas = $this->usuarios->buscarVarios($ids['organizador']);
        $organizadores = [];
        foreach ($ids['organizador'] as $id) {
            if (isset($cuentas[$id])) {
                $organizadores[] = ['id' => $id, 'nombre' => $cuentas[$id]->nombre];
            }
        }

        return [
            'salas' => $salas,
            'organizadores' => $organizadores,
            'salas_sin_datos' => $datosDeSalas === null && $ids['sala'] !== [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function datosDeMiCuenta(
        UsuarioEnListado $cuenta,
        bool $correoVerificado,
        bool $verificacionReenviada = false,
        ?string $errorVerificacion = null,
    ): array {
        return [
            'cuenta' => [
                'nombre' => $cuenta->nombre,
                'correo' => $cuenta->correo,
                'roles' => array_map(static fn (Rol $rol): string => $rol->value, $cuenta->roles),
                // «Desde marzo de 2025», como el wireframe.
                'desde' => self::MESES[(int) $cuenta->creadoEn->format('n') - 1] . ' de ' . $cuenta->creadoEn->format('Y'),
                'correo_verificado' => $correoVerificado,
            ],
            'verificacion_reenviada' => $verificacionReenviada,
            'error_verificacion' => $errorVerificacion,
        ];
    }
}
