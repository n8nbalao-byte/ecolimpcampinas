<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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

$normalizeDigits = function ($value) {
    return preg_replace('/\D/', '', $value ?? '');
};

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pdv_customers (
        id VARCHAR(36) PRIMARY KEY,
        nome VARCHAR(255) NOT NULL,
        telefone VARCHAR(50),
        endereco TEXT,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS nfe_clientes (
        id VARCHAR(36) PRIMARY KEY,
        razao_social VARCHAR(255) NOT NULL,
        nome_fantasia VARCHAR(255),
        cnpj VARCHAR(20) NOT NULL,
        ie VARCHAR(30),
        im VARCHAR(30),
        contribuinte_icms TINYINT(1) DEFAULT 0,
        endereco_json TEXT,
        telefone VARCHAR(50),
        email VARCHAR(100),
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao preparar tabelas']);
    exit;
}

if ($method === 'GET') {
    $stmtPdv = $pdo->query("SELECT id, nome, telefone, endereco, criado_em FROM pdv_customers");
    $pdvRows = $stmtPdv->fetchAll(PDO::FETCH_ASSOC);

    $stmtNfe = $pdo->query("SELECT id, razao_social, nome_fantasia, cnpj, ie, im, contribuinte_icms, endereco_json, telefone, email, criado_em FROM nfe_clientes");
    $nfeRows = $stmtNfe->fetchAll(PDO::FETCH_ASSOC);

    $customers = [];

    foreach ($nfeRows as $row) {
        $endereco = $row['endereco_json'] ? json_decode($row['endereco_json'], true) : null;
        $customers[] = [
            'id' => $row['id'],
            'source' => 'nfe',
            'nome' => $row['razao_social'],
            'nome_fantasia' => $row['nome_fantasia'],
            'cnpj' => $row['cnpj'],
            'ie' => $row['ie'],
            'im' => $row['im'],
            'contribuinte_icms' => (bool)$row['contribuinte_icms'],
            'telefone' => $row['telefone'],
            'email' => $row['email'],
            'endereco' => $endereco,
            'endereco_text' => null,
            'criado_em' => $row['criado_em'],
        ];
    }

    foreach ($pdvRows as $row) {
        $customers[] = [
            'id' => $row['id'],
            'source' => 'pdv',
            'nome' => $row['nome'],
            'telefone' => $row['telefone'],
            'email' => null,
            'endereco' => null,
            'endereco_text' => $row['endereco'],
            'cnpj' => null,
            'ie' => null,
            'im' => null,
            'contribuinte_icms' => null,
            'criado_em' => $row['criado_em'],
        ];
    }

    usort($customers, function ($a, $b) {
        return strcmp($b['criado_em'], $a['criado_em']);
    });

    echo json_encode($customers);
    exit;
}

if ($method === 'PUT') {
    $source = $input['source'] ?? null;
    $id = $input['id'] ?? null;
    if (!$source || !$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID e origem obrigatórios']);
        exit;
    }

    if ($source === 'pdv') {
        $nome = $upper($input['nome'] ?? '');
        if (!$nome) {
            http_response_code(400);
            echo json_encode(['error' => 'Nome obrigatório']);
            exit;
        }
        $telefone = $upper($input['telefone'] ?? '');
        $endereco = $upper($input['endereco_text'] ?? '');
        $stmt = $pdo->prepare("UPDATE pdv_customers SET nome = ?, telefone = ?, endereco = ? WHERE id = ?");
        $stmt->execute([$nome, $telefone, $endereco, $id]);
        echo json_encode(['id' => $id]);
        exit;
    }

    if ($source === 'nfe') {
        $razao = $upper($input['nome'] ?? '');
        $cnpj = $normalizeDigits($input['cnpj'] ?? '');
        $endereco = $input['endereco'] ?? null;
        if (!$razao || !$cnpj || !is_array($endereco)) {
            http_response_code(400);
            echo json_encode(['error' => 'Campos obrigatórios ausentes']);
            exit;
        }
        $enderecoUpper = [
            'cep' => $upper($endereco['cep'] ?? ''),
            'rua' => $upper($endereco['rua'] ?? ''),
            'numero' => $upper($endereco['numero'] ?? ''),
            'complemento' => $upper($endereco['complemento'] ?? ''),
            'bairro' => $upper($endereco['bairro'] ?? ''),
            'cidade' => $upper($endereco['cidade'] ?? ''),
            'uf' => $upper($endereco['uf'] ?? ''),
        ];
        $stmt = $pdo->prepare("UPDATE nfe_clientes SET razao_social=?, nome_fantasia=?, cnpj=?, ie=?, im=?, contribuinte_icms=?, endereco_json=?, telefone=?, email=? WHERE id=?");
        $stmt->execute([
            $razao,
            $upper($input['nome_fantasia'] ?? null),
            $cnpj,
            $upper($input['ie'] ?? null),
            $upper($input['im'] ?? null),
            !empty($input['contribuinte_icms']) ? 1 : 0,
            json_encode($enderecoUpper, JSON_UNESCAPED_UNICODE),
            $upper($input['telefone'] ?? null),
            $upper($input['email'] ?? null),
            $id
        ]);
        echo json_encode(['id' => $id]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Origem inválida']);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    $source = $_GET['source'] ?? null;
    if (!$id || !$source) {
        http_response_code(400);
        echo json_encode(['error' => 'ID e origem obrigatórios']);
        exit;
    }
    if ($source === 'pdv') {
        $stmt = $pdo->prepare("DELETE FROM pdv_customers WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['id' => $id]);
        exit;
    }
    if ($source === 'nfe') {
        $stmt = $pdo->prepare("DELETE FROM nfe_clientes WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['id' => $id]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'Origem inválida']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);