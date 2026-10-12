<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$message = $data['message'] ?? '';
$history = $data['history'] ?? [];

if (!$message || !is_string($message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Mensagem obrigatória']);
    exit;
}

try {
    $stmt = $pdo->query("SELECT openai_api_key, product_ai_api_key, product_ai_model FROM configuracoes WHERE id = 1");
    $config = $stmt->fetch();
    if (!$config) {
        throw new Exception('Configuração não encontrada');
    }

    $apiKey = $config['openai_api_key'] ?: ($config['product_ai_api_key'] ?? '');
    if (empty($apiKey)) {
        throw new Exception('OpenAI API Key não configurada');
    }

    $knowledgePath = __DIR__ . '/../admin_help_knowledge.txt';
    $knowledge = file_exists($knowledgePath) ? file_get_contents($knowledgePath) : '';

    $systemPrompt = "Você é um agente de ajuda para o administrador da loja. " .
        "Use o conhecimento abaixo para responder com orientações claras, passo a passo, e linguagem simples. " .
        "Se não houver informação suficiente, diga isso e indique onde o admin pode ajustar no painel.\n\n" .
        "CONHECIMENTO:\n" . $knowledge;

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
    ];

    if (is_array($history)) {
        foreach ($history as $item) {
            $role = $item['role'] ?? '';
            $content = $item['content'] ?? '';
            if (in_array($role, ['user', 'assistant'], true) && is_string($content) && $content !== '') {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }
    }

    $messages[] = ['role' => 'user', 'content' => $message];

    $requestedModel = $config['product_ai_model'] ?: '';
    $allowedModels = ['gpt-3.5-turbo', 'gpt-4o-mini'];
    $model = in_array($requestedModel, $allowedModels, true) ? $requestedModel : 'gpt-4o-mini';

    $payload = json_encode([
        'model' => $model,
        'messages' => $messages,
        'temperature' => 0.2
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception('Erro ao chamar OpenAI');
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Falha ao gerar resposta');
    }

    $result = json_decode($response, true);
    $reply = $result['choices'][0]['message']['content'] ?? '';
    echo json_encode(['reply' => $reply]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}