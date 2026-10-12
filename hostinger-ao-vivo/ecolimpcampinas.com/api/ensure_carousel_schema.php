<?php
require_once 'db.php';
header("Content-Type: application/json");

try {
    $stmt = $pdo->query("DESCRIBE configuracoes");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $log = [];

    if (!in_array('carousel_images', $columns)) {
        $pdo->exec("ALTER TABLE configuracoes ADD COLUMN carousel_images TEXT");
        $log[] = "Added 'carousel_images' column.";
    } else {
        $log[] = "'carousel_images' column already exists.";
    }

    if (!in_array('carousel_active', $columns)) {
        $pdo->exec("ALTER TABLE configuracoes ADD COLUMN carousel_active TINYINT(1) DEFAULT 1");
        $log[] = "Added 'carousel_active' column.";
    } else {
        $log[] = "'carousel_active' column already exists.";
    }

    echo json_encode(['success' => true, 'log' => $log]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>