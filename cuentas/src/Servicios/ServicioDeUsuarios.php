<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Servicios;

use DateTimeImmutable;
use DateTimeZone;
use IndieCinema\Cuentas\Modelo\EstadoCuenta;
use IndieCinema\Cuentas\Modelo\FiltrosDeUsuarios;
use IndieCinema\Cuentas\Modelo\PaginaDeUsuarios;
use IndieCinema\Cuentas\Modelo\ResumenDeUsuarios;
use IndieCinema\Cuentas\Modelo\UsuarioEnListado;
use IndieCinema\Cuentas\Repositorios\RepositorioDeUsuarios;
use IndieCinema\Nucleo\Configuracion;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Rol;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Nucleo\Uuid;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Los usuarios para el administrador: el listado con la búsqueda y los filtros, por páginas del
 * tamaño que dice config.ini, los números de las tarjetas, dar o quitar el rol de organizador y
 * suspender o reactivar una cuenta.
 * Que quien pide sea administrador lo controla la ruta.
 *
 * Desde la web sólo se da y se quita el de organizador: el de moderador y el de administrador no
 * tienen pantalla, y el primer administrador sale de los datos de prueba.
 */
final class ServicioDeUsuarios
{
    /**
     * Si config.ini no dice otra cosa: con las tarjetas y los filtros arriba, la página entra
     * entera en la pantalla de una notebook.
     */
    private const POR_PAGINA = 5;
    private const MAXIMO_POR_PAGINA = 100;

    /** El motivo de una suspensión, como la columna cambio_estado_cuenta.motivo. */
    public const MAXIMO_MOTIVO = 300;

    private readonly int $porPagina;

    /**
     * @throws RuntimeException si [usuarios] por_pagina no es un número entre 1 y 100
     */
    public function __construct(
        private readonly RepositorioDeUsuarios $usuarios,
        Configuracion $configuracion,
        private readonly LoggerInterface $log,
    ) {
        $porPagina = $configuracion->obtener('usuarios.por_pagina', self::POR_PAGINA);
        if (!is_int($porPagina) || $porPagina < 1 || $porPagina > self::MAXIMO_POR_PAGINA) {
            throw new RuntimeException('[usuarios] por_pagina tiene que ser un número entre 1 y ' . self::MAXIMO_POR_PAGINA . '.');
        }
        $this->porPagina = $porPagina;
    }

    /**
     * @param positive-int $numero
     *
     * @throws ExcepcionHttp 404 si la página no existe
     */
    public function pagina(FiltrosDeUsuarios $filtros, int $numero): PaginaDeUsuarios
    {
        $pagina = $this->usuarios->pagina($filtros, $numero, $this->porPagina);
        if (!$pagina->existe()) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $pagina;
    }

    /**
     * Las opciones del filtro de alta: los años en que se creó alguna cuenta, del más nuevo al más
     * viejo.
     *
     * @return list<int>
     */
    public function aniosDeAlta(): array
    {
        return $this->usuarios->aniosDeAlta();
    }

    /**
     * Los números de las tarjetas, sobre todas las cuentas: no cambian con la búsqueda.
     */
    public function resumen(): ResumenDeUsuarios
    {
        return $this->usuarios->resumen();
    }

    /**
     * El usuario como lo ve el administrador, o null si no existe. También es lo que ve cada uno
     * de su cuenta en «Mi cuenta»: el nombre, el correo, los roles y la fecha de alta.
     */
    public function buscarEnListado(string $id): ?UsuarioEnListado
    {
        return Uuid::esValido($id) ? $this->usuarios->buscarEnListado($id) : null;
    }

    public function buscarPorId(string $id): ?\IndieCinema\Cuentas\Modelo\Usuario
    {
        return Uuid::esValido($id) ? $this->usuarios->buscarPorId($id) : null;
    }

    /**
     * Varios usuarios como los ve el administrador o los otros subsistemas, indexados por id. Los
     * que no existen o no son un UUID válido se ignoran.
     *
     * @param list<string> $ids
     *
     * @return array<string, UsuarioEnListado>
     */
    public function buscarVarios(array $ids): array
    {
        $validos = array_values(array_unique(array_filter($ids, Uuid::esValido(...))));
        if ($validos === []) {
            return [];
        }

        return $this->usuarios->buscarVariosEnListado($validos);
    }

    /**
     * «Guardar cambios» de la tabla: deja a cada usuario de espectador, de organizador o de
     * moderador (EF-07.3), el rol mayor que se ve en el desplegable. Al administrador no se lo
     * cambia desde la web, y su desplegable está apagado.
     *
     * Como el moderador alcanza a organizador, quitarle el de moderador lo deja en el que se eligió:
     * de organizador conserva «Mis salas» (si no tenía el rol guardado, se le da); de espectador
     * pierde los dos. Cada usuario cambia en una sola transacción, que cierra sus sesiones, y cada
     * rol que se da o se quita queda en el log: es el registro de auditoría.
     *
     * Primero se revisan todos: con uno que no se puede hacer, no se cambia ninguno. El que ya
     * tiene ese rol no cambia ni se registra.
     *
     * @param array<string, Rol> $cambios id del usuario => el rol que le eligieron
     *
     * @return array<string, array{antes: Rol, despues: Rol}> los que cambiaron
     *
     * @throws ExcepcionHttp 404 si un usuario no existe; 400 si un rol no es espectador,
     *                       organizador ni moderador, o si un usuario es administrador
     */
    public function cambiarRoles(array $cambios, Usuario $administrador): array
    {
        $cambiables = [Rol::Espectador, Rol::Organizador, Rol::Moderador];
        $hechos = [];
        $usuarios = [];
        foreach ($cambios as $id => $rol) {
            $usuario = $this->buscar($id);
            $actual = $usuario->mayorRol();
            if (!in_array($rol, $cambiables, true) || !in_array($actual, $cambiables, true)) {
                throw ExcepcionHttp::solicitudInvalida('Desde la web sólo se elige espectador, organizador o moderador.');
            }
            if ($rol !== $actual) {
                $hechos[$id] = ['antes' => $actual, 'despues' => $rol];
                $usuarios[$id] = $usuario;
            }
        }
        foreach ($hechos as $id => $cambio) {
            $this->dejarConRolMayor($usuarios[$id], $cambio['despues'], $administrador);
        }

        return $hechos;
    }

    /**
     * Le da y le quita los roles que hacen falta para que $rol sea el mayor, en una transacción
     * que cierra sus sesiones, y registra cada uno.
     */
    private function dejarConRolMayor(UsuarioEnListado $usuario, Rol $rol, Usuario $administrador): void
    {
        $tiene = static fn (Rol $buscado): bool => in_array($buscado, $usuario->roles, true);
        [$agregar, $quitar] = match ($rol) {
            Rol::Moderador => [[Rol::Moderador], []],
            Rol::Organizador => [$tiene(Rol::Organizador) ? [] : [Rol::Organizador], [Rol::Moderador]],
            default => [[], [Rol::Moderador, Rol::Organizador]],
        };
        $quitar = array_values(array_filter($quitar, $tiene));
        $this->usuarios->cambiarRoles($usuario->id, $agregar, $quitar);
        foreach ($quitar as $quitado) {
            $this->log->notice("{administrador} le quitó el rol de {$quitado->value} a {usuario}.", ['administrador' => $administrador->id, 'usuario' => $usuario->id]);
        }
        foreach ($agregar as $agregado) {
            $this->log->notice("{administrador} le dio el rol de {$agregado->value} a {usuario}.", ['administrador' => $administrador->id, 'usuario' => $usuario->id]);
        }
    }

    /**
     * Le da el rol de organizador, además del que tiene. Si ya lo tiene, o uno mayor que lo
     * incluye, no cambia nada ni se registra (dos pestañas, o reenviar el POST).
     *
     * El repositorio cierra sus sesiones en la misma transacción, así el rol nuevo se ve en el
     * próximo ingreso, y el cambio queda en el log con quién lo hizo: es el registro de auditoría.
     *
     * @param string $administradorId quién lo da: el administrador desde «Usuarios y roles», o el
     *                                que aprobó la solicitud de sala en programación, que llama
     *                                por la API interna sin su sesión
     *
     * @throws ExcepcionHttp 404 si el usuario no existe
     */
    public function darOrganizador(string $id, string $administradorId): UsuarioEnListado
    {
        $usuario = $this->buscar($id);
        if ($usuario->mayorRol()->alcanza(Rol::Organizador)) {
            return $usuario;
        }
        $this->usuarios->agregarRol($usuario->id, Rol::Organizador);
        $this->log->notice('{administrador} le dio el rol de organizador a {usuario}.', [
            'administrador' => $administradorId,
            'usuario' => $usuario->id,
        ]);

        return $usuario;
    }

    /**
     * @throws ExcepcionHttp 404 si no existe, también si el id no tiene forma de id
     */
    /**
     * Suspende la cuenta con un motivo (EF-14.3): no ingresa más y pierde sus sesiones. Una
     * pendiente también se suspende (una cuenta de spam que nunca verificó), y al reactivarla
     * vuelve a pendiente. Queda en cambio_estado_cuenta y en el log.
     *
     * @return bool si la suspendió; false si ya estaba suspendida
     *
     * @throws ExcepcionHttp 404 si no existe; 400 si es la propia, la de un administrador o falta el motivo
     */
    public function suspender(string $id, string $motivo, Usuario $administrador, ?DateTimeImmutable $ahora = null): bool
    {
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > self::MAXIMO_MOTIVO) {
            throw ExcepcionHttp::solicitudInvalida('Escribí el motivo de la suspensión, de hasta ' . self::MAXIMO_MOTIVO . ' caracteres.');
        }
        $usuario = $this->suspendible($id, $administrador);
        if ($usuario->estado === EstadoCuenta::Suspendida) {
            return false;
        }
        $hecho = $this->usuarios->cambiarEstadoRegistrado($id, $usuario->estado, EstadoCuenta::Suspendida, $motivo, $administrador->id, self::ahora($ahora));
        if ($hecho) {
            $this->log->notice('{administrador} suspendió a {usuario}: {motivo}', ['administrador' => $administrador->id, 'usuario' => $id, 'motivo' => $motivo]);
        }

        return $hecho;
    }

    /**
     * Le levanta la suspensión: vuelve al estado que tenía antes (activa o pendiente) y, sin
     * registro de cómo estaba, a activa. Puede volver a ingresar.
     *
     * @return bool si la reactivó; false si no estaba suspendida
     *
     * @throws ExcepcionHttp 404 si no existe; 400 si es la propia o la de un administrador
     */
    public function reactivar(string $id, Usuario $administrador, ?DateTimeImmutable $ahora = null): bool
    {
        $usuario = $this->suspendible($id, $administrador);
        if ($usuario->estado !== EstadoCuenta::Suspendida) {
            return false;
        }
        $vuelve = $this->usuarios->suspensiones([$id])[$id]['anterior'] ?? EstadoCuenta::Activa;
        $hecho = $this->usuarios->cambiarEstadoRegistrado($id, EstadoCuenta::Suspendida, $vuelve, null, $administrador->id, self::ahora($ahora));
        if ($hecho) {
            $this->log->notice('{administrador} reactivó a {usuario}.', ['administrador' => $administrador->id, 'usuario' => $id]);
        }

        return $hecho;
    }

    /**
     * El motivo y la fecha de la última suspensión de las cuentas suspendidas de la lista.
     *
     * @param list<string> $ids
     *
     * @return array<string, array{motivo: ?string, anterior: EstadoCuenta, desde: DateTimeImmutable}>
     */
    public function suspensiones(array $ids): array
    {
        return $this->usuarios->suspensiones($ids);
    }

    /**
     * Si el administrador puede cambiarle el estado: ni a sí mismo (se quedaría afuera) ni a otro
     * administrador, que no tiene quien lo reactive desde la web.
     */
    public static function sePuedeSuspender(UsuarioEnListado $usuario, Usuario $administrador): bool
    {
        return $usuario->id !== $administrador->id && !in_array(Rol::Administrador, $usuario->roles, true);
    }

    /**
     * @throws ExcepcionHttp 404 si no existe; 400 si no se le puede cambiar el estado
     */
    private function suspendible(string $id, Usuario $administrador): UsuarioEnListado
    {
        $usuario = $this->buscar($id);
        if (!self::sePuedeSuspender($usuario, $administrador)) {
            throw ExcepcionHttp::solicitudInvalida($usuario->id === $administrador->id
                ? 'No podés suspender tu propia cuenta.'
                : 'A un administrador no se lo suspende desde la web.');
        }

        return $usuario;
    }

    private static function ahora(?DateTimeImmutable $ahora): DateTimeImmutable
    {
        return ($ahora ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    }

    private function buscar(string $id): UsuarioEnListado
    {
        return $this->buscarEnListado($id) ?? throw ExcepcionHttp::noEncontrada();
    }
}
