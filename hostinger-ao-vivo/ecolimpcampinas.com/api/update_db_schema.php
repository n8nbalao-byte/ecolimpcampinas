<?php
require_once 'db.php';

header("Content-Type: application/json");

try {
    // Verificar se a coluna codigo_barras existe
    $stmt = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'codigo_barras'");
    $exists = $stmt->fetch();

    if (!$exists) {
        // Adicionar a coluna
        $sql = "ALTER TABLE produtos ADD COLUMN codigo_barras VARCHAR(255) NULL AFTER nome";
        $pdo->exec($sql);
        echo json_encode(["status" => "success", "message" => "Coluna 'codigo_barras' adicionada com sucesso."]);
    } else {
        echo json_encode(["status" => "success", "message" => "Coluna 'codigo_barras' já existe."]);
    }

    // Verificar se a coluna imagem_url é TEXT para suportar base64
    $stmt = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'imagem_url'");
    $col = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Se for varchar curto, alterar para LONGTEXT para garantir suporte a base64
    if ($col && strpos(strtolower($col['Type']), 'text') === false) {
        $sql = "ALTER TABLE produtos MODIFY COLUMN imagem_url LONGTEXT";
        $pdo->exec($sql);
        echo json_encode(["status" => "success", "message" => "Coluna 'imagem_url' alterada para LONGTEXT."]);
    }

    // --- MIGRATION FOR PEDIDOS TABLE ---

    // 1. Check for 'status' column in 'pedidos'
    $stmt = $pdo->query("SHOW COLUMNS FROM pedidos LIKE 'status'");
    $exists = $stmt->fetch();
    if (!$exists) {
        $sql = "ALTER TABLE pedidos ADD COLUMN status VARCHAR(50) DEFAULT 'novo' AFTER total";
        $pdo->exec($sql);
        echo json_encode(["status" => "success", "message" => "Coluna 'status' adicionada em 'pedidos'."]);
    }

    // 2. Check for 'numero_casa' column in 'pedidos'
    $stmt = $pdo->query("SHOW COLUMNS FROM pedidos LIKE 'numero_casa'");
    $exists = $stmt->fetch();
    if (!$exists) {
        $sql = "ALTER TABLE pedidos ADD COLUMN numero_casa VARCHAR(20) NULL AFTER endereco_completo";
        $pdo->exec($sql);
        echo json_encode(["status" => "success", "message" => "Coluna 'numero_casa' adicionada em 'pedidos'."]);
    }
    
    // 3. Check for 'criado_em' column in 'pedidos'
    $stmt = $pdo->query("SHOW COLUMNS FROM pedidos LIKE 'criado_em'");
    $exists = $stmt->fetch();
    if (!$exists) {
        $sql = "ALTER TABLE pedidos ADD COLUMN criado_em DATETIME DEFAULT CURRENT_TIMESTAMP";
        $pdo->exec($sql);
        echo json_encode(["status" => "success", "message" => "Coluna 'criado_em' adicionada em 'pedidos'."]);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>