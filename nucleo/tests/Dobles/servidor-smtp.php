<?php

/*
 * Un servidor SMTP de mentira, para que ClienteSmtp mande por la red de verdad sin un servidor de
 * correo: lo arranca ServidorSmtpDePrueba. Responde lo justo para que un cliente mande un correo y
 * anota cada uno en el archivo, como una línea de JSON con el remitente, los destinatarios y el
 * mensaje. Rechaza los destinatarios que empiezan con «rechazado@», como un servidor que no conoce
 * la dirección.
 *
 * Uso: php servidor-smtp.php <puerto> <archivo>
 */

declare(strict_types=1);

[, $puerto, $archivo] = $argv;
$servidor = stream_socket_server("tcp://127.0.0.1:{$puerto}");

while (($conexion = @stream_socket_accept($servidor, -1)) !== false) {
    $responder = static fn (string $linea): int|false => fwrite($conexion, "{$linea}\r\n");
    $responder('220 prueba ESMTP');
    $sobre = ['de' => null, 'para' => []];
    $mensaje = null;

    while (($linea = fgets($conexion)) !== false) {
        if ($mensaje !== null) {
            if ($linea !== ".\r\n") {
                $mensaje .= $linea;
                continue;
            }
            file_put_contents($archivo, json_encode($sobre + ['mensaje' => $mensaje]) . "\n", FILE_APPEND | LOCK_EX);
            $sobre = ['de' => null, 'para' => []];
            $mensaje = null;
            $responder('250 Recibido');
            continue;
        }

        $comando = strtoupper(substr($linea, 0, 4));
        $argumento = trim(substr($linea, strpos($linea, ':') + 1));
        if ($comando === 'MAIL') {
            $sobre['de'] = $argumento;
            $responder('250 OK');
        } elseif ($comando === 'RCPT' && str_starts_with($argumento, '<rechazado@')) {
            $responder('550 No existe esa dirección');
        } elseif ($comando === 'RCPT') {
            $sobre['para'][] = $argumento;
            $responder('250 OK');
        } elseif ($comando === 'DATA') {
            $mensaje = '';
            $responder('354 Mandá el mensaje');
        } elseif ($comando === 'QUIT') {
            $responder('221 Chau');
            break;
        } else {
            // EHLO, RSET y lo demás: de acuerdo, sin extensiones (ni STARTTLS ni AUTH).
            $responder('250 prueba');
        }
    }
    fclose($conexion);
}
