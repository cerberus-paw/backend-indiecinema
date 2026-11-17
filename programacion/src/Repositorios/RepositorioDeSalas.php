<?php

declare(strict_types=1);

namespace IndieCinema\Programacion\Repositorios;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Programacion\Modelo\DatosDeSala;
use IndieCinema\Programacion\Modelo\ReglaIncumplida;
use IndieCinema\Programacion\Modelo\Sala;
use PDOException;

/**
 * Las salas en el esquema de programación. Las dadas de baja no se ven desde acá: para la
 * aplicación ya no existen, aunque la fila siga en la tabla.
 */
final class RepositorioDeSalas
{
    /** Cuántos segundos espera a que otro suelte la sala, como el bloqueo de las reservas. */
    private const ESPERA_DEL_BLOQUEO = 5;

    /** El error de MySQL cuando se vence la espera de un bloqueo. */
    private const ESPERA_VENCIDA = 1205;

    private const COLUMNAS = 'id, organizador_id, nombre, descripcion, direccion, localidad, capacidad,
        peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, imagen, habilitada';

    public function __construct(private readonly BaseDeDatos $base)
    {
    }

    public function buscar(string $id): ?Sala
    {
        $fila = $this->base->fila(
            'SELECT ' . self::COLUMNAS . ' FROM sala WHERE id = ? AND dada_de_baja_en IS NULL',
            [$id],
        );

        return $fila === null ? null : self::sala($fila);
    }

    /**
     * Corre $trabajo en una transacción con la sala bloqueada (EF-05.6): programar en una sala es
     * de a uno. Todo lo que deja una función programada (programar y editar a mano, confirmar el
     * acuerdo, la tarea que cierra los vencidos) lee la ocupación, controla que no se pise y guarda
     * dentro del trabajo; el segundo que llega espera, y al entrar ya ve la función del primero.
     * Salas distintas no se esperan entre sí.
     *
     * Es un SELECT … FOR UPDATE: la sala tiene fila, y el bloqueo se suelta solo con la
     * transacción. Al trabajo le llega la sala leída con el bloqueo, con los parámetros de ese
     * momento, o null si no existe o se dio de baja.
     *
     * @template T
     *
     * @param callable(?Sala): T $trabajo
     *
     * @return T
     *
     * @throws ReglaIncumplida si otro no suelta la sala en 5 segundos. Es una regla y no un 503 para
     *                         que el organizador vuelva al formulario con lo cargado; la tarea la
     *                         deja pasar y la próxima pasada lo vuelve a intentar.
     */
    public function conLaSalaBloqueada(string $id, callable $trabajo): mixed
    {
        try {
            return $this->base->enTransaccion(function (BaseDeDatos $base) use ($id, $trabajo): mixed {
                // MySQL espera 50 segundos por omisión: demasiado para un formulario.
                $base->ejecutar('SET SESSION innodb_lock_wait_timeout = ' . self::ESPERA_DEL_BLOQUEO);
                $fila = $base->fila(
                    'SELECT ' . self::COLUMNAS . ' FROM sala WHERE id = ? AND dada_de_baja_en IS NULL FOR UPDATE',
                    [$id],
                );

                return $trabajo($fila === null ? null : self::sala($fila));
            });
        } catch (PDOException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) !== self::ESPERA_VENCIDA) {
                throw $error;
            }
            throw new ReglaIncumplida('Otra programación de esta sala se está guardando: probá de nuevo en unos segundos.', previous: $error);
        }
    }

    /**
     * Las salas de un organizador, de la más nueva a la más vieja.
     *
     * @return list<Sala>
     */
    public function delOrganizador(string $organizadorId): array
    {
        return array_map(self::sala(...), $this->base->filas(
            'SELECT ' . self::COLUMNAS . ' FROM sala
             WHERE organizador_id = ? AND dada_de_baja_en IS NULL
             ORDER BY creada_en DESC, nombre',
            [$organizadorId],
        ));
    }

    /**
     * Las que se ven en el sitio: habilitadas y no dadas de baja, por nombre.
     *
     * @param ?string $texto si viene, sólo las que lo tienen en el nombre o la localidad. Como en
     *                       la cartelera, sin distinguir mayúsculas ni tildes, y lo que escribe el
     *                       visitante va como parámetro, con los comodines de LIKE escapados.
     *
     * @return list<Sala>
     */
    public function publicadas(?string $texto = null): array
    {
        $texto = trim((string) $texto);
        $busqueda = '';
        $parametros = [];
        if ($texto !== '') {
            $busqueda = "AND (nombre LIKE ? ESCAPE '!' OR localidad LIKE ? ESCAPE '!')";
            $parametros = [Like::contiene($texto), Like::contiene($texto)];
        }

        return array_map(self::sala(...), $this->base->filas(
            'SELECT ' . self::COLUMNAS . " FROM sala
             WHERE habilitada = TRUE AND dada_de_baja_en IS NULL {$busqueda}
             ORDER BY nombre",
            $parametros,
        ));
    }

    /**
     * Guarda la sala como está. Una del formulario llega sin habilitar (Sala::nueva()); una de los
     * datos de prueba, ya habilitada.
     */
    public function agregar(Sala $sala): void
    {
        $datos = $sala->datos;
        $this->base->ejecutar(
            'INSERT INTO sala (id, organizador_id, nombre, descripcion, direccion, localidad, capacidad,
                peliculas_por_funcion, duracion_funcion, tiempo_entre_funciones, imagen, habilitada)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $sala->id, $sala->organizadorId, $datos->nombre, $datos->descripcion, $datos->direccion,
                $datos->localidad, $datos->capacidad, $datos->peliculasPorFuncion, $datos->duracionFuncion,
                $datos->tiempoEntreFunciones, $sala->imagen, $sala->habilitada,
            ],
        );
    }

    /**
     * Guarda lo que carga el organizador. El dueño y el flag de habilitada no cambian por acá.
     */
    public function guardar(Sala $sala): void
    {
        $datos = $sala->datos;
        $this->base->ejecutar(
            'UPDATE sala SET nombre = ?, descripcion = ?, direccion = ?, localidad = ?, capacidad = ?,
                peliculas_por_funcion = ?, duracion_funcion = ?, tiempo_entre_funciones = ?, imagen = ?
             WHERE id = ?',
            [
                $datos->nombre, $datos->descripcion, $datos->direccion, $datos->localidad, $datos->capacidad,
                $datos->peliculasPorFuncion, $datos->duracionFuncion, $datos->tiempoEntreFunciones, $sala->imagen,
                $sala->id,
            ],
        );
    }

    /**
     * Guarda sólo el flag de habilitada, que cambia el administrador: los datos de la sala son
     * del organizador y no se pisan con lo que se leyó antes.
     */
    public function guardarHabilitacion(Sala $sala): void
    {
        $this->base->ejecutar('UPDATE sala SET habilitada = ? WHERE id = ?', [(int) $sala->habilitada, $sala->id]);
    }

    /**
     * La sala de una fila con las columnas de COLUMNAS. Pública para el repositorio de solicitudes,
     * que trae cada solicitud con su sala en la misma consulta.
     *
     * @param array<string, mixed> $fila
     */
    public static function sala(array $fila): Sala
    {
        return new Sala(
            (string) $fila['id'],
            (string) $fila['organizador_id'],
            new DatosDeSala(
                (string) $fila['nombre'],
                (string) $fila['descripcion'],
                (string) $fila['direccion'],
                (string) $fila['localidad'],
                (int) $fila['capacidad'],
                (int) $fila['peliculas_por_funcion'],
                (int) $fila['duracion_funcion'],
                (int) $fila['tiempo_entre_funciones'],
            ),
            $fila['imagen'] === null ? null : (string) $fila['imagen'],
            (bool) $fila['habilitada'],
        );
    }
}
