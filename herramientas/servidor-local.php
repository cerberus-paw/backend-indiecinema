<?php

/*
 * Servidor de desarrollo sin Docker: hace con `php -S` lo que hace nginx en el compose, para ver
 * las pantallas rápido. Nunca se usa en el VPS; allá está nginx.
 *
 *     php -S localhost:8080 herramientas/servidor-local.php
 *
 * - /estaticos/ sale de ../frontend-indiecinema/estaticos.
 * - /cuenta/ va a cuentas, /funcion/ a funciones y el resto a programación, sin el prefijo.
 * - /interno/ da 404, como desde afuera.
 * - Con la variable ROL simula una sesión: manda las cabeceras de identidad con el secreto de
 *   cada subsistema, como nginx después del auth_request. Sirve para probar el menú según el rol
 *   mientras no esté el inicio de sesión de cuentas (IC-29 a IC-31):
 *
 *     env ROL=organizador NOMBRE="Salvador Baez" php -S localhost:8080 herramientas/servidor-local.php
 */

declare(strict_types=1);

const PREFIJOS = ['/cuenta' => 'cuentas', '/funcion' => 'funciones'];
const TIPOS = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'ico' => 'image/x-icon',
    'woff2' => 'font/woff2',
];

$raiz = dirname(__DIR__);
$ruta = rawurldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (str_starts_with($ruta, '/estaticos/')) {
    $base = realpath("{$raiz}/../frontend-indiecinema/estaticos");
    $archivo = $base === false ? false : realpath($base . substr($ruta, strlen('/estaticos')));
    // realpath() resuelve los «..»: si el archivo queda fuera de estaticos/, no existe.
    if ($archivo === false || !str_starts_with($archivo, $base . '/') || !is_file($archivo)) {
        http_response_code(404);

        return true;
    }
    header('Content-Type: ' . (TIPOS[strtolower(pathinfo($archivo, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($archivo);

    return true;
}

$subsistema = 'programacion';
$sinPrefijo = $ruta;
foreach (PREFIJOS as $prefijo => $nombre) {
    if ($ruta === $prefijo || str_starts_with($ruta, $prefijo . '/')) {
        $subsistema = $nombre;
        $sinPrefijo = substr($ruta, strlen($prefijo)) ?: '/';
        break;
    }
}

$entrada = "{$raiz}/{$subsistema}/public/index.php";
if (str_starts_with($sinPrefijo, '/interno/') || !is_file($entrada)) {
    http_response_code(404);

    return true;
}

$consulta = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY);
$_SERVER['REQUEST_URI'] = $sinPrefijo . ($consulta ? "?{$consulta}" : '');

// Como nginx: las cabeceras de identidad que manda el navegador no llegan nunca.
foreach (['HTTP_X_NGINX_SECRETO', 'HTTP_X_USUARIO_ID', 'HTTP_X_ROL', 'HTTP_X_USUARIO_NOMBRE'] as $cabecera) {
    unset($_SERVER[$cabecera]);
}

$rol = getenv('ROL');
$configuracion = "{$raiz}/{$subsistema}/config/config.ini";
if ($rol !== false && $rol !== '' && is_file($configuracion)) {
    $secciones = parse_ini_file($configuracion, true, INI_SCANNER_TYPED) ?: [];
    $_SERVER['HTTP_X_NGINX_SECRETO'] = (string) ($secciones['nginx']['secreto'] ?? '');
    $_SERVER['HTTP_X_USUARIO_ID'] = getenv('USUARIO_ID') ?: '00000000-0000-4000-8000-000000000001';
    $_SERVER['HTTP_X_ROL'] = $rol;
    $_SERVER['HTTP_X_USUARIO_NOMBRE'] = rawurlencode(getenv('NOMBRE') ?: 'Usuario de prueba');
}

chdir(dirname($entrada));
require $entrada;

return true;
