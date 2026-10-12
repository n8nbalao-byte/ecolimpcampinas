<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$normalizeDigits = function ($value) {
    return preg_replace('/\D/', '', $value ?? '');
};
$upper = function ($value) {
    if ($value === null) return null;
    $text = trim((string)$value);
    return $text === '' ? null : mb_strtoupper($text, 'UTF-8');
};

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM nfe_clientes WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Cliente não encontrado']);
            exit;
        }
        $row['endereco'] = $row['endereco_json'] ? json_decode($row['endereco_json'], true) : null;
        unset($row['endereco_json']);
        echo json_encode($row);
        exit;
    }
    $stmt = $pdo->query("SELECT * FROM nfe_clientes ORDER BY criado_em DESC");
    $rows = $stmt->fetchAll();
    $rows = array_map(function ($row) {
        $row['endereco'] = $row['endereco_json'] ? json_decode($row['endereco_json'], true) : null;
        unset($row['endereco_json']);
        return $row;
    }, $rows);
    echo json_encode($rows);
    exit;
}

if ($method === 'POST') {
    $razao = $upper($input['razao_social'] ?? '');
    $cnpj = $normalizeDigits($input['cnpj'] ?? '');
    $endereco = $input['endereco'] ?? null;

    if ($razao === '' || $cnpj === '' || !is_array($endereco)) {
        http_response_code(400);
        echo json_encode(['error' => 'Campos obrigatórios ausentes']);
        exit;
    }

    $id = bin2hex(random_bytes(16));
    $enderecoUpper = [
        'cep' => $upper($endereco['cep'] ?? ''),
        'rua' => $upper($endereco['rua'] ?? ''),
        'numero' => $upper($endereco['numero'] ?? ''),
        'complemento' => $upper($endereco['complemento'] ?? ''),
        'bairro' => $upper($endereco['bairro'] ?? ''),
        'cidade' => $upper($endereco['cidade'] ?? ''),
        'uf' => $upper($endereco['uf'] ?? ''),
    ];
    $stmt = $pdo->prepare("INSERT INTO nfe_clientes (id, razao_social, nome_fantasia, cnpj, ie, im, contribuinte_icms, endereco_json, telefone, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $id,
        $razao,
        $upper($input['nome_fantasia'] ?? null),
        $cnpj,
        $upper($input['ie'] ?? null),
        $upper($input['im'] ?? null),
        !empty($input['contribuinte_icms']) ? 1 : 0,
        json_encode($enderecoUpper, JSON_UNESCAPED_UNICODE),
        $upper($input['telefone'] ?? null),
        $upper($input['email'] ?? null)
    ]);
    echo json_encode(['id' => $id]);
    exit;
}

if ($method === 'PUT') {
    $id = $input['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID obrigatório']);
        exit;
    }

    $razao = $upper($input['razao_social'] ?? '');
    $cnpj = $normalizeDigits($input['cnpj'] ?? '');
    $endereco = $input['endereco'] ?? null;
    if ($razao === '' || $cnpj === '' || !is_array($endereco)) {
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

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID obrigatório']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM nfe_clientes WHERE id = ?");
    $stmt->execute([$id]);
    echo json_encode(['id' => $id]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);