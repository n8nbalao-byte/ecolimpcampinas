<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once 'db.php';

header("Content-Type: text/plain; charset=utf-8");

function columnExists($pdo, $table, $column) {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->fetch() !== false;
    } catch (PDOException $e) {
        return false;
    }
}

echo "=== ATUALIZAÇÃO DE SCHEMA DO BANCO DE DADOS ===\n\n";

try {
    // Lista de colunas para adicionar na tabela 'configuracoes'
    $columns = [
        'openai_model' => "VARCHAR(100) DEFAULT 'gpt-4o-realtime-preview-2024-10-01'",
        'gemini_api_key' => "VARCHAR(255) DEFAULT ''",
        'gemini_model' => "VARCHAR(100) DEFAULT 'gemini-1.5-flash'",
        'groq_api_key' => "VARCHAR(255) DEFAULT ''",
        'groq_model' => "VARCHAR(100) DEFAULT 'llama3-8b-8192'",
        'active_provider' => "VARCHAR(50) DEFAULT 'openai'",
        // New columns for Product AI (Cadastramento com IA)
        'product_ai_provider' => "VARCHAR(50) DEFAULT 'openai'",
        'product_ai_model' => "VARCHAR(100) DEFAULT 'gpt-4o-mini'",
        'product_ai_api_key' => "VARCHAR(255) DEFAULT ''"
    ];

    $table = 'configuracoes';
    $updated = false;

    // Verificar se a tabela existe
    try {
        $pdo->query("SELECT 1 FROM $table LIMIT 1");
    } catch (PDOException $e) {
        echo "❌ Tabela '$table' não encontrada. Criando tabela básica...\n";
        // Criar tabela se não existir (schema básico)
        $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome_empresa VARCHAR(255),
            whatsapp_loja VARCHAR(50),
            agente_ativo TINYINT(1) DEFAULT 1
        )");
        echo "✅ Tabela '$table' criada.\n";
    }

    foreach ($columns as $colName => $colDef) {
        if (!columnExists($pdo, $table, $colName)) {
            echo "➕ Adicionando coluna '$colName'...\n";
            try {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$colName` $colDef");
                echo "   ✅ Sucesso!\n";
                $updated = true;
            } catch (PDOException $e) {
                echo "   ❌ Erro: " . $e->getMessage() . "\n";
            }
        } else {
            echo "ℹ️ Coluna '$colName' já existe.\n";
        }
    }

    if ($updated) {
        echo "\n🎉 Atualização concluída com sucesso!\n";
    } else {
        echo "\n👍 Nenhuma atualização necessária. O banco já está atualizado.\n";
    }

} catch (PDOException $e) {
    echo "\n❌ ERRO CRÍTICO: " . $e->getMessage() . "\n";
}
?>