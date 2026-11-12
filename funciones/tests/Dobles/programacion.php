<?php

/*
 * Programación de mentira, atendiendo con `php -S` las rutas que llama funciones: así
 * FuncionesDeProgramacionTest prueba el cliente por la red, como en el compose. Responde lo mismo
 * que GET /interno/funciones/{id} y GET /interno/funciones?ids=… de programación
 * (FuncionesInternasTest de programación prueba ese mismo JSON del otro lado), y como ellas, sólo
 * a funciones con su token.
 */

declare(strict_types=1);

const PROGRAMADA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const EN_VOTACION = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
const ROTA = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
const LENTA = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
const SIN_PUBLICAR = '99999999-9999-4999-8999-999999999999';
const PROXIMA = '12121212-1212-4212-8212-121212121212';
const EMPEZADA = '34343434-3434-4434-8434-343434343434';
const CANCELADA = '56565656-5656-4656-8656-565656565656';
const A_LA_GORRA = '78787878-7878-4878-8878-787878787878';
const FINALIZADA = '13131313-1313-4313-8313-131313131313';
const VIEJA = '24242424-2424-4424-8424-242424242424';
// Empieza en cinco horas: la que pide el recordatorio del día antes (EF-03.3).
const EN_CINCO_HORAS = '45454545-4545-4545-8545-454545454545';

header('Content-Type: application/json');
$cabeceras = getallheaders();
if (($cabeceras['X-Llamador'] ?? null) !== 'funciones' || ($cabeceras['Authorization'] ?? null) !== 'Bearer token-de-funciones') {
    http_response_code(403);
    echo '{"error":"Llamador no autorizado."}';

    return;
}

$funcion = static fn (string $id, string $estado, ?string $fecha, array $titulos, bool $elegidos, bool $publicada = true, int $precio = 3500): array => [
    'id' => $id,
    'estado' => $estado,
    'fecha_hora' => $fecha,
    'precio_base' => $precio,
    'cupo' => 40,
    'titulos' => $titulos,
    'titulos_elegidos' => $elegidos,
    'sala' => ['id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'nombre' => 'Cine Club El Galpón', 'localidad' => 'Luján', 'habilitada' => $publicada, 'direccion' => 'Av. San Martín 450', 'duracion_funcion' => 120],
    'organizador_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
    'obras_ids' => array_map(static fn (string $titulo): string => $titulo === 'Zama' ? '0b0b0b0b-0b0b-40b0-80b0-0b0b0b0b0b0b' : '0c0c0c0c-0c0c-40c0-80c0-0c0c0c0c0c0c', $titulos),
];

/**
 * La función de ese id como la manda programación, o null si no la conoce. LENTA tarda dos
 * segundos, más que lo que espera el cliente.
 */
$responder = static function (string $id) use ($funcion): ?array {
    return match ($id) {
        PROGRAMADA => $funcion($id, 'programada', '2026-10-24T20:30:00', ['Zama'], true),
        EN_VOTACION => $funcion($id, 'en_votacion_titulos', null, ['La ciénaga', 'Zama'], false),
        // Para las pruebas que reservan con la hora de verdad: dentro de un mes, y una que empezó
        // hace una hora.
        PROXIMA => $funcion($id, 'programada', date('Y-m-d\\TH:i:s', strtotime('+30 days')), ['Zama'], true),
        EMPEZADA => $funcion($id, 'programada', date('Y-m-d\\TH:i:s', strtotime('-1 hour')), ['Zama'], true),
        EN_CINCO_HORAS => $funcion($id, 'programada', date('Y-m-d\\TH:i:s', strtotime('+5 hours')), ['Zama'], true),
        // Terminaron: una hace dos días, que se puede calificar, y otra hace cuarenta, fuera del
        // plazo (EF-04.2).
        FINALIZADA => $funcion($id, 'finalizada', date('Y-m-d\\TH:i:s', strtotime('-2 days')), ['Zama'], true),
        VIEJA => $funcion($id, 'finalizada', date('Y-m-d\\TH:i:s', strtotime('-40 days')), ['Zama'], true),
        // Sin precio: no hay nada que cobrar (X-01).
        A_LA_GORRA => $funcion($id, 'programada', date('Y-m-d\\TH:i:s', strtotime('+30 days')), ['Zama'], true, precio: 0),
        // El organizador la canceló: el panel de sus reservas no registra asistencia.
        CANCELADA => $funcion($id, 'cancelada', date('Y-m-d\\TH:i:s', strtotime('+30 days')), ['Zama'], true),
        // Su sala todavía no se aprobó, o se retiró de la cartelera.
        SIN_PUBLICAR => $funcion($id, 'programada', '2026-10-24T20:30:00', ['Zama'], true, false),
        // Un cambio en programación que rompe lo que funciones espera.
        ROTA => ['id' => $id, 'estado' => 'programada'],
        LENTA => (static function (): ?array {
            sleep(2);

            return null;
        })(),
        default => null,
    };
};

$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($ruta === '/interno/funciones/proximas') {
    // Las programadas que conoce y caen en el rango, como GET /interno/funciones/proximas (EF-03.3).
    $desde = strtotime((string) ($_GET['desde'] ?? ''));
    $hasta = strtotime((string) ($_GET['hasta'] ?? ''));
    $proximas = [];
    foreach ([PROXIMA, EN_CINCO_HORAS] as $id) {
        $datos = $responder($id);
        $cuando = strtotime((string) $datos['fecha_hora']);
        if ($cuando > $desde && $cuando <= $hasta) {
            $proximas[] = $datos;
        }
    }
    echo json_encode($proximas);

    return;
}
if ($ruta === '/interno/funciones') {
    // Las de una página de «Mis reservas»: las que no conoce no vienen.
    $varias = [];
    foreach (explode(',', (string) ($_GET['ids'] ?? '')) as $id) {
        $datos = $responder($id);
        if ($datos !== null) {
            $varias[$id] = $datos;
        }
    }
    echo json_encode((object) $varias);

    return;
}

$datos = $responder((string) preg_replace('#^/interno/funciones/#', '', $ruta));
if ($datos === null) {
    http_response_code(404);

    return;
}
echo json_encode($datos);
