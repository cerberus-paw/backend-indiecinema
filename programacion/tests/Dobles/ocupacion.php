<?php

/*
 * Funciones de mentira, atendiendo con `php -S` las rutas de ocupación que llama programación:
 * así LugaresLibresTest prueba la cartelera y la ficha con el cliente de verdad, por la red, como
 * en el compose. Responde lo mismo que GET /interno/funciones/{id}/ocupacion y GET
 * /interno/ocupacion de funciones (OcupacionInternaTest de funciones prueba ese mismo JSON del
 * otro lado), y como ellas, sólo a programación con su token.
 *
 * Los lugares ocupados de cada función los decide la prueba: los escribe en el JSON que nombra
 * la variable de entorno OCUPACION (funcion_id => ocupados). Las que no están, 0. Como la de
 * verdad, no responde más de 100 funciones por pedido.
 */

declare(strict_types=1);

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'programacion' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-programacion') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}

$archivo = (string) getenv('OCUPACION');
$ocupados = is_file($archivo) ? json_decode((string) file_get_contents($archivo), true) : [];
$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($ruta === '/interno/ocupacion') {
    $ids = array_filter(explode(',', (string) ($_GET['funciones'] ?? '')));
    if (count($ids) > 100) {
        http_response_code(400);
        echo '{"error":"Se piden hasta 100 funciones por vez."}';

        return;
    }
    echo json_encode(['lugares_ocupados' => (object) array_map(static fn (string $id): int => $ocupados[$id] ?? 0, array_combine($ids, $ids))]);
} elseif (preg_match('#^/interno/funciones/([0-9a-f-]{36})/ocupacion$#', $ruta, $partes) === 1) {
    $respuesta = ['funcion_id' => $partes[1], 'lugares_ocupados' => $ocupados[$partes[1]] ?? 0, 'maximo_por_persona' => 6];
    if (isset($_GET['usuario'])) {
        $delUsuario = is_array($ocupados[$partes[1] . ':usuario'] ?? null)
            ? ($ocupados[$partes[1] . ':usuario'][$_GET['usuario']] ?? 0)
            : ($ocupados[$partes[1] . ':usuario'] ?? 0);
        $respuesta['lugares_del_usuario'] = is_int($delUsuario) ? $delUsuario : 0;
    }
    echo json_encode($respuesta);
} else {
    http_response_code(404);
}
