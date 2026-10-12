<?php
require_once 'db.php';

try {
    // Add card_span column to produtos table if it doesn't exist
    $sql = "SHOW COLUMNS FROM produtos LIKE 'card_span'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        $sql = "ALTER TABLE produtos ADD COLUMN card_span INT DEFAULT 1";
        $pdo->exec($sql);
        echo json_encode(["message" => "Column card_span added successfully"]);
    } else {
        echo json_encode(["message" => "Column card_span already exists"]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>