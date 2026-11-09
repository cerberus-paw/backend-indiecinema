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

$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($ruta === '/interno/usuarios') {
    $idsParam = (string) ($_GET['ids'] ?? '');
    $ids = $idsParam !== '' ? array_values(array_unique(explode(',', $idsParam))) : [];
    if (count($ids) > 100) {
        http_response_code(400);
        echo '{"error":"Se piden hasta 100 usuarios por vez."}';

        return;
    }
    $usuarios = [];
    foreach ($ids as $id) {
        if ($id === BORRADO || preg_match('/^[0-9a-f-]{36}$/', $id) !== 1) {
            continue;
        }
        $nombre = match (true) {
            str_starts_with($id, 'ffffffff') => '=HYPERLINK("http://malo.test")',
            str_starts_with($id, 'eeeeeeee') => '+12345',
            str_starts_with($id, 'dddddddd') => '-100',
            str_starts_with($id, 'cccccccc') => '@admin',
            str_starts_with($id, 'bbbbbbbb') => 'Elías',
            str_starts_with($id, '99999999') => "\tTabulado",
            default => 'Eva',
        };
        $usuarios[$id] = ['id' => $id, 'nombre' => $nombre];
    }
    echo json_encode((object) $usuarios);

    return;
}

$id = (string) preg_replace('#^/interno/usuarios/#', '', $ruta);
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
