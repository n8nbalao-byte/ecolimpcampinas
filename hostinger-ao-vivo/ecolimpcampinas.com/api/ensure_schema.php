<?php
require_once 'db.php';

header("Content-Type: application/json");

try {
    // 1. Check if column exists
    $stmt = $pdo->query("DESCRIBE produtos");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $hasCardSpan = in_array('card_span', $columns);
    $messages = [];
    
    if (!$hasCardSpan) {
        // 2. Add column if missing
        $pdo->exec("ALTER TABLE produtos ADD COLUMN card_span INT DEFAULT 1");
        $messages[] = "Column 'card_span' added successfully.";
    } else {
        $messages[] = "Column 'card_span' already exists.";
    }

    // 3. Verify again
    $stmt = $pdo->query("DESCRIBE produtos");
    $columnsAfter = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        "success" => true,
        "messages" => $messages,
        "columns" => $columnsAfter,
        "has_card_span" => in_array('card_span', $columnsAfter)
    ]);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "error" => $e->getMessage()
    ]);
}
?>