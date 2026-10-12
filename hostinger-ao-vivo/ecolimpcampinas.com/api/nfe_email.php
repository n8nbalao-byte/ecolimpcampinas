<?php
require_once 'db.php';
require_once 'smtp_mailer.php';

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

$numero = $input['numero'] ?? null;
if (!$numero) {
    http_response_code(400);
    echo json_encode(['error' => 'Número da nota obrigatório']);
    exit;
}

try {
    $stmtNota = $pdo->prepare("SELECT n.*, c.razao_social, c.cnpj, c.email FROM nfe_notas n JOIN nfe_clientes c ON n.cliente_id = c.id WHERE n.numero = ?");
    $stmtNota->execute([$numero]);
    $nota = $stmtNota->fetch(PDO::FETCH_ASSOC);
    if (!$nota) {
        throw new Exception('Nota não encontrada');
    }
    if (($nota['sefaz_status'] ?? '') !== '100') {
        throw new Exception('Apenas notas autorizadas podem ser enviadas por email');
    }

    $email = $input['email'] ?? $nota['email'] ?? '';
    if (!$email) {
        throw new Exception('Email do cliente não cadastrado');
    }

    $stmtItens = $pdo->prepare("SELECT * FROM nfe_itens WHERE nfe_numero = ? ORDER BY criado_em ASC");
    $stmtItens->execute([$numero]);
    $itens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

    $stmtConfig = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);

    $emitente = $config['nome_empresa'] ?? 'Emitente';
    $danfeHtml = '<html><head><meta charset="utf-8"><style>'
        . 'body{font-family:Arial, sans-serif;font-size:12px;color:#111;}'
        . '.box{border:1px solid #000;padding:8px;margin-bottom:8px;}'
        . '.label{font-size:10px;text-transform:uppercase;color:#333;}'
        . '.value{font-size:12px;font-weight:600;}'
        . 'table{width:100%;border-collapse:collapse;}'
        . 'th,td{border:1px solid #000;padding:6px;font-size:10px;}'
        . 'th{background:#f0f0f0;text-transform:uppercase;}'
        . '</style></head><body>';
    $danfeHtml .= '<div class="box"><div class="value">DANFE - NF-e ' . $nota['numero'] . ' Série ' . $nota['serie'] . '</div>';
    $danfeHtml .= '<div class="label">Emitente</div><div class="value">' . $emitente . '</div></div>';
    $danfeHtml .= '<div class="box"><div class="label">Destinatário</div><div class="value">' . $nota['razao_social'] . '</div>';
    $danfeHtml .= '<div class="value">CNPJ: ' . $nota['cnpj'] . '</div></div>';
    $danfeHtml .= '<table><thead><tr><th>Item</th><th>Descrição</th><th>Qtd</th><th>Unitário</th><th>Total</th></tr></thead><tbody>';
    $idx = 1;
    foreach ($itens as $item) {
        $danfeHtml .= '<tr><td>' . $idx . '</td><td>' . ($item['descricao'] ?? '') . '</td><td>' . ($item['quantidade'] ?? '') . '</td><td>R$ ' . number_format((float)$item['valor_unitario'], 2, ',', '.') . '</td><td>R$ ' . number_format((float)$item['total'], 2, ',', '.') . '</td></tr>';
        $idx++;
    }
    $danfeHtml .= '</tbody></table>';
    $danfeHtml .= '<div class="box"><div class="value">Total: R$ ' . number_format((float)$nota['total'], 2, ',', '.') . '</div></div>';
    $danfeHtml .= '</body></html>';

    $boundary = md5(uniqid(time()));
    $subject = 'NF-e Autorizada - Nº ' . $nota['numero'];
    $from = $config['email_loja'] ?? 'no-reply@ecolimpcampinas.com';

    $headers = "Content-Type: multipart/mixed; boundary=\"{$boundary}\"";

    $body = "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
    $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
    $body .= "<p>Sua NF-e foi autorizada.</p>";
    $body .= "<p>Número: {$nota['numero']} | Série: {$nota['serie']}</p>";
    $body .= "<p>Protocolo: " . ($nota['sefaz_protocolo'] ?? 'Pendente') . "</p>";
    $body .= "\r\n";

    if (!empty($nota['xml'])) {
        $xmlContent = $nota['xml'];
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/xml; name=\"nfe-{$nota['numero']}.xml\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= "Content-Disposition: attachment; filename=\"nfe-{$nota['numero']}.xml\"\r\n\r\n";
        $body .= chunk_split(base64_encode($xmlContent)) . "\r\n";
    }

    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; name=\"danfe-{$nota['numero']}.html\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"danfe-{$nota['numero']}.html\"\r\n\r\n";
    $body .= chunk_split(base64_encode($danfeHtml)) . "\r\n";
    $body .= "--{$boundary}--";

    $smtpConfig = [
        'host' => $config['smtp_host'] ?? getenv('SMTP_HOST') ?? '',
        'port' => $config['smtp_port'] ?? getenv('SMTP_PORT') ?? '',
        'user' => $config['smtp_user'] ?? getenv('SMTP_USER') ?? '',
        'pass' => $config['smtp_pass'] ?? getenv('SMTP_PASS') ?? '',
        'secure' => $config['smtp_secure'] ?? getenv('SMTP_SECURE') ?? 'tls',
        'from' => $config['smtp_from'] ?? getenv('SMTP_FROM') ?? $from,
        'from_name' => $config['smtp_from_name'] ?? getenv('SMTP_FROM_NAME') ?? $emitente,
    ];

    smtp_send_mail($smtpConfig, $email, $subject, $body, $headers);

    echo json_encode(['success' => true, 'message' => 'Email enviado', 'email' => $email]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}