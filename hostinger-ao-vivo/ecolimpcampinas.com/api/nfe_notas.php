<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$fetchItems = function ($numero) use ($pdo) {
    $stmt = $pdo->prepare("SELECT * FROM nfe_itens WHERE nfe_numero = ? ORDER BY criado_em ASC");
    $stmt->execute([$numero]);
    return $stmt->fetchAll();
};

if ($method === 'GET') {
    $numero = $_GET['numero'] ?? null;
    if ($numero) {
        $stmt = $pdo->prepare("SELECT n.*, c.razao_social, c.nome_fantasia, c.cnpj, c.endereco_json, c.ie, c.im, c.contribuinte_icms, c.telefone, c.email FROM nfe_notas n JOIN nfe_clientes c ON n.cliente_id = c.id WHERE n.numero = ?");
        $stmt->execute([$numero]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => 'Nota não encontrada']);
            exit;
        }
        $row['cliente'] = [
            'id' => $row['cliente_id'],
            'razao_social' => $row['razao_social'],
            'nome_fantasia' => $row['nome_fantasia'],
            'cnpj' => $row['cnpj'],
            'ie' => $row['ie'],
            'im' => $row['im'],
            'contribuinte_icms' => (bool)$row['contribuinte_icms'],
            'endereco' => $row['endereco_json'] ? json_decode($row['endereco_json'], true) : null,
            'telefone' => $row['telefone'],
            'email' => $row['email']
        ];
        unset($row['razao_social'], $row['nome_fantasia'], $row['cnpj'], $row['endereco_json'], $row['ie'], $row['im'], $row['contribuinte_icms'], $row['telefone'], $row['email']);
        $row['itens'] = $fetchItems($numero);
        echo json_encode($row);
        exit;
    }

    $stmt = $pdo->query("SELECT n.*, c.razao_social, c.cnpj FROM nfe_notas n JOIN nfe_clientes c ON n.cliente_id = c.id ORDER BY n.criado_em DESC");
    $rows = $stmt->fetchAll();
    $rows = array_map(function ($row) use ($fetchItems) {
        $row['cliente'] = [
            'id' => $row['cliente_id'],
            'razao_social' => $row['razao_social'],
            'cnpj' => $row['cnpj']
        ];
        unset($row['razao_social'], $row['cnpj']);
        $row['itens'] = $fetchItems($row['numero']);
        return $row;
    }, $rows);
    echo json_encode($rows);
    exit;
}

if ($method === 'POST') {
    $clienteId = $input['cliente_id'] ?? null;
    $natureza = trim($input['natureza_operacao'] ?? '');
    $tipo = $input['tipo_nota'] ?? null;
    $serie = isset($input['serie']) ? (int)$input['serie'] : 0;
    $items = $input['items'] ?? [];

    if (!$clienteId || $natureza === '' || !$tipo || !is_array($items) || count($items) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Campos obrigatórios ausentes']);
        exit;
    }

    $stmtConfig = $pdo->query("SELECT nfe_serie_padrao FROM configuracoes WHERE id = 1");
    $config = $stmtConfig->fetch();
    if ($serie <= 0) {
        $serie = isset($config['nfe_serie_padrao']) ? (int)$config['nfe_serie_padrao'] : 1;
    }

    $stmtCliente = $pdo->prepare("SELECT * FROM nfe_clientes WHERE id = ?");
    $stmtCliente->execute([$clienteId]);
    $cliente = $stmtCliente->fetch();
    if (!$cliente) {
        http_response_code(404);
        echo json_encode(['error' => 'Cliente não encontrado']);
        exit;
    }

    $subtotal = 0;
    $totalImpostos = 0;
    $preparedItems = [];
    $missingFiscal = [];

    foreach ($items as $item) {
        $produtoId = $item['produto_id'] ?? null;
        $quantidade = isset($item['quantidade']) ? (float)$item['quantidade'] : 0;
        if (!$produtoId || $quantidade <= 0) {
            continue;
        }

        $stmtProd = $pdo->prepare("SELECT * FROM produtos WHERE id = ?");
        $stmtProd->execute([$produtoId]);
        $produto = $stmtProd->fetch();
        if (!$produto) {
            http_response_code(404);
            echo json_encode(['error' => 'Produto não encontrado', 'produto_id' => $produtoId]);
            exit;
        }

        $requiredFields = ['ncm', 'cfop', 'cst_csosn', 'origem', 'unidade_fiscal'];
        foreach ($requiredFields as $field) {
            if (!isset($produto[$field]) || $produto[$field] === '' || $produto[$field] === null) {
                $missingFiscal[] = ['produto_id' => $produtoId, 'field' => $field];
            }
        }

        $valorUnitario = (float)$produto['preco'];
        $totalItem = $valorUnitario * $quantidade;

        $aliqIcms = isset($produto['aliquota_icms']) ? (float)$produto['aliquota_icms'] : 0;
        $aliqIpi = isset($produto['aliquota_ipi']) ? (float)$produto['aliquota_ipi'] : 0;
        $aliqPis = isset($produto['aliquota_pis']) ? (float)$produto['aliquota_pis'] : 0;
        $aliqCofins = isset($produto['aliquota_cofins']) ? (float)$produto['aliquota_cofins'] : 0;

        $icms = $totalItem * ($aliqIcms / 100);
        $ipi = $totalItem * ($aliqIpi / 100);
        $pis = $totalItem * ($aliqPis / 100);
        $cofins = $totalItem * ($aliqCofins / 100);

        $subtotal += $totalItem;
        $totalImpostos += ($icms + $ipi + $pis + $cofins);

        $preparedItems[] = [
            'produto_id' => $produtoId,
            'descricao' => $produto['nome'],
            'quantidade' => $quantidade,
            'valor_unitario' => $valorUnitario,
            'total' => $totalItem,
            'icms' => $icms,
            'ipi' => $ipi,
            'pis' => $pis,
            'cofins' => $cofins,
            'ncm' => $produto['ncm'] ?? null,
            'cfop' => $produto['cfop'] ?? null,
            'cest' => $produto['cest'] ?? null,
            'origem' => $produto['origem'] ?? null,
            'cst_csosn' => $produto['cst_csosn'] ?? null,
            'unidade_fiscal' => $produto['unidade_fiscal'] ?? null,
            'gtin' => $produto['gtin'] ?? $produto['codigo_barras'] ?? null
        ];
    }

    if (count($missingFiscal) > 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados fiscais ausentes nos produtos', 'missing' => $missingFiscal]);
        exit;
    }

    $id = bin2hex(random_bytes(16));
    $total = $subtotal + $totalImpostos;
    $status = 'emitida';

    $stmtNota = $pdo->prepare("INSERT INTO nfe_notas (id, serie, cliente_id, natureza_operacao, tipo_nota, subtotal, total_impostos, total, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtNota->execute([$id, $serie, $clienteId, $natureza, $tipo, $subtotal, $totalImpostos, $total, $status]);
    $numero = (int)$pdo->lastInsertId();

    foreach ($preparedItems as $item) {
        $itemId = bin2hex(random_bytes(16));
        $stmtItem = $pdo->prepare("INSERT INTO nfe_itens (id, nfe_numero, produto_id, descricao, quantidade, valor_unitario, total, icms, ipi, pis, cofins, ncm, cfop, cest, origem, cst_csosn, unidade_fiscal, gtin) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtItem->execute([
            $itemId,
            $numero,
            $item['produto_id'],
            $item['descricao'],
            $item['quantidade'],
            $item['valor_unitario'],
            $item['total'],
            $item['icms'],
            $item['ipi'],
            $item['pis'],
            $item['cofins'],
            $item['ncm'],
            $item['cfop'],
            $item['cest'],
            $item['origem'],
            $item['cst_csosn'],
            $item['unidade_fiscal'],
            $item['gtin']
        ]);
    }

    $xmlParts = [];
    $xmlParts[] = '<?xml version="1.0" encoding="UTF-8"?>';
    $xmlParts[] = '<NFe>';
    $xmlParts[] = '<infNFe>';
    $xmlParts[] = '<ide><nNF>' . $numero . '</nNF><serie>' . $serie . '</serie><tpNF>' . $tipo . '</tpNF><natOp>' . htmlspecialchars($natureza) . '</natOp></ide>';
    $xmlParts[] = '<dest><xNome>' . htmlspecialchars($cliente['razao_social']) . '</xNome><CNPJ>' . $cliente['cnpj'] . '</CNPJ></dest>';
    foreach ($preparedItems as $idx => $item) {
        $n = $idx + 1;
        $xmlParts[] = '<det nItem="' . $n . '"><prod><cProd>' . $item['produto_id'] . '</cProd><xProd>' . htmlspecialchars($item['descricao']) . '</xProd><NCM>' . $item['ncm'] . '</NCM><CFOP>' . $item['cfop'] . '</CFOP><uCom>' . $item['unidade_fiscal'] . '</uCom><qCom>' . $item['quantidade'] . '</qCom><vUnCom>' . $item['valor_unitario'] . '</vUnCom><vProd>' . $item['total'] . '</vProd></prod></det>';
    }
    $xmlParts[] = '</infNFe>';
    $xmlParts[] = '</NFe>';
    $xml = implode('', $xmlParts);

    $stmtXml = $pdo->prepare("UPDATE nfe_notas SET xml = ? WHERE numero = ?");
    $stmtXml->execute([$xml, $numero]);

    echo json_encode([
        'id' => $id,
        'numero' => $numero,
        'serie' => $serie,
        'status' => $status,
        'xml' => $xml
    ]);
    exit;
}

if ($method === 'PUT') {
    $numero = $input['numero'] ?? null;
    $status = $input['status'] ?? null;
    if (!$numero || !$status) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados obrigatórios ausentes']);
        exit;
    }
    $allowed = ['emitida', 'cancelada', 'inutilizada'];
    if (!in_array($status, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Status inválido']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE nfe_notas SET status = ? WHERE numero = ?");
    $stmt->execute([$status, $numero]);
    echo json_encode(['numero' => $numero, 'status' => $status]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método não permitido']);