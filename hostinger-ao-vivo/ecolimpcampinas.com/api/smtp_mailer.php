<?php
function smtp_read_response($socket) {
    $data = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }
    return $data;
}

function smtp_expect($socket, $codes) {
    $response = smtp_read_response($socket);
    foreach ((array)$codes as $code) {
        if (strpos($response, (string)$code) === 0) {
            return $response;
        }
    }
    throw new Exception(trim($response));
}

function smtp_send_command($socket, $command, $codes) {
    fwrite($socket, $command . "\r\n");
    return smtp_expect($socket, $codes);
}

function smtp_encode_header($text) {
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function smtp_send_mail($config, $to, $subject, $body, $headers) {
    $host = $config['host'] ?? '';
    $port = (int)($config['port'] ?? 0);
    $user = $config['user'] ?? '';
    $pass = $config['pass'] ?? '';
    $secure = $config['secure'] ?? 'tls';

    if (!$host || !$user || !$pass) {
        throw new Exception('SMTP não configurado');
    }

    if (!$port) {
        $port = $secure === 'ssl' ? 465 : 587;
    }

    $transport = $secure === 'ssl' ? 'ssl://' : 'tcp://';
    $socket = stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15);
    if (!$socket) {
        throw new Exception('Falha ao conectar SMTP: ' . $errstr);
    }

    smtp_expect($socket, ['220']);
    smtp_send_command($socket, 'EHLO ' . $host, ['250']);

    if ($secure === 'tls') {
        smtp_send_command($socket, 'STARTTLS', ['220']);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception('Falha ao iniciar TLS');
        }
        smtp_send_command($socket, 'EHLO ' . $host, ['250']);
    }

    smtp_send_command($socket, 'AUTH LOGIN', ['334']);
    smtp_send_command($socket, base64_encode($user), ['334']);
    smtp_send_command($socket, base64_encode($pass), ['235']);

    $fromEmail = $config['from'] ?? $user;
    $fromName = $config['from_name'] ?? '';
    $encodedSubject = smtp_encode_header($subject);
    $fullHeaders = [];
    $fullHeaders[] = 'From: ' . ($fromName ? smtp_encode_header($fromName) . " <{$fromEmail}>" : $fromEmail);
    $fullHeaders[] = 'To: ' . $to;
    $fullHeaders[] = 'Subject: ' . $encodedSubject;
    $fullHeaders[] = 'MIME-Version: 1.0';
    $fullHeaders[] = $headers;
    $headerString = implode("\r\n", $fullHeaders);

    smtp_send_command($socket, 'MAIL FROM:<' . $fromEmail . '>', ['250']);
    smtp_send_command($socket, 'RCPT TO:<' . $to . '>', ['250', '251']);
    smtp_send_command($socket, 'DATA', ['354']);
    fwrite($socket, $headerString . "\r\n\r\n" . $body . "\r\n.\r\n");
    smtp_expect($socket, ['250']);
    smtp_send_command($socket, 'QUIT', ['221']);
    fclose($socket);
    return true;
}