<?php

/*
 * Moderación de mentira, atendiendo con `php -S` GET /interno/resumen, como ResumenInternoTest de
 * moderación prueba que responde del otro lado: para la moderación de los reportes del
 * administrador (EF-22.4). Sólo a recaudación con su token.
 *
 * El Galpón (Dobles/programacion.php) tiene dos denuncias abiertas contra la sala y una
 * desestimada contra su primera función de noviembre, y está suspendido por una denuncia. Hay
 * además una denuncia de una obra y una sala suspendida que no tuvo funciones.
 */

declare(strict_types=1);

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'recaudacion' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-recaudacion') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}
if (parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/interno/resumen') {
    http_response_code(404);

    return;
}
echo json_encode([
    'denuncias' => [
        ['tipo' => 'funcion', 'objeto_id' => '30000000-0000-4000-8000-000000000001', 'estado' => 'desestimada', 'cantidad' => 1],
        ['tipo' => 'obra', 'objeto_id' => '20000000-0000-4000-8000-000000000001', 'estado' => 'abierta', 'cantidad' => 1],
        ['tipo' => 'sala', 'objeto_id' => '10000000-0000-4000-8000-000000000001', 'estado' => 'abierta', 'cantidad' => 2],
    ],
    'suspendidas' => [
        ['sala_id' => '10000000-0000-4000-8000-000000000001', 'causa' => 'denuncia', 'desde' => '2026-11-06 13:00:00'],
        ['sala_id' => '10000000-0000-4000-8000-000000000009', 'causa' => 'vencimiento', 'desde' => '2026-11-01 03:00:00'],
    ],
    // EF-22.10: la devuelta espera al solicitante, sin resolver; la vencida es aparte.
    'solicitudes' => ['pendiente' => 2, 'devuelta' => 1, 'aprobada' => 1, 'vencida' => 1],
]);
