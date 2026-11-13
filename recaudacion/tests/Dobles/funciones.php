<?php

/*
 * Funciones de mentira, atendiendo con `php -S` las rutas de cobros que llama recaudación: así
 * CobrosDeFuncionesTest prueba el cliente por la red, como en el compose. Responde lo mismo que
 * GET /interno/funciones/{id}/cobros y GET /interno/cobros de funciones (CobrosInternosTest de
 * funciones prueba ese mismo JSON del otro lado), y como ellas, sólo a recaudación con su token.
 *
 * También las ventas y las calificaciones de las funciones finalizadas de Dobles/programacion.php,
 * para ReportesTest (EF-09), y GET /interno/calificaciones/resumen.
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

// n reservas de un lugar, pagadas: las primeras «asistentes» fueron. Los compradores de la del
// primer miércoles empiezan en el 9: el 9 y el 10 también votaron (Dobles/programacion.php).
$vendidas = static function (int $lugares, int $asistentes, int $primerComprador = 100): array {
    $reservas = [];
    for ($n = 0; $n < $lugares; ++$n) {
        $reservas[] = [
            'id' => sprintf('40000000-0000-4000-8000-%012d', $primerComprador + $n),
            'usuario_id' => sprintf('90000000-0000-4000-8000-%012d', $primerComprador + $n),
            'cantidad' => 1,
            'total' => '3500.00',
            'cobrado' => '3500.00',
            'pago_id' => 'pago-' . ($primerComprador + $n),
            'estado' => $n < $asistentes ? 'asistio' : 'ausente',
            'reembolsada' => false,
        ];
    }

    return ['reservas' => $reservas, 'neto' => sprintf('%d.00', 3500 * $lugares)];
};

$cobros = static fn (string $id): array => match ($id) {
    // El Galpón: los miércoles, 10 y 6 de 40; los sábados, 38 y 36 (con 36 y 34 asistentes).
    '30000000-0000-4000-8000-000000000001' => $vendidas(10, 8, 9),
    '30000000-0000-4000-8000-000000000002' => $vendidas(38, 36),
    '30000000-0000-4000-8000-000000000003' => $vendidas(6, 5),
    '30000000-0000-4000-8000-000000000004' => $vendidas(36, 34),
    '30000000-0000-4000-8000-000000000005' => $vendidas(20, 15),
    '30000000-0000-4000-8000-000000000006' => $vendidas(20, 18),
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
if ($ruta === '/interno/calificaciones/resumen') {
    // «Rojo» los sábados: 4,5 con diez calificaciones y 4,3 con ocho.
    $calificadas = [
        '30000000-0000-4000-8000-000000000002' => ['promedio' => 4.5, 'cantidad' => 10],
        '30000000-0000-4000-8000-000000000004' => ['promedio' => 4.3, 'cantidad' => 8],
        '30000000-0000-4000-8000-000000000003' => ['promedio' => 3, 'cantidad' => 2],
    ];
    $pedidas = explode(',', (string) ($_GET['funciones'] ?? ''));
    echo json_encode([
        'salas' => (object) [],
        'obras' => (object) [],
        'organizadores' => (object) [],
        'funciones' => (object) array_intersect_key($calificadas, array_flip($pedidas)),
        'asistentes' => (object) [],
    ]);

    return;
}
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
