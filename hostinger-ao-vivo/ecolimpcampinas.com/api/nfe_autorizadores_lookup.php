<?php
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$uf = strtoupper($_GET['uf'] ?? '');
$ambiente = $_GET['ambiente'] ?? 'producao';
if (!$uf || strlen($uf) !== 2) {
    http_response_code(400);
    echo json_encode(['error' => 'UF inválida']);
    exit;
}

$svrsUfs = ['AC','AL','AP','CE','DF','ES','PA','PB','PI','RJ','RN','RO','RR','SC','SE','TO'];
$svaUfs = ['MA'];

$baseUrl = $ambiente === 'homologacao'
    ? 'https://hom.nfe.fazenda.gov.br/portal/webservices.aspx'
    : 'https://www.nfe.fazenda.gov.br/portal/webservices.aspx';

$context = stream_context_create([
    'http' => [
        'timeout' => 15,
        'header' => "User-Agent: Mozilla/5.0\r\n"
    ]
]);

$html = @file_get_contents($baseUrl, false, $context);
if (!$html) {
    http_response_code(502);
    echo json_encode(['error' => 'Falha ao consultar portal NF-e']);
    exit;
}

$getUrlFromBlock = function ($block) {
    if (preg_match('/https?:\/\/[^\s"<]*NFeAutorizacao4[^\s"<]*/i', $block, $m)) {
        return str_replace('?wsdl', '', $m[0]);
    }
    return null;
};

$url = null;

if (in_array($uf, $svaUfs, true)) {
    if (preg_match('/SVAN[\s\S]*?(?=SVRS|Sefaz|$)/i', $html, $m)) {
        $url = $getUrlFromBlock($m[0]);
    }
} elseif (in_array($uf, $svrsUfs, true)) {
    if (preg_match('/SVRS[\s\S]*?(?=SVAN|Sefaz|$)/i', $html, $m)) {
        $url = $getUrlFromBlock($m[0]);
    }
} else {
    $pattern = '/Sefaz\s+[^<]*\(' . preg_quote($uf, '/') . '\)[\s\S]*?(?=Sefaz\s+[^<]*\(|SVAN|SVRS|$)/i';
    if (preg_match($pattern, $html, $m)) {
        $url = $getUrlFromBlock($m[0]);
    }
}

if (!$url) {
    http_response_code(404);
    echo json_encode(['error' => 'URL de autorizador não encontrada']);
    exit;
}

echo json_encode(['url' => $url]);