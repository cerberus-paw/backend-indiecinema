<?php

/*
 * Funciones de mentira, atendiendo con `php -S` las rutas de cobros que llama recaudación: así
 * CobrosDeFuncionesTest prueba el cliente por la red, como en el compose. Responde lo mismo que
 * GET /interno/funciones/{id}/cobros y GET /interno/cobros de funciones (CobrosInternosTest de
 * funciones prueba ese mismo JSON del otro lado), y como ellas, sólo a recaudación con su token.
 */

declare(strict_types=1);

const PAGADA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const A_LA_GORRA = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const ROTA = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const LENTA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'recaudacion' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-recaudacion') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}

$cobros = static fn (string $id): array => match ($id) {
    PAGADA => ['reservas' => [
        ['id' => '11111111-1111-4111-8111-111111111111', 'usuario_id' => '99999999-9999-4999-8999-999999999999', 'cantidad' => 2, 'total' => '7000.00', 'cobrado' => '7000.00', 'pago_id' => 'pago-1', 'estado' => 'asistio', 'reembolsada' => false],
        ['id' => '22222222-2222-4222-8222-222222222222', 'usuario_id' => '99999999-9999-4999-8999-999999999999', 'cantidad' => 1, 'total' => '3500.00', 'cobrado' => '0.00', 'pago_id' => null, 'estado' => 'confirmada', 'reembolsada' => false],
    ], 'neto' => '7000.00'],
    // Un cambio en funciones que rompe lo que recaudación espera: el monto como número.
    ROTA => ['reservas' => [], 'neto' => 7000],
    LENTA => (static function (): array {
        sleep(3);

        return ['reservas' => [], 'neto' => '0.00'];
    })(),
    default => ['reservas' => [], 'neto' => '0.00'],
};

$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($ruta === '/interno/cobros') {
    $ids = explode(',', (string) ($_GET['funciones'] ?? ''));
    echo json_encode(['cobros' => (object) array_combine($ids, array_map($cobros, $ids))]);

    return;
}
if (preg_match('#^/interno/funciones/([0-9a-f-]{36})/cobros$#', $ruta, $partes) === 1) {
    echo json_encode(['funcion_id' => $partes[1], ...$cobros($partes[1])]);

    return;
}
http_response_code(404);
