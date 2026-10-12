<?php
header('Content-Type: text/plain');
require_once 'config.php';

echo "--- Debugging Carousel Data ---\n";

try {
    $stmt = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        echo "No configuration found for id = 1\n";
    } else {
        echo "Raw carousel_active: " . var_export($config['carousel_active'], true) . "\n";
        echo "Raw carousel_images: " . var_export($config['carousel_images'], true) . "\n";
        
        echo "\n--- Decoding JSON ---\n";
        $decoded = json_decode($config['carousel_images'], true);
        echo "Decoded carousel_images: " . var_export($decoded, true) . "\n";
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "JSON Decode Error: " . json_last_error_msg() . "\n";
        }
    }
    
    echo "\n--- Table Structure ---\n";
    $stmt = $pdo->query("DESCRIBE configuracoes");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . " (" . $col['Type'] . ")\n";
    }

} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
}
?>