<?php
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$pfxBase64 = $input['pfx_base64'] ?? '';
$senha = $input['senha'] ?? '';

if (!$pfxBase64) {
    http_response_code(400);
    echo json_encode(['error' => 'Certificado não informado']);
    exit;
}

try {
    $pfx = base64_decode($pfxBase64);
    if (!$pfx) {
        throw new Exception('Certificado inválido');
    }
    $certs = [];
    if (!openssl_pkcs12_read($pfx, $certs, $senha)) {
        throw new Exception('Senha incorreta ou certificado inválido');
    }
    $certData = openssl_x509_parse($certs['cert']);
    $subject = $certData['subject'] ?? [];
    $serial = $subject['serialNumber'] ?? '';
    $cnpj = null;
    if ($serial) {
        if (preg_match('/\d{14}/', $serial, $matches)) {
            $cnpj = $matches[0];
        }
    }
    if (!$cnpj && !empty($subject['CN'])) {
        if (preg_match('/\d{14}/', $subject['CN'], $matches)) {
            $cnpj = $matches[0];
        }
    }
    echo json_encode([
        'cnpj' => $cnpj,
        'subject' => $subject,
        'valid_from' => $certData['validFrom_time_t'] ?? null,
        'valid_to' => $certData['validTo_time_t'] ?? null
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}