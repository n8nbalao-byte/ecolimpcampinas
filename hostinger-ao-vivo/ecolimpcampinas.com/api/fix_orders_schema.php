<?php
require_once 'db.php';
header("Content-Type: application/json");

$log = [];

try {
    // Check columns in 'pedidos' table
    $stmt = $pdo->query("DESCRIBE pedidos");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $log[] = "Existing columns in 'pedidos': " . implode(", ", $columns);

    // Check if 'endereco_completo' exists
    if (!in_array('endereco_completo', $columns)) {
        // If 'endereco' exists, we might want to migrate data
        if (in_array('endereco', $columns)) {
            $pdo->exec("ALTER TABLE pedidos ADD COLUMN endereco_completo TEXT");
            $pdo->exec("UPDATE pedidos SET endereco_completo = endereco");
            $log[] = "Added 'endereco_completo' and migrated data from 'endereco'.";
        } else {
            $pdo->exec("ALTER TABLE pedidos ADD COLUMN endereco_completo TEXT");
            $log[] = "Added 'endereco_completo' column.";
        }
    } else {
        $log[] = "'endereco_completo' already exists.";
    }

    // Check if 'numero_casa' exists
    if (!in_array('numero_casa', $columns)) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN numero_casa VARCHAR(20)");
        $log[] = "Added 'numero_casa' column.";
    } else {
        $log[] = "'numero_casa' already exists.";
    }

    // Check if 'status' exists
    if (!in_array('status', $columns)) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN status VARCHAR(50) DEFAULT 'novo'");
        $log[] = "Added 'status' column.";
    } else {
        $log[] = "'status' already exists.";
    }
    
    // Check if 'criado_em' exists
    if (!in_array('criado_em', $columns)) {
        $pdo->exec("ALTER TABLE pedidos ADD COLUMN criado_em DATETIME DEFAULT CURRENT_TIMESTAMP");
        $log[] = "Added 'criado_em' column.";
    } else {
        $log[] = "'criado_em' already exists.";
    }
    
    echo json_encode(['status' => 'success', 'log' => $log]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage(), 'log' => $log]);
}
?>