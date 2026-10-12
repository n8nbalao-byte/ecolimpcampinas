<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';

try {
    $stmt = $pdo->query("DESCRIBE admin_users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns: ";
    $cols = [];
    foreach($columns as $col) {
        $cols[] = $col['Field'] . " (" . $col['Type'] . ")";
    }
    echo implode(", ", $cols);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>