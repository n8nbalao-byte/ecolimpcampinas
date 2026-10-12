<?php
require_once 'db.php';
header("Content-Type: application/json; charset=UTF-8");

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $productId = $_GET['product_id'] ?? '';
    if (!$productId) {
        http_response_code(400);
        echo json_encode(['error' => 'product_id obrigatório']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id, product_id, nome, rating, comentario, created_at FROM product_reviews WHERE product_id = ? ORDER BY created_at DESC");
    $stmt->execute([$productId]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    $productId = $data['product_id'] ?? '';
    $nome = $data['nome'] ?? '';
    $rating = (int)($data['rating'] ?? 0);
    $comentario = $data['comentario'] ?? null;

    if (!$productId || !$nome || $rating < 1 || $rating > 5) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados inválidos']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO product_reviews (product_id, nome, rating, comentario) VALUES (?, ?, ?, ?)");
    $stmt->execute([$productId, $nome, $rating, $comentario]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);