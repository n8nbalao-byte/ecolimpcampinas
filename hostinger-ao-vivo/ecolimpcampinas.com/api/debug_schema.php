<?php
require_once 'db.php';

header("Content-Type: application/json");

try {
    // Check table columns
    $stmt = $pdo->query("DESCRIBE produtos");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        "columns" => $columns,
        "has_card_span" => in_array('card_span', $columns)
    ]);

} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>