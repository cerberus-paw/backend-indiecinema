<?php

/*
 * Cuentas de mentira, atendiendo con `php -S` la ruta que llama funciones para avisar por correo
 * que se canceló una función: así PanelDeReservasTest prueba los avisos por la red, como en el
 * compose. Responde lo mismo que GET /interno/usuarios/{id} de cuentas (UsuariosInternosTest de
 * cuentas prueba ese JSON del otro lado), y como ella, sólo a funciones con su token.
 *
 * Todo usuario existe y su correo es <id>@ejemplo.test, salvo BORRADO, que ya no existe.
 */

declare(strict_types=1);

const BORRADO = '00000000-0000-4000-8000-000000000000';

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'funciones' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-funciones') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}

$id = (string) preg_replace('#^/interno/usuarios/#', '', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($id === BORRADO || preg_match('/^[0-9a-f-]{36}$/', $id) !== 1) {
    http_response_code(404);

    return;
}
echo json_encode([
    'id' => $id,
    'nombre' => 'Eva',
    'correo' => "{$id}@ejemplo.test",
    'estado' => 'activa',
    'roles' => ['espectador'],
    'creado_en' => '2026-09-01T12:00:00',
]);
