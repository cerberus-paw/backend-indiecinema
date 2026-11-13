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
        foreach (glob(dirname(__DIR__) . '/migraciones/*.sql') ?: [] as $migracion) {
            $base->ejecutar((string) file_get_contents($migracion));
        }

        return $base;
    }
}
