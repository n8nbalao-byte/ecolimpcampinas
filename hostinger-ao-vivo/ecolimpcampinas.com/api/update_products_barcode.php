<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

try {
    // Add codigo_barras column to produtos table if it doesn't exist
    $stmt = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'codigo_barras'");
    $columnExists = $stmt->fetch();

    if (!$columnExists) {
        $pdo->exec("ALTER TABLE produtos ADD COLUMN codigo_barras VARCHAR(50) DEFAULT NULL AFTER nome");
        echo json_encode(["message" => "Column 'codigo_barras' added to 'produtos' table successfully."]);
    } else {
        echo json_encode(["message" => "Column 'codigo_barras' already exists in 'produtos' table."]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>