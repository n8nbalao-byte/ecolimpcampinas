<?php
// Aumentar limites para upload de vídeos
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('max_execution_time', '300');

require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    if (!isset($_FILES['file'])) {
        throw new Exception("Nenhum arquivo enviado");
    }

    $file = $_FILES['file'];
    $type = $file['type'];
    $name = $file['name'];
    $tmp_name = $file['tmp_name'];
    $error = $file['error'];

    if ($error !== UPLOAD_ERR_OK) {
        throw new Exception("Erro no upload: " . $error);
    }

    // Define base directory for uploads
    $upload_dir = __DIR__ . '/../uploads/';
    
    // Create directory structure if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Determine subfolder based on type
    $subfolder = 'images';
    if (strpos($type, 'video/') !== false) {
        $subfolder = 'videos';
    } elseif (strpos($type, 'image/gif') !== false) {
        $subfolder = 'videos'; // Store GIFs in videos as they are loops
    }

    $target_dir = $upload_dir . $subfolder . '/';
    if (!is_dir($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    // Generate unique filename
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $new_name = uniqid('up_', true) . '.' . $extension;
    $target_path = $target_dir . $new_name;

    // Move file
    if (!move_uploaded_file($tmp_name, $target_path)) {
        throw new Exception("Não foi possível salvar o arquivo no servidor");
    }

    // Return the relative URL for the client
    $relative_url = '/uploads/' . $subfolder . '/' . $new_name;
    
    echo json_encode([
        'url' => $relative_url,
        'message' => 'Upload realizado com sucesso'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>