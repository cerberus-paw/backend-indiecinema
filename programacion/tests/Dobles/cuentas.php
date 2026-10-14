<?php

/*
 * Cuentas de mentira, atendiendo con `php -S` las dos rutas que llama la bandeja de solicitudes de
 * sala: así AdministracionDeSolicitudesTest prueba la llamada por la red, como en el compose.
 * Responde lo mismo que /interno/usuarios/{id} de cuentas (UsuariosInternosTest de cuentas prueba
 * ese mismo JSON del otro lado), y como ella, sólo a programación con su token.
 *
 * Cada rol que le piden lo anota en el archivo de la variable REGISTRO, para que la prueba vea qué
 * le llegó.
 */

declare(strict_types=1);

const ESPECTADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const ORGANIZADOR = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
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
];

if (!preg_match('#^/interno/usuarios/([^/]+)(/roles)?$#', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $partes)) {
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

if (($partes[2] ?? '') === '/roles') {
    $pedido = json_decode((string) file_get_contents('php://input'), true);
    file_put_contents((string) getenv('REGISTRO'), json_encode(['usuario' => $id] + (array) $pedido) . "\n", FILE_APPEND);
    $usuario['roles'] = array_values(array_unique([...$usuario['roles'], 'organizador']));
}

echo json_encode(['id' => $id, 'estado' => 'activa'] + $usuario);
