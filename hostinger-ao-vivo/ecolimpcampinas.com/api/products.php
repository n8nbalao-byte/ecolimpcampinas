<?php
require_once 'db.php';

// Auto-migration for card_span
try {
    $stmt = $pdo->query("DESCRIBE produtos");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('card_span', $columns)) {
        $pdo->exec("ALTER TABLE produtos ADD COLUMN card_span INT DEFAULT 1");
    }
} catch (Exception $e) {
    // Silent fail or log if needed, but don't break the API
    error_log("Migration error: " . $e->getMessage());
}

header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $activeOnly = isset($_GET['activeOnly']) ? $_GET['activeOnly'] : 'true';
        $category = isset($_GET['category']) ? $_GET['category'] : null;
        $search = isset($_GET['search']) ? $_GET['search'] : null;
        
        $query = "SELECT * FROM produtos";
        $params = [];
        $conditions = [];

        if ($activeOnly !== 'false') {
            $conditions[] = "ativo = 1";
        }

        if ($category) {
            $conditions[] = "categoria = ?";
            $params[] = $category;
        }

        if ($search) {
            $conditions[] = "(nome LIKE ? OR codigo_barras LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if (count($conditions) > 0) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        $orderBy = isset($_GET['orderBy']) ? $_GET['orderBy'] : 'nome';
        $orderDir = isset($_GET['orderDir']) && strtoupper($_GET['orderDir']) === 'DESC' ? 'DESC' : 'ASC';
        
        $allowedSorts = ['nome', 'preco', 'id', 'categoria', 'ativo'];
        if (!in_array($orderBy, $allowedSorts)) $orderBy = 'nome';

        $query .= " ORDER BY $orderBy $orderDir";

        // Pagination - Use direct values to avoid PDO binding issues with LIMIT/OFFSET
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : null;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        if ($limit !== null) {
            $query .= " LIMIT $limit OFFSET $offset";
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        $comboIds = [];
        foreach ($products as $product) {
            if (!empty($product['combo']) && !empty($product['combo_produtos_json'])) {
                $decoded = json_decode($product['combo_produtos_json'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $pid) {
                        if ($pid) $comboIds[$pid] = true;
                    }
                }
            }
        }

        $comboItemsMap = [];
        if (!empty($comboIds)) {
            $comboIdList = array_keys($comboIds);
            $placeholders = implode(',', array_fill(0, count($comboIdList), '?'));
            $stmtCombo = $pdo->prepare("SELECT id, preco FROM produtos WHERE id IN ($placeholders)");
            $stmtCombo->execute($comboIdList);
            $comboItems = $stmtCombo->fetchAll();
            foreach ($comboItems as $comboItem) {
                $comboItemsMap[$comboItem['id']] = (float)$comboItem['preco'];
            }
        }

        foreach ($products as &$product) {
            $product['preco'] = (float)$product['preco'];
            $product['ativo'] = (bool)$product['ativo'];
            $product['combo'] = !empty($product['combo']) ? 1 : 0;
            $product['combo_desconto'] = $product['combo_desconto'] !== null ? (float)$product['combo_desconto'] : null;
            $product['combo_destaque'] = $product['combo_destaque'] !== null ? (int)$product['combo_destaque'] : null;
            $product['combo_embalagem'] = $product['combo_embalagem'] ?? null;
            $product['desconto_qtd_ativo'] = !empty($product['desconto_qtd_ativo']) ? 1 : 0;
            $product['desconto_qtd_tiers'] = $product['desconto_qtd_tiers'] ? json_decode($product['desconto_qtd_tiers'], true) : [];
            $product['combo_produtos'] = $product['combo_produtos_json'] ? json_decode($product['combo_produtos_json'], true) : [];
            $product['card_span'] = isset($product['card_span']) ? (int)$product['card_span'] : 1;
            unset($product['combo_produtos_json']);

            if (!empty($product['combo']) && !empty($product['combo_produtos'])) {
                $sum = 0.0;
                foreach ($product['combo_produtos'] as $pid) {
                    if (isset($comboItemsMap[$pid])) {
                        $sum += $comboItemsMap[$pid];
                    }
                }
                $discount = $product['combo_desconto'] !== null ? (float)$product['combo_desconto'] : 7.0;
                $finalPrice = $sum * (1 - ($discount / 100));
                $product['preco'] = round($finalPrice, 2);
                $product['categoria'] = 'combo';
            }
        }

        echo json_encode($products);
    }
    elseif ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['nome']) || !isset($data['preco'])) {
            throw new Exception("Nome and Preço are required");
        }
        
        $id = uniqid('prod_', true);
        
        // Prepare fields
        $fields = ['id', 'nome', 'codigo_barras', 'descricao', 'descricao_detalhada', 'preco', 'unidade', 'imagem_url', 'video_url', 'especificacoes', 'instrucoes', 'categoria', 'ativo', 'ncm', 'cfop', 'cest', 'origem', 'aliquota_icms', 'aliquota_ipi', 'aliquota_pis', 'aliquota_cofins', 'cst_csosn', 'unidade_fiscal', 'gtin', 'combo', 'combo_produtos_json', 'combo_desconto', 'combo_destaque', 'combo_embalagem', 'desconto_qtd_ativo', 'desconto_qtd_tiers', 'card_span'];
        $comboProducts = $data['combo_produtos'] ?? [];
        $comboDesconto = $data['combo_desconto'] ?? null;
        $comboAtivo = !empty($data['combo']);

        if ($comboAtivo && is_array($comboProducts) && count($comboProducts) > 0) {
            $placeholdersCombo = implode(',', array_fill(0, count($comboProducts), '?'));
            $stmtCombo = $pdo->prepare("SELECT preco FROM produtos WHERE id IN ($placeholdersCombo)");
            $stmtCombo->execute($comboProducts);
            $sum = 0.0;
            foreach ($stmtCombo->fetchAll() as $row) {
                $sum += (float)$row['preco'];
            }
            $discount = $comboDesconto !== null ? (float)$comboDesconto : 7.0;
            $data['preco'] = round($sum * (1 - ($discount / 100)), 2);
            $data['categoria'] = 'combo';
        }

        $values = [
            $id, 
            $data['nome'], 
            $data['codigo_barras'] ?? '',
            $data['descricao'] ?? '', 
            $data['descricao_detalhada'] ?? '',
            $data['preco'], 
            $data['unidade'] ?? 'un', 
            $data['imagem_url'] ?? '', 
            $data['video_url'] ?? '',
            $data['especificacoes'] ?? '',
            $data['instrucoes'] ?? '',
            $data['categoria'] ?? 'outros', 
            isset($data['ativo']) && $data['ativo'] ? 1 : 0,
            $data['ncm'] ?? null,
            $data['cfop'] ?? null,
            $data['cest'] ?? null,
            isset($data['origem']) ? $data['origem'] : null,
            isset($data['aliquota_icms']) ? $data['aliquota_icms'] : null,
            isset($data['aliquota_ipi']) ? $data['aliquota_ipi'] : null,
            isset($data['aliquota_pis']) ? $data['aliquota_pis'] : null,
            isset($data['aliquota_cofins']) ? $data['aliquota_cofins'] : null,
            $data['cst_csosn'] ?? null,
            $data['unidade_fiscal'] ?? null,
            $data['gtin'] ?? null,
            $comboAtivo ? 1 : 0,
            $comboProducts ? json_encode($comboProducts, JSON_UNESCAPED_UNICODE) : null,
            $comboDesconto !== null ? $comboDesconto : null,
            $data['combo_destaque'] ?? null,
            $data['combo_embalagem'] ?? null,
            !empty($data['desconto_qtd_ativo']) ? 1 : 0,
            isset($data['desconto_qtd_tiers']) ? json_encode($data['desconto_qtd_tiers'], JSON_UNESCAPED_UNICODE) : null,
            $data['card_span'] ?? 1
        ];
        $placeholders = str_repeat('?,', count($fields) - 1) . '?';
        
        $sql = "INSERT INTO produtos (" . implode(',', $fields) . ") VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        
        echo json_encode(["id" => $id, "message" => "Product created successfully"]);
    }
    elseif ($method === 'PUT') {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['id'])) {
            throw new Exception("Product ID is required for update");
        }
        
        $id = $data['id'];
        $updateFields = [];
        $params = [];
        
        $allowedFields = ['nome', 'codigo_barras', 'descricao', 'descricao_detalhada', 'preco', 'unidade', 'imagem_url', 'video_url', 'especificacoes', 'instrucoes', 'categoria', 'ativo', 'ncm', 'cfop', 'cest', 'origem', 'aliquota_icms', 'aliquota_ipi', 'aliquota_pis', 'aliquota_cofins', 'cst_csosn', 'unidade_fiscal', 'gtin', 'combo', 'combo_produtos_json', 'combo_desconto', 'combo_destaque', 'combo_embalagem', 'desconto_qtd_ativo', 'desconto_qtd_tiers', 'card_span'];

        if (array_key_exists('combo_produtos', $data)) {
            $data['combo_produtos_json'] = $data['combo_produtos'] ? json_encode($data['combo_produtos'], JSON_UNESCAPED_UNICODE) : null;
        }
        if (array_key_exists('desconto_qtd_tiers', $data)) {
            $data['desconto_qtd_tiers'] = $data['desconto_qtd_tiers'] ? json_encode($data['desconto_qtd_tiers'], JSON_UNESCAPED_UNICODE) : null;
        }

        if (array_key_exists('combo', $data)) {
            $data['combo'] = !empty($data['combo']) ? 1 : 0;
        }

        if (!empty($data['combo']) && !empty($data['combo_produtos'])) {
            $comboProducts = $data['combo_produtos'];
            $placeholdersCombo = implode(',', array_fill(0, count($comboProducts), '?'));
            $stmtCombo = $pdo->prepare("SELECT preco FROM produtos WHERE id IN ($placeholdersCombo)");
            $stmtCombo->execute($comboProducts);
            $sum = 0.0;
            foreach ($stmtCombo->fetchAll() as $row) {
                $sum += (float)$row['preco'];
            }
            $discount = isset($data['combo_desconto']) ? (float)$data['combo_desconto'] : 7.0;
            $data['preco'] = round($sum * (1 - ($discount / 100)), 2);
            $data['categoria'] = 'combo';
        }
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateFields[] = "$field = ?";
                if ($field === 'ativo') {
                    $params[] = $data[$field] ? 1 : 0;
                } elseif ($field === 'combo' || $field === 'desconto_qtd_ativo') {
                    $params[] = !empty($data[$field]) ? 1 : 0;
                } elseif ($field === 'origem') {
                    $params[] = $data[$field] !== '' ? $data[$field] : null;
                } elseif (in_array($field, ['aliquota_icms', 'aliquota_ipi', 'aliquota_pis', 'aliquota_cofins'], true)) {
                    $params[] = $data[$field] !== '' ? $data[$field] : null;
                } else {
                    $params[] = $data[$field];
                }
            }
        }
        
        if (empty($updateFields)) {
            echo json_encode(["message" => "No changes provided"]);
            exit;
        }
        
        $params[] = $id;
        $sql = "UPDATE produtos SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode(["message" => "Product updated successfully"]);
    }
    elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            throw new Exception("Product ID is required");
        }
        
        $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(["message" => "Product deleted successfully"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>