<?php
require_once 'db.php';

try {
    // Add columns if they don't exist
    $columns = [
        'evolution_api_url' => "VARCHAR(255) DEFAULT 'https://api.n8nbalao.com'",
        'evolution_instance' => "VARCHAR(255) DEFAULT ''",
        'evolution_apikey' => "VARCHAR(255) DEFAULT ''"
    ];

    foreach ($columns as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE configuracoes ADD COLUMN $col $def");
            echo "Added column $col.<br>";
        } catch (PDOException $e) {
            // Ignore if column already exists (SQLSTATE 42S21 usually)
            if (strpos($e->getMessage(), 'Duplicate column') !== false || $e->getCode() == '42S21') {
                echo "Column $col already exists.<br>";
            } else {
                echo "Error adding $col: " . $e->getMessage() . "<br>";
            }
        }
    }

    echo "Schema update completed.";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
}