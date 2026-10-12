<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$text = $data['text'] ?? '';
$voice = $data['voice'] ?? null;

if (!$text || !is_string($text)) {
    http_response_code(400);
    echo json_encode(['error' => 'Text is required']);
    exit;
}

try {
    $stmt = $pdo->query("SELECT openai_api_key, voz_agente FROM configuracoes WHERE id = 1");
    $config = $stmt->fetch();

    if (!$config) {
        throw new Exception('Configuração não encontrada');
    }

    $apiKey = $config['openai_api_key'] ?? '';
    $voice = $voice ?: ($config['voz_agente'] ?? 'alloy');

    if (empty($apiKey)) {
        throw new Exception('OpenAI API Key não configurada');
    }

    $payload = json_encode([
        'model' => 'gpt-4o-mini-tts',
        'voice' => $voice,
        'input' => $text,
        'format' => 'mp3'
    ]);

    $ch = curl_init('https://api.openai.com/v1/audio/speech');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception('Erro ao gerar áudio');
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Falha ao gerar áudio');
    }

    $base64 = base64_encode($response);
    $dataUrl = 'data:audio/mpeg;base64,' . $base64;

    echo json_encode(['audio' => $dataUrl]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}