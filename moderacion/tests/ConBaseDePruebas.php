<?php

declare(strict_types=1);

namespace IndieCinema\Moderacion\Pruebas;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;
use PHPUnit\Framework\Attributes\After;
use ReflectionObject;

/**
 * Para las pruebas que usan un MySQL de verdad, como en programación: sin las variables
 * PRUEBAS_MYSQL_* se saltean; el README dice cómo levantar uno con Docker.
 */
trait ConBaseDePruebas
{
    /**
     * Suelta los objetos que la prueba guardó en sus propiedades: la base y los repositorios que
     * la usan. PHPUnit conserva cada prueba hasta el final de la corrida, y con ella su conexión
     * abierta; con la suite completa se pasa el máximo de conexiones de MySQL.
     */
    #[After]
    protected function soltarLaBase(): void
    {
        foreach ((new ReflectionObject($this))->getProperties() as $propiedad) {
            if ($propiedad->isStatic() || $propiedad->getDeclaringClass()->getName() !== static::class || !$propiedad->isInitialized($this)) {
                continue;
            }
            if (is_object($propiedad->getValue($this))) {
                unset($this->{$propiedad->getName()});
            }
        }
        // Cada pedido arma su Aplicacion, cuyo contenedor guarda funciones que la apuntan: es un
        // ciclo, y PHP lo libera (y cierra la conexión) recién cuando junta los ciclos.
        gc_collect_cycles();
    }

    /**
     * La sección [base] de la configuración, o saltea la prueba si no hay MySQL.
     *
     * @return array<string, mixed>
     */
    private static function baseDePruebas(): array
    {
        if (getenv('PRUEBAS_MYSQL_HOST') === false) {
            self::markTestSkipped('Sin PRUEBAS_MYSQL_HOST no hay MySQL para probar.');
        }

        return [
            'host' => getenv('PRUEBAS_MYSQL_HOST'),
            'puerto' => (int) (getenv('PRUEBAS_MYSQL_PUERTO') ?: 3306),
            'esquema' => getenv('PRUEBAS_MYSQL_ESQUEMA') ?: 'pruebas',
            'usuario' => getenv('PRUEBAS_MYSQL_USUARIO') ?: 'root',
            'clave' => (string) getenv('PRUEBAS_MYSQL_CLAVE'),
        ];
    }

    /**
     * Rehace las tablas con las migraciones: cada aplicación abre su conexión, así que no sirven
     * tablas temporales. En la máquina, todos los subsistemas prueban sobre el mismo esquema: las
     * tablas con el nombre de una de moderación (la solicitud_sala de programación) se borran
     * igual.
     */
    private static function baseConTablasNuevas(Configuracion $configuracion): BaseDeDatos
    {
        $base = BaseDeDatos::conectar($configuracion);
        $base->ejecutar('SET FOREIGN_KEY_CHECKS = 0');
        $base->ejecutar('DROP TABLE IF EXISTS solicitud_sala');
        $base->ejecutar('SET FOREIGN_KEY_CHECKS = 1');
        self::programacionConSolicitudes($base);
        foreach (glob(dirname(__DIR__) . '/migraciones/*.sql') ?: [] as $migracion) {
            $base->ejecutar((string) file_get_contents($migracion));
        }

        return $base;
    }

    /**
     * Las dos tablas de programación que leen las migraciones que mudaron las solicitudes de sala
     * (EF-07.2), vacías, como en una base nueva: la segunda borra la de solicitudes, así que se
     * vuelven a crear antes de cada prueba. Sólo las columnas que se copian.
     */
    private static function programacionConSolicitudes(BaseDeDatos $base): void
    {
        $base->ejecutar('CREATE SCHEMA IF NOT EXISTS programacion');
        $base->ejecutar('DROP TABLE IF EXISTS programacion.solicitud_sala');
        $base->ejecutar('DROP TABLE IF EXISTS programacion.sala');
        $base->ejecutar(
            'CREATE TABLE programacion.sala (
                id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
                nombre VARCHAR(100) NOT NULL,
                descripcion VARCHAR(2000) NOT NULL,
                direccion VARCHAR(150) NOT NULL,
                localidad VARCHAR(100) NOT NULL,
                capacidad SMALLINT UNSIGNED NOT NULL,
                peliculas_por_funcion TINYINT UNSIGNED NOT NULL,
                duracion_funcion SMALLINT UNSIGNED NOT NULL,
                tiempo_entre_funciones SMALLINT UNSIGNED NOT NULL,
                dada_de_baja_en DATETIME NULL
            )',
        );
        $base->ejecutar(
            "CREATE TABLE programacion.solicitud_sala (
                id CHAR(36) CHARACTER SET ascii NOT NULL PRIMARY KEY,
                sala_id CHAR(36) CHARACTER SET ascii NOT NULL,
                usuario_id CHAR(36) CHARACTER SET ascii NOT NULL,
                solicitante_nombre VARCHAR(100) NULL,
                estado ENUM('pendiente', 'aprobada', 'rechazada') NOT NULL DEFAULT 'pendiente',
                enviada_en DATETIME NOT NULL,
                resuelta_por CHAR(36) CHARACTER SET ascii NULL,
                resuelta_en DATETIME NULL,
                motivo VARCHAR(1000) NULL
            )",
        );
    }
}
