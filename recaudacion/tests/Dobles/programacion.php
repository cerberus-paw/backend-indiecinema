<?php

/*
 * Programación de mentira, atendiendo con `php -S` las funciones finalizadas que pide la
 * reportería (EF-09). Responde lo mismo que GET /interno/funciones/finalizadas de programación
 * (FuncionesInternasTest de programación prueba ese JSON del otro lado), sólo a recaudación con su
 * token. Los datos están armados para que salga cada sugerencia (ReportesTest):
 *
 * - El Galpón vende poco los miércoles (16 de 80 lugares) y casi todo los sábados (74 de 80).
 * - «Rojo», los sábados, se llena, va casi todo el que reservó y tiene más de 4 de promedio.
 * - «La ciénaga» perdió la votación del primer miércoles con el 40 % de los votos.
 * - Cineclub Norte es de otra organizadora: sólo la ve el administrador.
 * - En octubre hay una más en El Galpón, con un título que empieza como una fórmula de planilla,
 *   para el CSV (EF-09.2).
 *
 * Como programación, devuelve sólo las del período, con los dos días incluidos, de la primera a la
 * última.
 *
 * Las ventas y las calificaciones de estas funciones están en Dobles/funciones.php.
 */

declare(strict_types=1);

const ORGANIZADORA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const SIN_FUNCIONES = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const GALPON = '10000000-0000-4000-8000-000000000001';
const NORTE = '10000000-0000-4000-8000-000000000002';
const ZAMA = '20000000-0000-4000-8000-000000000001';
const CIENAGA = '20000000-0000-4000-8000-000000000002';
const ROJO = '20000000-0000-4000-8000-000000000003';
const FORMULA = '20000000-0000-4000-8000-000000000004';

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'recaudacion' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-recaudacion') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}
if ((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/interno/funciones/finalizadas') {
    http_response_code(404);

    return;
}
if (!isset($_GET['desde'], $_GET['hasta'])) {
    http_response_code(400);
    echo '{"error":"Falta el período."}';

    return;
}

$obra = static fn (string $id, string $titulo, bool $elegida, int $votos = 0): array => ['id' => $id, 'titulo' => $titulo, 'dueno_id' => ORGANIZADORA, 'elegida' => $elegida, 'votos' => $votos];
$funcion = static fn (string $id, string $fecha, string $sala, string $nombre, array $obras, array $votantes = [], string $organizador = ORGANIZADORA): array => [
    'id' => $id,
    'fecha_hora' => $fecha,
    'cupo' => 40,
    'precio_base' => '3500.00',
    'sala' => ['id' => $sala, 'nombre' => $nombre],
    'organizador_id' => $organizador,
    'obras' => $obras,
    'votantes_ids' => $votantes,
];
// Diez votantes, del 1 al 10: el 9 y el 10, además, reservaron.
$votantes = array_map(static fn (int $n): string => sprintf('90000000-0000-4000-8000-%012d', $n), range(1, 10));
$delOrganizador = [
    $funcion('30000000-0000-4000-8000-000000000001', '2026-11-04T21:00:00', GALPON, 'El Galpón', [$obra(CIENAGA, 'La ciénaga', false, 4), $obra(ZAMA, 'Zama', true, 6)], $votantes),
    $funcion('30000000-0000-4000-8000-000000000002', '2026-11-07T21:00:00', GALPON, 'El Galpón', [$obra(ROJO, 'Rojo', true)]),
    $funcion('30000000-0000-4000-8000-000000000003', '2026-11-11T21:00:00', GALPON, 'El Galpón', [$obra(ZAMA, 'Zama', true)]),
    $funcion('30000000-0000-4000-8000-000000000004', '2026-11-14T21:00:00', GALPON, 'El Galpón', [$obra(ROJO, 'Rojo', true)]),
    $funcion('30000000-0000-4000-8000-000000000006', '2026-10-15T20:00:00', GALPON, 'El Galpón', [$obra(FORMULA, '=HIPERVINCULO("x")', true)]),
];
$deOtra = [$funcion('30000000-0000-4000-8000-000000000005', '2026-11-08T20:00:00', NORTE, 'Cineclub Norte', [$obra(ZAMA, 'Zama', true)], [], 'cccccccc-cccc-4ccc-8ccc-cccccccccccc')];

$funciones = match ($_GET['organizador'] ?? null) {
    ORGANIZADORA => $delOrganizador,
    null => [...$delOrganizador, ...$deOtra],
    default => [],
};
$delPeriodo = array_values(array_filter(
    $funciones,
    static fn (array $f): bool => substr($f['fecha_hora'], 0, 10) >= $_GET['desde'] && substr($f['fecha_hora'], 0, 10) <= $_GET['hasta'],
));
// De la primera a la última, como programación.
usort($delPeriodo, static fn (array $a, array $b): int => [$a['fecha_hora'], $a['id']] <=> [$b['fecha_hora'], $b['id']]);
echo json_encode(['funciones' => $delPeriodo, 'completas' => true]);
