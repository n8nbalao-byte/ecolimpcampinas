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
if (!is_array($input)) $input = [];

$orderId = $input['order_id'] ?? null;
$targetEmail = $input['email'] ?? null;

if (!$orderId) {
    http_response_code(400);
    echo json_encode(['error' => 'order_id obrigatório']);
    exit;
}

$normalizeDigits = function ($v) { return preg_replace('/\D/', '', $v ?? ''); };

try {
    // Fetch order
    $stmt = $pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Pedido não encontrado']);
        exit;
    }

    // Fetch items
    $stmtItems = $pdo->prepare("SELECT * FROM itens_pedido WHERE pedido_id = ?");
    $stmtItems->execute([$orderId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    // Load store config
    $stmtConfig = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC) ?: [];

    // Resolve target email: prefer provided, else try match in nfe_clientes by phone or name
    $resolvedEmail = null;
    if ($targetEmail) {
        $resolvedEmail = trim($targetEmail);
    } else {
        $phone = $normalizeDigits($order['cliente_telefone'] ?? '');
        if ($phone) {
            $stmtC = $pdo->prepare("SELECT email FROM nfe_clientes WHERE REPLACE(telefone, ' ', '') LIKE ? AND email IS NOT NULL AND email <> '' LIMIT 1");
            $stmtC->execute(['%' . $phone . '%']);
            $rowC = $stmtC->fetch(PDO::FETCH_ASSOC);
            if ($rowC && !empty($rowC['email'])) {
                $resolvedEmail = $rowC['email'];
            }
        }
        if (!$resolvedEmail && !empty($order['cliente_nome'])) {
            $stmtC2 = $pdo->prepare("SELECT email FROM nfe_clientes WHERE razao_social = ? AND email IS NOT NULL AND email <> '' LIMIT 1");
            $stmtC2->execute([$order['cliente_nome']]);
            $rowC2 = $stmtC2->fetch(PDO::FETCH_ASSOC);
            if ($rowC2 && !empty($rowC2['email'])) {
                $resolvedEmail = $rowC2['email'];
            }
        }
    }

    if (!$resolvedEmail) {
        http_response_code(400);
        echo json_encode(['error' => 'E-mail do cliente não encontrado. Informe manualmente.']);
        exit;
    }

    // Build HTML email
    $storeName = $config['nome_empresa'] ?? 'Nossa Loja';
    $from = $config['email_loja'] ?? 'no-reply@ecolimpcampinas.com';
    $total = number_format((float)($order['total'] ?? 0), 2, ',', '.');
    $createdAt = $order['criado_em'] ? date('d/m/Y H:i', strtotime($order['criado_em'])) : '';

    $itemsHtml = '';
    foreach ($items as $it) {
        $qty = (float)($it['quantidade'] ?? 0);
        $unit = (float)($it['preco_unitario'] ?? 0);
        $line = number_format($qty * $unit, 2, ',', '.');
        $itemsHtml .= "<tr><td>{$qty}x</td><td>".htmlspecialchars($it['produto_nome'] ?? 'Item')."</td><td>R$ ".number_format($unit, 2, ',', '.')."</td><td>R$ {$line}</td></tr>";
    }

    $html = "<html><body style=\"font-family: Arial, sans-serif; color:#111;\">
      <h2 style=\"margin:0 0 8px 0;\">Pedido #{$orderId}</h2>
      <p style=\"margin:0 0 12px 0;\">Olá ".htmlspecialchars($order['cliente_nome'] ?? 'Cliente').", segue o resumo do seu pedido realizado em {$storeName}.</p>
      <table style=\"width:100%; border-collapse:collapse;\" border=\"1\" cellpadding=\"6\">
        <thead><tr><th>Qtd</th><th>Item</th><th>Unitário</th><th>Total</th></tr></thead>
        <tbody>{$itemsHtml}</tbody>
      </table>
      <p style=\"margin:12px 0; font-weight:bold;\">Total: R$ {$total}</p>
      <p style=\"margin:6px 0;\">Pagamento: ".htmlspecialchars($order['forma_pagamento'] ?? '')."</p>
      <p style=\"margin:6px 0;\">Data: {$createdAt}</p>
      <p style=\"margin:6px 0;\">Endereço: ".htmlspecialchars($order['endereco_completo'] ?? '')."</p>
      <hr style=\"margin:16px 0;\"/>
      <p style=\"font-size:12px; color:#555;\">{$storeName} • ".htmlspecialchars($config['telefone_loja'] ?? '')." • ".htmlspecialchars($config['endereco_loja'] ?? '')."</p>
    </body></html>";

    $subject = "Resumo do Pedido #{$orderId}";

    $smtpConfig = [
        'host' => $config['smtp_host'] ?? getenv('SMTP_HOST') ?? '',
        'port' => $config['smtp_port'] ?? getenv('SMTP_PORT') ?? '',
        'user' => $config['smtp_user'] ?? getenv('SMTP_USER') ?? '',
        'pass' => $config['smtp_pass'] ?? getenv('SMTP_PASS') ?? '',
        'secure' => $config['smtp_secure'] ?? getenv('SMTP_SECURE') ?? 'tls',
        'from' => $config['smtp_from'] ?? getenv('SMTP_FROM') ?? $from,
        'from_name' => $config['smtp_from_name'] ?? getenv('SMTP_FROM_NAME') ?? $storeName,
    ];

    $headers = "Content-Type: text/html; charset=UTF-8";
    smtp_send_mail($smtpConfig, $resolvedEmail, $subject, $html, $headers);

    echo json_encode(['success' => true, 'message' => 'Pedido enviado por email', 'email' => $resolvedEmail]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}