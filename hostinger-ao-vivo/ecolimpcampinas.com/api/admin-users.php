<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';

// Headers are already set in db.php, but we can ensure they are set if db.php didn't
if (!headers_sent()) {
    header("Content-Type: application/json; charset=UTF-8");
}

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // db.php handles this usually, but just in case
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Ensure table exists (wrap in try-catch and log but continue if possible, or just skip if we know it exists)
// debug_admin.php showed table exists.
try {
    // Only try to create if we really need to, to avoid permission/syntax errors on existing tables
    // But IF NOT EXISTS should be safe. 
    // Maybe the issue was somewhere else.
    // Let's just check count to see if we can access it.
    $stmt = $pdo->query("SELECT 1 FROM admin_users LIMIT 1");
} catch (PDOException $e) {
    // Table might not exist
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
            id VARCHAR(36) PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            nome VARCHAR(255),
            ativo TINYINT(1) DEFAULT 1,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        
        // Seed if empty
        $stmt = $pdo->query("SELECT COUNT(*) FROM admin_users");
        if ($stmt->fetchColumn() == 0) {
            $id = uniqid('admin_', true);
            $passHash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO admin_users (id, username, password_hash, nome, ativo) VALUES (?, ?, ?, ?, 1)");
            $stmt->execute([$id, 'admin', $passHash, 'Administrador']);
        }
    } catch (Exception $ex) {
         http_response_code(500);
         echo json_encode(["error" => "Table setup failed: " . $ex->getMessage()]);
         exit;
    }
}

// Helper to get input data
function getJsonInput() {
    return json_decode(file_get_contents("php://input"), true);
}

try {
    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT id, username, nome, ativo, criado_em as created_at FROM admin_users ORDER BY criado_em DESC");
        $users = $stmt->fetchAll();
        
        // Convert tinyint to boolean for frontend if needed
        foreach ($users as &$user) {
            $user['ativo'] = (bool)$user['ativo'];
        }
        
        echo json_encode($users);
    }
    elseif ($method === 'POST') {
        $data = getJsonInput();
        
        if (!isset($data['username']) || !isset($data['password'])) {
            throw new Exception("Username and password are required");
        }
        
        // Check if username exists
        $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ?");
        $stmt->execute([$data['username']]);
        if ($stmt->fetch()) {
            http_response_code(400);
            echo json_encode(["error" => "Username already exists"]);
            exit;
        }
        
        $id = uniqid('user_', true);
        $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $nome = $data['nome'] ?? $data['username'];
        $ativo = isset($data['ativo']) ? ($data['ativo'] ? 1 : 0) : 1;
        
        $stmt = $pdo->prepare("INSERT INTO admin_users (id, username, password_hash, nome, ativo) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$id, $data['username'], $password_hash, $nome, $ativo]);
        
        echo json_encode(["id" => $id, "message" => "User created successfully"]);
    }
    elseif ($method === 'PUT') {
        $data = getJsonInput();
        $id = isset($_GET['id']) ? $_GET['id'] : (isset($data['id']) ? $data['id'] : null);
        
        if (!$id) {
            throw new Exception("User ID is required");
        }
        
        $fields = [];
        $values = [];
        
        if (isset($data['password']) && !empty($data['password'])) {
            $fields[] = "password_hash = ?";
            $values[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        if (isset($data['nome'])) {
            $fields[] = "nome = ?";
            $values[] = $data['nome'];
        }
        
        if (isset($data['ativo'])) {
            $fields[] = "ativo = ?";
            $values[] = $data['ativo'] ? 1 : 0;
        }
        
        if (empty($fields)) {
            echo json_encode(["message" => "No changes provided"]);
            exit;
        }
        
        $values[] = $id;
        $sql = "UPDATE admin_users SET " . implode(", ", $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        
        echo json_encode(["message" => "User updated successfully"]);
    }
    elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            throw new Exception("User ID is required");
        }
        
        $stmt = $pdo->prepare("DELETE FROM admin_users WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(["message" => "User deleted successfully"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
?>