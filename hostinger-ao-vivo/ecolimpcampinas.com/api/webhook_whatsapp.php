<?php
// Webhook para receber eventos da Evolution API (mensagens, status, etc.)
// URL para configurar na Evolution API: https://olhacandida.n8nbalao.com/api/webhook_whatsapp.php

header("Content-Type: application/json");

// Apenas aceitar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Ler o corpo da requisição
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Logar os dados recebidos (para debug)
$logEntry = "[" . date('Y-m-d H:i:s') . "] " . $input . PHP_EOL;
file_put_contents('webhook.log', $logEntry, FILE_APPEND);

// Aqui você pode adicionar lógica para processar mensagens recebidas
// Exemplo:
if (isset($data['type']) && $data['type'] === 'message') {
    // Processar mensagem recebida
    // $sender = $data['data']['key']['remoteJid'];
    // $messageContent = $data['data']['message'] ...
}

// Retornar 200 OK para a API não tentar reenviar
http_response_code(200);
echo json_encode(['status' => 'received']);