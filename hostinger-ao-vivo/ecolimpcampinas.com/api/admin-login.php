<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$username = $data['username'] ?? '';
$password = $data['password'] ?? '';

if (!$username || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password are required']);
    exit;
}

try {
    // Check if admin table exists, if not create it (for safety)
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id VARCHAR(36) PRIMARY KEY,
        username VARCHAR(255) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        nome VARCHAR(255),
        ativo BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Check if any admin exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM admin_users");
    if ($stmt->fetchColumn() == 0) {
        // Create default admin
        $defaultId = 'admin-default-id';
        $defaultPass = 'Aa366560402@';
        $defaultHash = password_hash($defaultPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO admin_users (id, username, password_hash, nome, ativo) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute([$defaultId, 'admin', $defaultHash, 'Administrador']);
    }

    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND ativo = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $isValid = false;
    if ($user) {
        $hash = $user['password_hash'] ?? '';
        if ($hash && password_verify($password, $hash)) {
            $isValid = true;
        } elseif ($hash === $password) {
            $isValid = true;
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$newHash, $user['id']]);
        }
    }

    if ($user && $isValid) {
        // Success
        echo json_encode([
            'id' => $user['id'],
            'username' => $user['username'],
            'nome' => $user['nome']
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Credenciais inválidas']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>