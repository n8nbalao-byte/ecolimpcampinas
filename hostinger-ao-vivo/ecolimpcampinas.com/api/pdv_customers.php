<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pdv_customers (
        id VARCHAR(36) PRIMARY KEY,
        nome VARCHAR(255) NOT NULL,
        telefone VARCHAR(50),
        endereco TEXT,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao preparar tabela de clientes']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$upper = function ($value) {
    if ($value === null) return null;
    $text = trim((string)$value);
    return $text === '' ? null : mb_strtoupper($text, 'UTF-8');
};

try {
    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT * FROM pdv_customers ORDER BY criado_em DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }

    if ($method === 'POST') {
        $nome = $upper($input['nome'] ?? '');
        if (!$nome) {
            http_response_code(400);
            echo json_encode(['error' => 'Nome obrigatório']);
            exit;
        }
        $telefone = $upper($input['telefone'] ?? '');
        $endereco = $upper($input['endereco'] ?? '');

        $id = uniqid('pdv_', true);
        $stmt = $pdo->prepare("INSERT INTO pdv_customers (id, nome, telefone, endereco) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $nome, $telefone, $endereco]);

        echo json_encode([
            'id' => $id,
            'nome' => $nome,
            'telefone' => $telefone,
            'endereco' => $endereco,
            'criado_em' => date('Y-m-d H:i:s')
        ]);
        exit;
    }

    if ($method === 'PUT') {
        $id = $input['id'] ?? '';
        $nome = $upper($input['nome'] ?? '');
        $telefone = $upper($input['telefone'] ?? '');
        $endereco = $upper($input['endereco'] ?? '');
        if (!$id || !$nome) {
            http_response_code(400);
            echo json_encode(['error' => 'ID e nome obrigatórios']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE pdv_customers SET nome = ?, telefone = ?, endereco = ? WHERE id = ?");
        $stmt->execute([$nome, $telefone, $endereco, $id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? '';
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID obrigatório']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM pdv_customers WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}