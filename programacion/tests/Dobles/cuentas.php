<?php

/*
 * Cuentas de mentira, atendiendo con `php -S` lo que le piden los bloqueos (EF-12) y los pedidos
 * de títulos (EF-11): nombres y correos de usuarios. Así sus pruebas hacen la llamada por la red,
 * como en el compose. Responde lo mismo que /interno/usuarios de cuentas (UsuariosInternosTest de
 * cuentas prueba ese mismo JSON del otro lado), y como ella, sólo a programación con su token.
 *
 * Los roles ya no: desde EF-07.2 los da moderación, que tiene su propio doble.
 */

declare(strict_types=1);

const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const OTRO_ESPECTADOR = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const LENTO = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'programacion' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-programacion') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}

$usuarios = [
    ESPECTADORA => ['nombre' => 'Eva Prueba', 'correo' => 'eva@ejemplo.com', 'roles' => ['espectador'], 'creado_en' => '2025-03-08T15:00:00'],
    ORGANIZADOR => ['nombre' => 'Omar Prueba', 'correo' => 'omar@ejemplo.com', 'roles' => ['espectador', 'organizador'], 'creado_en' => '2024-11-02T12:00:00'],
    OTRO_ESPECTADOR => ['nombre' => 'Clara Prueba', 'correo' => 'clara@ejemplo.com', 'roles' => ['espectador'], 'creado_en' => '2025-04-10T18:00:00'],
];

$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($ruta === '/interno/usuarios' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $idsCrudo = (string) ($_GET['ids'] ?? '');
    if (getenv('REGISTRO_CONSULTAS') !== false && getenv('REGISTRO_CONSULTAS') !== '') {
        file_put_contents((string) getenv('REGISTRO_CONSULTAS'), $idsCrudo . "\n", FILE_APPEND);
    }
    $respuesta = [];
    foreach (array_filter(explode(',', $idsCrudo)) as $id) {
        $id = trim($id);
        if (isset($usuarios[$id])) {
            $respuesta[$id] = ['id' => $id, 'nombre' => $usuarios[$id]['nombre']];
        }
    }
    echo json_encode($respuesta, JSON_FORCE_OBJECT);

    return;
}

if (!preg_match('#^/interno/usuarios/([^/]+)$#', $ruta, $partes)) {
    http_response_code(404);

    return;
}
$id = $partes[1];
if ($id === LENTO) {
    sleep(3);
}
$usuario = $usuarios[$id] ?? null;
if ($usuario === null) {
    http_response_code(404);

    return;
}

echo json_encode(['id' => $id, 'estado' => 'activa'] + $usuario);
