<?php

/*
 * Lo que le falta a la configuración local frente a los ejemplos versionados: las secciones y
 * claves de cada config.ini que están en su config.ejemplo.ini, y las variables del .env que están
 * en el .env.ejemplo. Cuando un cambio suma una clave a un ejemplo, el config.ini de cada uno (que
 * no se versiona) se queda atrás sin avisar, y el síntoma aparece recién al usar esa parte.
 *
 *     php herramientas/comprobar-config.php
 *
 * Muestra sólo nombres, nunca valores: la configuración tiene secretos. Sale con 1 si falta algo.
 * No necesita las extensiones de los subsistemas: corre con cualquier PHP 8.4, también con
 *
 *     docker run --rm -v "$PWD:/app" -w /app php:8.4-cli php herramientas/comprobar-config.php
 */

declare(strict_types=1);

const SUBSISTEMAS = ['cuentas', 'programacion', 'funciones', 'moderacion', 'recaudacion'];

/**
 * @return array<string, list<string>>|null las claves de cada sección, o null si no se puede leer
 */
function seccionesDe(string $archivo): ?array
{
    // Sin interpretar los valores: «cambiar», URLs y hashes van como están, y no importan.
    $datos = @parse_ini_file($archivo, true, INI_SCANNER_RAW);
    if ($datos === false) {
        return null;
    }

    return array_map(static fn (mixed $seccion): array => is_array($seccion) ? array_keys($seccion) : [], $datos);
}

/**
 * @return list<string>|null las variables definidas (sin las comentadas), o null si no existe
 */
function variablesDe(string $archivo): ?array
{
    $lineas = @file($archivo, FILE_IGNORE_NEW_LINES);
    if ($lineas === false) {
        return null;
    }
    $variables = [];
    foreach ($lineas as $linea) {
        if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=/', $linea, $coincidencia) === 1) {
            $variables[] = $coincidencia[1];
        }
    }

    return $variables;
}

/**
 * @return list<string> lo que falta, ya escrito para mostrar
 */
function faltantesDelIni(string $ejemplo, string $local): array
{
    $esperado = seccionesDe($ejemplo) ?? [];
    $actual = seccionesDe($local);
    if ($actual === null) {
        return ['falta el archivo (copiarlo de ' . basename($ejemplo) . ')'];
    }
    $faltan = [];
    foreach ($esperado as $seccion => $claves) {
        if (!array_key_exists($seccion, $actual)) {
            $faltan[] = "[{$seccion}] entera";

            continue;
        }
        foreach (array_diff($claves, $actual[$seccion]) as $clave) {
            $faltan[] = "[{$seccion}] {$clave}";
        }
    }

    return $faltan;
}

/**
 * @return list<string>
 */
function faltantesDelEnv(string $ejemplo, string $local): array
{
    $actual = variablesDe($local);
    if ($actual === null) {
        return ['falta el archivo (copiarlo de .env.ejemplo)'];
    }

    return array_values(array_diff(variablesDe($ejemplo) ?? [], $actual));
}

$raiz = $argv[1] ?? dirname(__DIR__);
$archivos = ['.env' => faltantesDelEnv("{$raiz}/.env.ejemplo", "{$raiz}/.env")];
foreach (SUBSISTEMAS as $subsistema) {
    $archivos["{$subsistema}/config/config.ini"] = faltantesDelIni(
        "{$raiz}/{$subsistema}/config/config.ejemplo.ini",
        "{$raiz}/{$subsistema}/config/config.ini",
    );
}

$incompletos = array_filter($archivos);
foreach ($archivos as $archivo => $faltan) {
    if ($faltan === []) {
        echo "{$archivo}: completo\n";

        continue;
    }
    echo "{$archivo}: le falta\n";
    foreach ($faltan as $falta) {
        echo "  - {$falta}\n";
    }
}
if ($incompletos !== []) {
    echo "\nCopiar lo que falta del ejemplo de cada uno y completar los valores.\n";
}

exit($incompletos === [] ? 0 : 1);
