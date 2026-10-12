<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    
    // Validate required fields
    if ((!isset($data['customerName']) && !isset($data['cliente_nome'])) || !isset($data['total'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    // Generate short 8-char ID
    $id = strtoupper(substr(uniqid(), -8));
    
    $customerName = $data['customerName'] ?? $data['cliente_nome'] ?? '';
    $customerPhone = $data['customerPhone'] ?? $data['cliente_telefone'] ?? '';
    $total = $data['total'];
    
    // Accept status from payload, default to 'novo'
    $status = $data['status'] ?? $data['status_pedido'] ?? 'novo'; 
    
    $address = $data['address'] ?? $data['endereco_completo'] ?? ''; // This is likely just the street
    $houseNumber = $data['house_number'] ?? $data['numero_casa'] ?? ''; // New field
    
    // Construct full address if needed, or just use what's passed
    // The schema has endereco_completo AND numero_casa.
    // Frontend sends 'address' (street or full) and 'house_number'.
    
    $enderecoCompleto = $address;
    
    // Only append house number if it's NOT already in the address string
    if (!empty($houseNumber) && strpos($enderecoCompleto, (string)$houseNumber) === false) {
        $enderecoCompleto .= ", " . $houseNumber;
    }
    // Also check for comma/space formatting to be neat, but the main thing is avoiding "Rua X, 123, 123"

    $paymentMethod = $data['paymentMethod'] ?? $data['forma_pagamento'] ?? 'pix';
    $notes = $data['notes'] ?? $data['observacoes'] ?? '';
    $items = isset($data['items']) ? $data['items'] : (isset($data['itens']) ? $data['itens'] : []); // Array of items

    // Set timezone to Brazil/Sao_Paulo
    date_default_timezone_set('America/Sao_Paulo');
    $criadoEm = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();

        // 1. Insert Order
        $stmt = $pdo->prepare("INSERT INTO pedidos (id, cliente_nome, cliente_telefone, total, status, endereco_completo, numero_casa, forma_pagamento, observacoes, criado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $id,
            $customerName,
            $customerPhone,
            $total,
            $status,
            $enderecoCompleto,
            $houseNumber,
            $paymentMethod,
            $notes,
            $criadoEm
        ]);

        // 2. Insert Items
        if (!empty($items)) {
            $stmtItem = $pdo->prepare("INSERT INTO itens_pedido (id, pedido_id, produto_id, produto_nome, quantidade, preco_unitario) VALUES (?, ?, ?, ?, ?, ?)");
            
            foreach ($items as $item) {
                $itemId = uniqid('item_');
                $prodId = $item['productId'] ?? ($item['produto_id'] ?? null);
                $prodName = $item['productName'] ?? ($item['produto_nome'] ?? 'Produto Desconhecido');
                $qty = $item['quantity'] ?? ($item['quantidade'] ?? 1);
                $price = $item['price'] ?? ($item['preco_unitario'] ?? 0);
                
                $stmtItem->execute([
                    $itemId,
                    $id,
                    $prodId,
                    $prodName,
                    $qty,
                    $price
                ]);
            }
        }
        
        $pdo->commit();

        // --- WHATSAPP NOTIFICATION START ---
        // Skip notification for PDV orders
        if ($customerName !== 'Venda Balcão') {
            try {
                require_once 'whatsapp_service.php';
                
                // Fetch Store Config
                $stmtConfig = $pdo->query("SELECT nome_empresa, whatsapp_loja FROM configuracoes WHERE id = 1");
                $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);
                
                $storeName = $config['nome_empresa'] ?? 'Nossa Loja';
                $adminPhone = $config['whatsapp_loja'] ?? ''; 
                
                $waService = new WhatsAppService();
                
                // 1. Send to Customer
                if ($customerPhone) {
                    $msgCustomer = "Olá *{$customerName}*! 👋\n\n";
                    $msgCustomer .= "Recebemos seu pedido *order: {$id}* com sucesso!\n";
                    $msgCustomer .= "💰 Total: R$ " . number_format($total, 2, ',', '.') . "\n";
                    $msgCustomer .= "📦 Status: Aguardando confirmação\n\n";
                    $msgCustomer .= "Em breve confirmaremos a entrega.\n";
                    $msgCustomer .= "Obrigado por comprar na *{$storeName}*!";
                    
                    $waService->sendText($customerPhone, $msgCustomer);
                }
                
                // 2. Send to Admin (Store)
                if ($adminPhone) {
                    // Format requested by user:
                    // "acabei de fazer um pedido, order xxxxxxxx. aguardo a entrega"
                    // + full details
                    
                    $msgAdmin = "acabei de fazer um pedido, order {$id}. aguardo a entrega\n\n";
                    
                    $msgAdmin .= "📋 *DETALHES DO PEDIDO*\n";
                    $msgAdmin .= "👤 Cliente: {$customerName}\n";
                    $msgAdmin .= "📱 Tel: {$customerPhone}\n";
                    $msgAdmin .= "📍 Endereço: {$enderecoCompleto}\n";
                    $msgAdmin .= "💳 Pagamento: {$paymentMethod}\n\n";
                    
                    $msgAdmin .= "📦 *ITENS:*\n";
                    foreach ($items as $item) {
                        $iName = $item['productName'] ?? ($item['produto_nome'] ?? 'Item');
                        $iQty = $item['quantity'] ?? ($item['quantidade'] ?? 1);
                        $iPrice = $item['price'] ?? ($item['preco_unitario'] ?? 0);
                        $msgAdmin .= "- {$iQty}x {$iName} (R$ " . number_format($iPrice * $iQty, 2, ',', '.') . ")\n";
                    }
                    
                    $msgAdmin .= "\n💰 *TOTAL: R$ " . number_format($total, 2, ',', '.') . "*";
                    
                    $waService->sendText($adminPhone, $msgAdmin);
                }
                
            } catch (Exception $e) {
                // Ignore notification errors to not block the order response
                error_log("WhatsApp Notification Error: " . $e->getMessage());
            }
        }
        // --- WHATSAPP NOTIFICATION END ---
        
        echo json_encode(['id' => $id, 'success' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// GET (Admin fetch)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = $_GET['id'] ?? null;

    if ($id) {
        try {
            // Fetch single order
            $stmt = $pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
            $stmt->execute([$id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($order) {
                $stmtItems = $pdo->prepare("SELECT * FROM itens_pedido WHERE pedido_id = ?");
                $stmtItems->execute([$id]);
                $order['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                
                // Map legacy fields
                $order['endereco'] = $order['endereco_completo'];
                
                echo json_encode($order);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Order not found']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    } else {
        try {
            $stmt = $pdo->query("SELECT * FROM pedidos ORDER BY criado_em DESC LIMIT 50");
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // For each order, fetch items
            foreach ($orders as &$order) {
                $stmtItems = $pdo->prepare("SELECT * FROM itens_pedido WHERE pedido_id = ?");
                $stmtItems->execute([$order['id']]);
                $order['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
                
                // Map legacy fields for frontend compatibility if needed
                $order['endereco'] = $order['endereco_completo']; 
            }
            
            echo json_encode($orders);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true);
    $id = $data['id'] ?? null;
    $status = $data['status'] ?? null;
    
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID required']);
        exit;
    }
    
    try {
        $hasDetails = isset($data['items']) || isset($data['itens']) || isset($data['customerName']) || isset($data['cliente_nome']) || isset($data['customerPhone']) || isset($data['cliente_telefone']) || isset($data['total']) || isset($data['paymentMethod']) || isset($data['forma_pagamento']) || isset($data['address']) || isset($data['endereco_completo']) || isset($data['notes']) || isset($data['observacoes']) || isset($data['house_number']) || isset($data['numero_casa']);

        if (!$hasDetails) {
            if (!$status) {
                http_response_code(400);
                echo json_encode(['error' => 'ID and status required']);
                exit;
            }

            $stmtOrder = $pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
            $stmtOrder->execute([$id]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("UPDATE pedidos SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            
            if ($order && !empty($order['cliente_telefone'])) {
                 try {
                    require_once 'whatsapp_service.php';
                    $waService = new WhatsAppService();
                    
                    $msg = "Olá *{$order['cliente_nome']}*! 👋\n\n";
                    $msg .= "O status do seu pedido *#{$id}* mudou para:\n";
                    $msg .= "*".strtoupper(str_replace('_', ' ', $status))."*\n\n";
                    
                    if ($status === 'saiu_entrega') {
                        $msg .= "🛵 Seu pedido saiu para entrega! Fique atento.\n";
                    } elseif ($status === 'entregue') {
                        $msg .= "✅ Pedido entregue. Obrigado pela preferência!\n";
                    } elseif ($status === 'cancelado') {
                        $msg .= "❌ Pedido cancelado. Entre em contato se houver dúvidas.\n";
                    }
                    
                    $waService->sendText($order['cliente_telefone'], $msg);
                 } catch (Exception $e) {
                    error_log("WA Status Update Error: " . $e->getMessage());
                 }
            }

            echo json_encode(['success' => true]);
        } else {
            $pdo->beginTransaction();

            $stmtOrder = $pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
            $stmtOrder->execute([$id]);
            $existing = $stmtOrder->fetch(PDO::FETCH_ASSOC);

            if (!$existing) {
                $pdo->rollBack();
                http_response_code(404);
                echo json_encode(['error' => 'Order not found']);
                exit;
            }

            $customerName = $data['customerName'] ?? $data['cliente_nome'] ?? $existing['cliente_nome'] ?? '';
            $customerPhone = $data['customerPhone'] ?? $data['cliente_telefone'] ?? $existing['cliente_telefone'] ?? '';
            $total = $data['total'] ?? $existing['total'];
            $statusValue = $data['status'] ?? $existing['status'] ?? 'novo';
            $address = $data['address'] ?? $data['endereco_completo'] ?? $existing['endereco_completo'] ?? '';
            $houseNumber = $data['house_number'] ?? $data['numero_casa'] ?? $existing['numero_casa'] ?? '';
            $paymentMethod = $data['paymentMethod'] ?? $data['forma_pagamento'] ?? $existing['forma_pagamento'] ?? 'pix';
            $notes = $data['notes'] ?? $data['observacoes'] ?? $existing['observacoes'] ?? '';

            $enderecoCompleto = $address;
            if (!empty($houseNumber) && strpos($enderecoCompleto, (string)$houseNumber) === false) {
                $enderecoCompleto .= ", " . $houseNumber;
            }

            $stmt = $pdo->prepare("UPDATE pedidos SET cliente_nome = ?, cliente_telefone = ?, total = ?, status = ?, endereco_completo = ?, numero_casa = ?, forma_pagamento = ?, observacoes = ? WHERE id = ?");
            $stmt->execute([
                $customerName,
                $customerPhone,
                $total,
                $statusValue,
                $enderecoCompleto,
                $houseNumber,
                $paymentMethod,
                $notes,
                $id
            ]);

            if (isset($data['items']) || isset($data['itens'])) {
                $items = isset($data['items']) ? $data['items'] : $data['itens'];
                $stmtDelete = $pdo->prepare("DELETE FROM itens_pedido WHERE pedido_id = ?");
                $stmtDelete->execute([$id]);

                if (!empty($items)) {
                    $stmtItem = $pdo->prepare("INSERT INTO itens_pedido (id, pedido_id, produto_id, produto_nome, quantidade, preco_unitario) VALUES (?, ?, ?, ?, ?, ?)");
                    foreach ($items as $item) {
                        $itemId = uniqid('item_');
                        $prodId = $item['productId'] ?? ($item['produto_id'] ?? null);
                        $prodName = $item['productName'] ?? ($item['produto_nome'] ?? 'Produto Desconhecido');
                        $qty = $item['quantity'] ?? ($item['quantidade'] ?? 1);
                        $price = $item['price'] ?? ($item['preco_unitario'] ?? 0);

                        $stmtItem->execute([
                            $itemId,
                            $id,
                            $prodId,
                            $prodName,
                            $qty,
                            $price
                        ]);
                    }
                }
            }

            $pdo->commit();

            $stmtOrder = $pdo->prepare("SELECT * FROM pedidos WHERE id = ?");
            $stmtOrder->execute([$id]);
            $order = $stmtOrder->fetch(PDO::FETCH_ASSOC);

            $stmtItems = $pdo->prepare("SELECT * FROM itens_pedido WHERE pedido_id = ?");
            $stmtItems->execute([$id]);
            $order['items'] = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
            $order['endereco'] = $order['endereco_completo'];

            echo json_encode($order);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
}
?>