<?php

declare(strict_types=1);

namespace IndieCinema\Cuentas\Pruebas;

use IndieCinema\Nucleo\BaseDeDatos;
use IndieCinema\Nucleo\Configuracion;

/**
 * Para las pruebas que usan un MySQL de verdad: sin las variables PRUEBAS_MYSQL_* se saltean; el
 * README dice cómo levantar uno con Docker.
 */
trait ConBaseDePruebas
{
    /**
     * Lo que dejaron las migraciones en cada tabla (sin las columnas generadas), o null si esta
     * clase de prueba todavía no migró. Es estática del trait, así que cada clase tiene la suya:
     * la primera prueba de cada clase rehace las tablas con las migraciones y las demás sólo las
     * vacían y vuelven a cargar eso (IC-663). Rehacerlas antes de cada prueba era lo que más
     * tardaba el CI, y vaciar deja la base igual que recién migrada.
     *
     * @var array<string, array{filas: list<array<string, mixed>>, autoincremento: bool}>|null
     */
    private static ?array $loQueDejaronLasMigraciones = null;

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
     * tablas temporales.
     */
    private static function baseConTablasNuevas(Configuracion $configuracion): BaseDeDatos
    {
        $base = BaseDeDatos::conectar($configuracion);
        if (self::$loQueDejaronLasMigraciones !== null) {
            self::vaciarComoRecienMigrada($base, self::$loQueDejaronLasMigraciones);

            return $base;
        }
        // Antes que usuario: tiene una clave foránea a ella (EF-15.4).
        $base->ejecutar('DROP TABLE IF EXISTS seguimiento');
        $base->ejecutar('DROP TABLE IF EXISTS token_de_cuenta');
        $base->ejecutar('DROP TABLE IF EXISTS solicitud');
        $base->ejecutar('DROP TABLE IF EXISTS sesion');
        $base->ejecutar('DROP TABLE IF EXISTS rol');
        $base->ejecutar('DROP TABLE IF EXISTS usuario');
        foreach (glob(dirname(__DIR__) . '/migraciones/*.sql') ?: [] as $migracion) {
            $base->ejecutar((string) file_get_contents($migracion));
        }
        self::$loQueDejaronLasMigraciones = self::loQueDejaron($base);

        return $base;
    }

    /**
     * Las filas de cada tabla del esquema recién migrado, que son las que cargan las migraciones
     * (el reparto por omisión, los géneros…), sin las columnas generadas, que no se pueden
     * insertar; y si la tabla tiene un AUTO_INCREMENT, para volver a empezarlo.
     *
     * @return array<string, array{filas: list<array<string, mixed>>, autoincremento: bool}>
     */
    private static function loQueDejaron(BaseDeDatos $base): array
    {
        $tablas = [];
        foreach ($base->filas("SELECT TABLE_NAME AS tabla FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $tabla) {
            $nombre = (string) $tabla['tabla'];
            $columnas = $base->filas(
                "SELECT COLUMN_NAME AS columna, EXTRA AS extra FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION",
                [$nombre],
            );
            $comunes = array_filter($columnas, static fn (array $c): bool => !str_contains(strtoupper((string) $c['extra']), 'GENERATED'));
            $lista = implode(', ', array_map(static fn (array $c): string => '`' . $c['columna'] . '`', $comunes));
            $tablas[$nombre] = [
                'filas' => $base->filas("SELECT {$lista} FROM `{$nombre}`"),
                'autoincremento' => in_array('auto_increment', array_map(static fn (array $c): string => strtolower((string) $c['extra']), $columnas), true),
            ];
        }

        return $tablas;
    }

    /**
     * Deja el esquema como recién migrado sin rehacer las tablas: las vacía, empieza de nuevo los
     * AUTO_INCREMENT y vuelve a cargar lo que dejaron las migraciones. Con DELETE y no TRUNCATE:
     * TRUNCATE rehace el archivo de la tabla, y en un MySQL que escribe en disco (el de
     * herramientas/pruebas.sh) era más lento que migrar; DELETE en una tabla vacía no cuesta nada.
     *
     * @param array<string, array{filas: list<array<string, mixed>>, autoincremento: bool}> $loQueDejaron
     */
    private static function vaciarComoRecienMigrada(BaseDeDatos $base, array $loQueDejaron): void
    {
        $base->ejecutar('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($loQueDejaron as $tabla => ['filas' => $filas, 'autoincremento' => $autoincremento]) {
            $base->ejecutar("DELETE FROM `{$tabla}`");
            if ($autoincremento) {
                $base->ejecutar("ALTER TABLE `{$tabla}` AUTO_INCREMENT = 1");
            }
            foreach ($filas as $fila) {
                $columnas = implode(', ', array_map(static fn (string $c): string => "`{$c}`", array_keys($fila)));
                $marcas = implode(', ', array_fill(0, count($fila), '?'));
                $base->ejecutar("INSERT INTO `{$tabla}` ({$columnas}) VALUES ({$marcas})", array_values($fila));
            }
        }
        $base->ejecutar('SET FOREIGN_KEY_CHECKS = 1');
    }
}
