<?php
require_once 'db.php';

header("Content-Type: text/plain");

echo "Diagnosticando Agente de Voz...\n";

// 1. Check Config
try {
    $stmt = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        echo "[ERRO] Configurações não encontradas no banco de dados.\n";
        exit;
    }
    
    echo "[OK] Configurações carregadas.\n";
    
    $apiKey = $config['openai_api_key'] ?? '';
    
    if (empty($apiKey)) {
        echo "[ERRO] OpenAI API Key NÃO configurada no banco de dados.\n";
    } else {
        echo "[OK] OpenAI API Key presente (tamanho: " . strlen($apiKey) . " chars).\n";
        if (substr($apiKey, 0, 3) !== 'sk-') {
             echo "[AVISO] API Key não começa com 'sk-'. Pode estar incorreta.\n";
        }
    }
    
    // 2. Check Products
    $stmtP = $pdo->query("SELECT COUNT(*) FROM produtos WHERE ativo = 1");
    $count = $stmtP->fetchColumn();
    echo "[OK] Produtos ativos encontrados: $count\n";
    
    // 3. Test OpenAI Connectivity (List Models)
    if (!empty($apiKey)) {
        echo "Testando conexão com OpenAI...\n";
        
        $ch = curl_init('https://api.openai.com/v1/models');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($err) {
            echo "[ERRO] Falha na conexão cURL: $err\n";
        } else {
            if ($httpCode === 200) {
                echo "[OK] Conexão com OpenAI bem sucedida (HTTP 200).\n";
            } else {
                echo "[ERRO] OpenAI retornou erro HTTP $httpCode.\n";
                $json = json_decode($response, true);
                if (isset($json['error']['message'])) {
                    echo "Mensagem: " . $json['error']['message'] . "\n";
                }
            }
        }
    }

} catch (Exception $e) {
    echo "[ERRO] Exceção: " . $e->getMessage() . "\n";
}
?>