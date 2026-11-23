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
 * - Cineclub Norte es de otra organizadora: sólo la ve el administrador. Proyectó «Zama», que es
 *   de la organizadora: como dueña, la ve.
 * - «La ciénaga» es de otra dueña (EF-09.3).
 * - En octubre hay una más en El Galpón, con un título que empieza como una fórmula de planilla,
 *   para el CSV (EF-09.2).
 *
 * Como programación, devuelve sólo las del período, con los dos días incluidos, de la primera a la
 * última.
 *
 * Sin «hasta» atiende el otro modo, el cursor de la tarea del reparto (EF-08.2): las que se
 * finalizaron desde un momento, por fecha de finalización. Ésas son otras funciones, más abajo.
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
const DUENA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
// Las del reparto (EF-08.2), que no son las de la reportería: ver el cursor, más abajo.
const SIMPLE = '50000000-0000-4000-8000-000000000001';
const TRES_OBRAS = '50000000-0000-4000-8000-000000000002';
const A_LA_GORRA_REPARTO = '50000000-0000-4000-8000-000000000003';
const VIEJA = '50000000-0000-4000-8000-000000000004';

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
// Los géneros (EF-22.18): «Rojo», que llena los sábados, es el único de suspenso.
$generos = [ZAMA => ['Drama'], CIENAGA => ['Drama'], ROJO => ['Drama', 'Suspenso'], FORMULA => []];
$obra = static fn (string $id, string $titulo, bool $elegida, int $votos = 0): array => ['id' => $id, 'titulo' => $titulo, 'dueno_id' => $id === CIENAGA ? DUENA : ORGANIZADORA, 'elegida' => $elegida, 'votos' => $votos, 'generos' => $generos[$id]];
$funcion = static fn (
    string $id,
    string $fecha,
    string $sala,
    string $nombre,
    array $obras,
    array $votantes = [],
    string $organizador = ORGANIZADORA,
    string $precio = '3500.00',
    ?string $finalizada = null,
): array => [
    'id' => $id,
    'fecha_hora' => $fecha,
    // Como programación: cuándo la finalizó la tarea. Las de la reportería, a su misma hora.
    'finalizada_en' => $finalizada ?? $fecha,
    'cupo' => 40,
    'precio_base' => $precio,
    'sala' => ['id' => $sala, 'nombre' => $nombre],
    'organizador_id' => $organizador,
    'obras' => $obras,
    'votantes_ids' => $votantes,
];

// Sin «hasta», el cursor de la tarea del reparto (EF-08.2): las que se finalizaron desde un
// momento, por fecha de finalización y de la primera a la última. Son funciones aparte de las de
// la reportería, con sus cobros en Dobles/funciones.php, armadas para probar el reparto:
//
// - SIMPLE: 10.000 cobrados y una obra, que con 70/15/15 da 7.000, 1.500 y 1.500.
// - TRES_OBRAS: 100,00 y tres obras, dos de la misma dueña, para el redondeo y las partes iguales.
// - A_LA_GORRA: sin precio, así que no reparte.
// - VIEJA: de octubre, finalizada la última: entra por su finalización y no por su fecha.
if (!isset($_GET['hasta'])) {
    if (!isset($_GET['desde']) || strlen((string) $_GET['desde']) !== 19) {
        http_response_code(400);
        echo '{"error":"Se pide «desde» en ISO 8601."}';

        return;
    }
    if (isset($_GET['organizador']) || isset($_GET['dueno'])) {
        http_response_code(400);
        echo '{"error":"Las finalizadas desde un momento van todas."}';

        return;
    }
    $delReparto = [
        $funcion(SIMPLE, '2026-11-20T21:00:00', GALPON, 'El Galpón', [$obra(ZAMA, 'Zama', true)], precio: '2500.00', finalizada: '2026-11-20T23:20:00'),
        $funcion(TRES_OBRAS, '2026-11-21T21:00:00', GALPON, 'El Galpón', [
            $obra(ZAMA, 'Zama', true),
            $obra(ROJO, 'Rojo', true),
            $obra(CIENAGA, 'La ciénaga', true),
        ], precio: '100.00', finalizada: '2026-11-21T23:20:00'),
        $funcion(A_LA_GORRA_REPARTO, '2026-11-22T20:00:00', GALPON, 'El Galpón', [$obra(ZAMA, 'Zama', true)], precio: '0.00', finalizada: '2026-11-22T22:20:00'),
        $funcion(VIEJA, '2026-10-05T20:00:00', GALPON, 'El Galpón', [$obra(ROJO, 'Rojo', true)], finalizada: '2026-11-23T10:00:00'),
    ];
    $desde = (string) $_GET['desde'];
    $pendientes = array_values(array_filter($delReparto, static fn (array $f): bool => $f['finalizada_en'] >= $desde));
    usort($pendientes, static fn (array $a, array $b): int => [$a['finalizada_en'], $a['id']] <=> [$b['finalizada_en'], $b['id']]);
    echo json_encode(['funciones' => $pendientes, 'completas' => true]);

    return;
}
if (!isset($_GET['desde'])) {
    http_response_code(400);
    echo '{"error":"Falta el período."}';

    return;
}
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
// Con ?dueno=, las que tuvieron alguna obra suya, en cualquier sala.
if (isset($_GET['dueno'])) {
    $funciones = array_filter(
        [...$delOrganizador, ...$deOtra],
        static fn (array $f): bool => in_array($_GET['dueno'], array_column($f['obras'], 'dueno_id'), true),
    );
}
$delPeriodo = array_values(array_filter(
    $funciones,
    static fn (array $f): bool => substr($f['fecha_hora'], 0, 10) >= $_GET['desde'] && substr($f['fecha_hora'], 0, 10) <= $_GET['hasta'],
));
// De la primera a la última, como programación.
usort($delPeriodo, static fn (array $a, array $b): int => [$a['fecha_hora'], $a['id']] <=> [$b['fecha_hora'], $b['id']]);
echo json_encode(['funciones' => $delPeriodo, 'completas' => true]);
