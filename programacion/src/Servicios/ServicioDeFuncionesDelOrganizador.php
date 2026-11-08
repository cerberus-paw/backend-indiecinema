<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Servicios;

use DateTimeImmutable;
use LogicException;
use RuntimeException;
use IndieCinema\Nucleo\Http\ExcepcionHttp;
use IndieCinema\Nucleo\Seguridad\Usuario;
use IndieCinema\Programacion\Modelo\EstadoFuncion;
use IndieCinema\Programacion\Modelo\Funcion;
use IndieCinema\Programacion\Modelo\FuncionDelOrganizador;
use IndieCinema\Programacion\Modelo\Obra;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\Sala;
use IndieCinema\Programacion\Repositorios\RepositorioDeFunciones;
use IndieCinema\Programacion\Repositorios\RepositorioDeObras;
use IndieCinema\Programacion\Repositorios\RepositorioDeSalas;
use IndieCinema\Programacion\Repositorios\RepositorioDeVotos;

/**
 * El panel de funciones del organizador: lo que ve de las funciones de sus salas y el alta
 * (E3-11.2), la edición y la cancelación.
 *
 * No es ServicioDeFunciones, que responde la API interna a los otros subsistemas: acá las reglas
 * son las del organizador y quién es el dueño.
 */
final class ServicioDeFuncionesDelOrganizador
{
    public function __construct(
        private readonly RepositorioDeFunciones $funciones,
        private readonly RepositorioDeSalas $salas,
        private readonly RepositorioDeObras $obras,
        private readonly RepositorioDeVotos $votos,
        private readonly ?AcuerdosDeFunciones $acuerdos = null,
    ) {
    }

    /**
     * Las funciones de las salas del organizador. Que sean sólo las suyas no lo decide la ruta,
     * que únicamente controla el rol: lo decide la consulta, con su id.
     *
     * @return list<FuncionDelOrganizador>
     */
    public function listar(Usuario $organizador): array
    {
        return $this->funciones->delOrganizador($organizador->id);
    }

    /**
     * El acuerdo de fecha de cada función que está en acuerdo, para que «Mis funciones» muestre
     * el paso que sigue (E3-13). Un pedido por función: son pocas a la vez, y funciones no tiene
     * una consulta de varias. Si funciones no responde, la lista sale igual, sin ese dato.
     *
     * @param list<FuncionDelOrganizador> $funciones
     *
     * @return array<string, array<string, mixed>|false|null> por id: el acuerdo, null si todavía
     *                                                         no tiene, false si no se supo
     */
    public function acuerdosDe(array $funciones): array
    {
        $acuerdos = [];
        foreach ($funciones as $funcion) {
            if ($funcion->estado !== EstadoFuncion::EnAcuerdoFecha) {
                continue;
            }
            try {
                // null es que no tiene acuerdo: no confundirlo con no haber podido preguntar.
                $acuerdos[$funcion->id] = $this->acuerdos === null ? false : $this->acuerdos->obtenerPorFuncion($funcion->id);
            } catch (ExcepcionHttp | RuntimeException) {
                $acuerdos[$funcion->id] = false;
            }
        }

        return $acuerdos;
    }

    /**
     * Las salas del organizador que están habilitadas para programar funciones.
     *
     * @return list<Sala>
     */
    public function salasHabilitadas(Usuario $organizador): array
    {
        return array_values(array_filter(
            $this->salas->delOrganizador($organizador->id),
            static fn (Sala $sala): bool => $sala->habilitada,
        ));
    }

    /**
     * El catálogo de obras disponibles para proyectar.
     *
     * @return list<Obra>
     */
    public function catalogoDeObras(): array
    {
        return $this->obras->todas();
    }

    /**
     * Da de alta y programa una nueva función en una sala propia habilitada (E3-11.2), con los
     * parámetros de la sala: cuántas obras, cuánto duran y que no se pise con otra función.
     *
     * @param list<string> $obrasIds
     *
     * @throws ReglaIncumplida si la sala no es suya, si una obra no está en el catálogo o si la
     *                         sala no admite la función
     */
    public function programar(
        Usuario $organizador,
        string $salaId,
        array $obrasIds,
        int $cupo,
        float $precioBase,
        DateTimeImmutable $fechaHora,
    ): Funcion {
        $sala = $this->salas->buscar($salaId);
        if ($sala === null || !$sala->esDe($organizador->id)) {
            throw new ReglaIncumplida('La sala no existe o no pertenece a tu cuenta.');
        }

        $duraciones = array_column($this->obras->todas(), 'duracionMinutos', 'id');
        $duracion = 0;
        foreach ($obrasIds as $obraId) {
            $duracion += $duraciones[$obraId] ?? throw new ReglaIncumplida('Una de las obras no está en el catálogo.');
        }

        $funcion = $sala->programarFuncion($obrasIds, $cupo, $precioBase, $fechaHora, $duracion, $this->funciones->ocupadoEnLaSala($sala->id));
        $this->funciones->agregar($funcion);

        return $funcion;
    }

    /**
     * Da de alta una nueva función en votación de títulos (E3-12.1), sin fecha todavía.
     *
     * @param list<string> $obrasIds
     *
     * @throws ReglaIncumplida si la sala no es suya o una obra no está en el catálogo
     */
    public function crearEnVotacionDeTitulos(
        Usuario $organizador,
        string $salaId,
        array $obrasIds,
        int $cupo,
        float $precioBase,
    ): Funcion {
        $sala = $this->salas->buscar($salaId);
        if ($sala === null || !$sala->esDe($organizador->id)) {
            throw new ReglaIncumplida('La sala no existe o no pertenece a tu cuenta.');
        }

        $idsCatalogo = array_column($this->obras->todas(), 'id');
        if (array_diff($obrasIds, $idsCatalogo) !== []) {
            throw new ReglaIncumplida('Una de las obras no está en el catálogo.');
        }

        $funcion = $sala->crearFuncion($obrasIds, $cupo, $precioBase);
        $this->funciones->agregar($funcion);

        return $funcion;
    }

    /**
     * La función para editarla, si es de una sala del organizador y se puede editar
     * (Funcion::seEdita(): está programada y todavía no se dio). Si no, la respuesta es 404, como
     * en propia(): el panel y la ficha no le muestran el botón, así que la única forma de llegar
     * es escribiendo la dirección, y no tiene sentido abrir un formulario que no va a poder
     * guardar (E3-11.3).
     *
     * @throws ExcepcionHttp 404
     */
    public function paraEditar(Usuario $organizador, string $id): Funcion
    {
        $funcion = $this->propia($organizador, $id);
        if (!Funcion::seEdita($funcion->estado, $funcion->fechaHora)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $funcion;
    }

    /**
     * Actualiza una función propia del organizador (E3-11.3).
     *
     * @param list<string> $obrasIds
     *
     * @throws ExcepcionHttp 404 si la función no existe o la sala no es del organizador
     * @throws ReglaIncumplida si los datos no cumplen las reglas de la sala o de la función
     */
    public function actualizar(
        Usuario $organizador,
        string $funcionId,
        string $nuevaSalaId,
        array $obrasIds,
        int $cupo,
        float $precioBase,
        DateTimeImmutable $fechaHora,
    ): Funcion {
        $funcion = $this->paraEditar($organizador, $funcionId);

        $sala = $this->salas->buscar($nuevaSalaId);
        if ($sala === null || !$sala->esDe($organizador->id)) {
            throw new ReglaIncumplida('La sala no existe o no pertenece a tu cuenta.');
        }

        $duraciones = array_column($this->obras->todas(), 'duracionMinutos', 'id');
        $duracion = 0;
        foreach ($obrasIds as $obraId) {
            $duracion += $duraciones[$obraId] ?? throw new ReglaIncumplida('Una de las obras no está en el catálogo.');
        }

        $ocupado = $this->funciones->ocupadoEnLaSala($sala->id, $funcion->id);
        $sala->actualizarFuncion($funcion, $obrasIds, $cupo, $precioBase, $fechaHora, $duracion, $ocupado);
        $this->funciones->actualizar($funcion);

        return $funcion;
    }

    /**
     * La función para la pantalla que confirma la cancelación, como la muestra el panel (E3-11.4).
     * Como en paraEditar(), es 404 si no es del organizador o no se puede cancelar
     * (Funcion::seCancela()).
     *
     * @throws ExcepcionHttp 404
     */
    public function paraCancelar(Usuario $organizador, string $id): FuncionDelOrganizador
    {
        $funcion = $this->funciones->delOrganizadorPorId($organizador->id, $id);
        if ($funcion === null || !Funcion::seCancela($funcion->estado, $funcion->fechaHora)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $funcion;
    }

    /**
     * Cancela una función propia del organizador: es una baja lógica, la función queda en su panel
     * pero sale de la cartelera (E3-11.4).
     *
     * @return Funcion la función como estaba antes de cancelarla, para deshacerCancelacion()
     *
     * @throws ExcepcionHttp 404 si no es del organizador o no se puede cancelar
     */
    public function cancelar(Usuario $organizador, string $id): Funcion
    {
        $funcion = $this->propia($organizador, $id);
        if (!Funcion::seCancela($funcion->estado, $funcion->fechaHora)) {
            throw ExcepcionHttp::noEncontrada();
        }

        $anterior = clone $funcion;
        $funcion->cancelar();
        $this->funciones->guardar($funcion);

        return $anterior;
    }

    /**
     * Vuelve la función a como estaba antes de cancelarla, cuando no se pudieron cancelar sus
     * reservas porque funciones no respondió (F-01.7). Se cancela primero y se deshace si hace
     * falta, y no al revés, porque una función cancelada ya no admite reservas: si se cancelaran
     * primero las reservas, en el medio podía entrar una nueva y quedar confirmada en una función
     * cancelada. La máquina de estados no deja salir de «cancelada», y está bien que no lo deje
     * ningún otro camino: esto no es un cambio de estado sino deshacer uno que no terminó.
     */
    public function deshacerCancelacion(Funcion $anterior): void
    {
        $this->funciones->guardar($anterior);
    }

    /**
     * Cierra la votación de títulos de una función propia con la obra más votada (E3-12.4).
     * La función pasa a estado «en acuerdo de fecha» y conserva únicamente la obra ganadora.
     *
     * @throws ExcepcionHttp 404 si la función no existe, es de otro organizador o no está en votación de títulos
     */
    public function cerrarVotacion(Usuario $organizador, string $id): Funcion
    {
        $funcion = $this->propia($organizador, $id);
        if ($funcion->estado !== EstadoFuncion::EnVotacionTitulos) {
            throw ExcepcionHttp::noEncontrada();
        }

        $obraGanadora = $this->votos->obraMasVotada($funcion->id, $funcion->obrasIds);
        $funcion->cerrarVotacionTitulos([$obraGanadora]);
        $this->funciones->guardar($funcion);

        return $funcion;
    }

    /**
     * Obtiene la función del organizador para proponer o reproponer franjas horarias (E3-13.2 / IC-154, E3-13.5 / IC-157).
     *
     * @throws ExcepcionHttp 404 si la función no es del organizador o no está en acuerdo de fecha
     */
    public function paraProponerFranjas(Usuario $organizador, string $id): FuncionDelOrganizador
    {
        $funcion = $this->funciones->delOrganizadorPorId($organizador->id, $id);
        if ($funcion === null || $funcion->estado !== EstadoFuncion::EnAcuerdoFecha) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $funcion;
    }

    /**
     * El organizador propone las franjas horarias y el mínimo de asistentes para el acuerdo de fecha (E3-13.2 / IC-154).
     *
     * @param list<array{fecha_hora_inicio: string, fecha_hora_fin?: string|null}> $franjas
     *
     * @return array<string, mixed> los datos del acuerdo creado por funciones
     *
     * @throws ExcepcionHttp 404 si la función no es del organizador o no está en acuerdo de fecha
     */
    public function proponerAcuerdoFecha(Usuario $organizador, string $id, int $minimoAsistentes, array $franjas): array
    {
        $funcion = $this->propia($organizador, $id);
        if ($funcion->estado !== EstadoFuncion::EnAcuerdoFecha) {
            throw ExcepcionHttp::noEncontrada();
        }

        if ($this->acuerdos === null) {
            throw new LogicException('No se configuró el cliente de acuerdos de funciones.');
        }
        // Uno abierto a la vez: si no, la ficha mostraría sólo el último y los votos del otro
        // quedarían colgados (E3-13.F2). Después de cerrarlo sin mínimo, sí se proponen otras.
        if (($this->acuerdos->obtenerPorFuncion($funcion->id)['estado'] ?? null) === 'abierto') {
            throw new ReglaIncumplida('Esta función ya tiene un acuerdo de fecha abierto. Para proponer otras franjas, primero cerralo sin mínimo desde la ficha.');
        }

        return $this->acuerdos->proponer($funcion->id, $minimoAsistentes, $franjas);
    }

    /**
     * Confirma la franja ganadora del acuerdo de fecha si alcanzó el mínimo y programa la función (E3-13.4 / IC-156).
     *
     * @return Funcion
     *
     * @throws ExcepcionHttp 404 si la función no es del organizador o no está en acuerdo de fecha
     * @throws ReglaIncumplida si el acuerdo no alcanzó el mínimo o la fecha no es válida
     */
    public function confirmarAcuerdo(Usuario $organizador, string $id): Funcion
    {
        $funcion = $this->propia($organizador, $id);
        if ($funcion->estado !== EstadoFuncion::EnAcuerdoFecha) {
            throw ExcepcionHttp::noEncontrada();
        }

        if ($this->acuerdos === null) {
            throw new LogicException('No se configuró el cliente de acuerdos de funciones.');
        }

        $acuerdo = $this->acuerdoAbierto($funcion->id);
        $lider = AcuerdosDeFunciones::franjaQueLidera($acuerdo);
        if ($lider === null || !AcuerdosDeFunciones::alcanzoElMinimo($acuerdo)) {
            throw new ReglaIncumplida("La franja con más compromisos todavía no llega al mínimo de {$acuerdo['minimo_asistentes']}.");
        }
        // Todo lo que puede fallar se controla antes de pedirle a funciones que lo cierre: si no,
        // el acuerdo quedaba confirmado allá y la función sin programar acá, sin forma de
        // reintentar (E3-13.F4).
        $fechaHora = self::fechaHora((string) ($lider['inicio'] ?? ''));
        if ($fechaHora <= new DateTimeImmutable()) {
            throw new ReglaIncumplida('La franja ganadora ya empezó: la función no se puede programar en esa fecha. Cerrá el acuerdo sin mínimo y proponé franjas nuevas.');
        }

        $this->acuerdos->confirmar((string) $acuerdo['id']);
        $funcion->fijarFechaHora($fechaHora);
        $this->funciones->guardar($funcion);

        return $funcion;
    }

    /**
     * Cierra el acuerdo de fecha cuando no alcanza el mínimo de asistentes (E3-13.5 / IC-157).
     * La función NO se programa y permanece disponible para que el organizador decida si propone nuevas franjas o la cancela.
     *
     * @return array<string, mixed>
     *
     * @throws ExcepcionHttp 404 si la función no es del organizador o no está en acuerdo de fecha
     * @throws ReglaIncumplida si no hay acuerdo activo
     */
    public function cerrarAcuerdoSinMinimo(Usuario $organizador, string $id): array
    {
        $funcion = $this->propia($organizador, $id);
        if ($funcion->estado !== EstadoFuncion::EnAcuerdoFecha) {
            throw ExcepcionHttp::noEncontrada();
        }

        if ($this->acuerdos === null) {
            throw new LogicException('No se configuró el cliente de acuerdos de funciones.');
        }

        $acuerdo = $this->acuerdoAbierto($funcion->id);

        return $this->acuerdos->cerrarSinMinimo((string) $acuerdo['id']);
    }

    /**
     * El acuerdo de la función, si está abierto. Confirmar o cerrar uno que ya se cerró (dos
     * clics, o dos pestañas) era un 422 de funciones, que llegaba como una página 500.
     *
     * @return array<string, mixed>
     *
     * @throws ReglaIncumplida si no tiene o ya se cerró
     */
    private function acuerdoAbierto(string $funcionId): array
    {
        if ($this->acuerdos === null) {
            throw new LogicException('No se configuró el cliente de acuerdos de funciones.');
        }
        $acuerdo = $this->acuerdos->obtenerPorFuncion($funcionId);
        if ($acuerdo === null) {
            throw new ReglaIncumplida('Esta función todavía no tiene franjas propuestas.');
        }
        if (($acuerdo['estado'] ?? null) !== 'abierto') {
            throw new ReglaIncumplida('El acuerdo de fecha ya está cerrado.');
        }

        return $acuerdo;
    }

    private static function fechaHora(string $texto): DateTimeImmutable
    {
        $fechaHora = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $texto);
        if ($fechaHora === false) {
            throw new RuntimeException("funciones mandó una franja con una fecha que no se entiende: «{$texto}».");
        }

        return $fechaHora;
    }

    /**
     * La función, si es de una sala del organizador. Si no existe o es de otro, la respuesta es
     * 404: así un organizador no puede averiguar qué ids existen.
     *
     * @throws ExcepcionHttp 404
     */
    private function propia(Usuario $organizador, string $id): Funcion
    {
        $funcion = $this->funciones->buscar($id) ?? throw ExcepcionHttp::noEncontrada();
        $sala = $this->salas->buscar($funcion->salaId);
        if ($sala === null || !$sala->esDe($organizador->id)) {
            throw ExcepcionHttp::noEncontrada();
        }

        return $funcion;
    }
}
