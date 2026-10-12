<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$cnpj = $_GET['cnpj'] ?? '';
$cnpj = preg_replace('/\D/', '', $cnpj);
if (strlen($cnpj) !== 14) {
    http_response_code(400);
    echo json_encode(['error' => 'CNPJ inválido']);
    exit;
}

$url = "https://www.receitaws.com.br/v1/cnpj/" . $cnpj;
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'Falha ao consultar CNPJ']);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    http_response_code(502);
    echo json_encode(['error' => 'Resposta inválida do serviço de CNPJ']);
    exit;
}

echo json_encode($data);