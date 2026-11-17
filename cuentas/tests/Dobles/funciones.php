<?php

/*
 * Funciones de mentira, atendiendo con `php -S` la ruta de conducta en lote que llama cuentas
 * (GET /interno/usuarios/conducta?ids=…). Lee el mapa usuario_id => {puntaje, bajo_umbral,
 * negativas, ausencias} del JSON que nombra CONDUCTA.
 */

declare(strict_types=1);

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'cuentas' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-cuentas') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}

$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($ruta !== '/interno/usuarios/conducta') {
    http_response_code(404);
    echo '{"error":"Ruta no encontrada."}';

    return;
}

$archivo = (string) getenv('CONDUCTA');
$mapa = is_file($archivo) ? json_decode((string) file_get_contents($archivo), true) : [];
$conductas = [];
foreach (array_filter(explode(',', (string) ($_GET['ids'] ?? ''))) as $id) {
    $conductas[$id] = is_array($mapa[$id] ?? null)
        ? $mapa[$id]
        : ['puntaje' => 100, 'bajo_umbral' => false, 'negativas' => 0, 'ausencias' => 0];
}

echo json_encode(['conductas' => (object) $conductas]);
