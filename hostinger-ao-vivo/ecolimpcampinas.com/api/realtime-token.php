<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // 1. Fetch Store Config
    $stmtConfig = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
    $config = $stmtConfig->fetch();

    $activeProvider = $config['active_provider'] ?? 'openai';
    
    // 2. Fetch Products and Build Instructions (Common for all providers)
    $stmtProducts = $pdo->query("SELECT id, nome, preco, descricao, categoria FROM produtos WHERE ativo = 1");
    $products = $stmtProducts->fetchAll();

    $instructions = "Você é um assistente de vendas amigável e útil para a loja " . ($config['nome_empresa'] ?? 'Nossa Loja') . ". ";
    $instructions .= "Sua missão é ajudar os clientes a encontrar os melhores produtos de limpeza para suas necessidades. ";
    $instructions .= "Você deve ser educado, profissional e conciso. ";
    
    if (!empty($config['agente_instrucoes'])) {
        $instructions .= "\n\nInstruções específicas da loja: " . $config['agente_instrucoes'];
    }

    $instructions .= "\n\nCatálogo de Produtos Disponíveis:\n";
    foreach ($products as $p) {
        $instructions .= "- ID: {$p['id']}, Nome: {$p['nome']}, Preço: R$ {$p['preco']}, Categoria: {$p['categoria']}\n";
        if (!empty($p['descricao'])) {
            $instructions .= "  Descrição: {$p['descricao']}\n";
        }
    }

    // 4. Define Tools (Common)
    $tools = [
        [
            "type" => "function",
            "name" => "add_to_cart",
            "description" => "Adiciona um produto ao carrinho de compras. O assistente deve extrair o ID, nome e preço do produto do contexto.",
            "parameters" => [
                "type" => "object",
                "properties" => [
                    "product_id" => [
                        "type" => "string",
                        "description" => "O ID do produto a ser adicionado"
                    ],
                    "product_name" => [
                        "type" => "string",
                        "description" => "O nome do produto"
                    ],
                    "quantity" => [
                        "type" => "integer",
                        "description" => "A quantidade a ser adicionada",
                        "default" => 1
                    ],
                    "price" => [
                        "type" => "number",
                        "description" => "O preço unitário do produto"
                    ]
                ],
                "required" => ["product_id", "product_name", "price"]
            ]
        ],
        [
            "type" => "function",
            "name" => "get_cart",
            "description" => "Retorna os itens atuais do carrinho e o total.",
            "parameters" => [
                "type" => "object",
                "properties" => new stdClass(),
                "required" => []
            ]
        ],
        [
            "type" => "function",
            "name" => "clear_cart",
            "description" => "Remove todos os itens do carrinho.",
            "parameters" => [
                "type" => "object",
                "properties" => new stdClass(),
                "required" => []
            ]
        ],
        [
            "type" => "function",
            "name" => "create_order",
            "description" => "Finaliza o pedido. Colete todas as informações necessárias do usuário antes de chamar esta função.",
            "parameters" => [
                "type" => "object",
                "properties" => [
                    "customer_name" => [
                        "type" => "string",
                        "description" => "Nome do cliente"
                    ],
                    "customer_phone" => [
                        "type" => "string",
                        "description" => "Telefone do cliente"
                    ],
                    "address" => [
                        "type" => "string",
                        "description" => "Endereço de entrega (Rua, Bairro, Cidade)"
                    ],
                    "house_number" => [
                        "type" => "string",
                        "description" => "Número da casa/apto"
                    ],
                    "payment_method" => [
                        "type" => "string",
                        "description" => "Forma de pagamento",
                        "enum" => ["dinheiro", "pix", "cartao"]
                    ],
                    "notes" => [
                        "type" => "string",
                        "description" => "Observações do pedido (opcional)"
                    ]
                ],
                "required" => ["customer_name", "customer_phone", "address", "house_number", "payment_method"]
            ]
        ]
    ];

    // 3. Handle Provider Specifics
    if ($activeProvider === 'gemini') {
        $apiKey = $config['gemini_api_key'] ?? '';
        if (!$apiKey) {
             http_response_code(400);
             echo json_encode(['error' => 'Gemini API key not configured']);
             exit;
        }
        
        echo json_encode([
            'provider' => 'gemini',
            'token' => $apiKey,
            'model' => $config['gemini_model'] ?? 'gemini-2.0-flash-exp', 
            'instructions' => $instructions,
            'tools' => $tools
        ]);
        exit;
    } elseif ($activeProvider === 'groq') {
        $apiKey = $config['groq_api_key'] ?? '';
        if (!$apiKey) {
             http_response_code(400);
             echo json_encode(['error' => 'Groq API key not configured']);
             exit;
        }
        echo json_encode([
            'provider' => 'groq',
            'token' => $apiKey,
            'model' => $config['groq_model'] ?? 'llama3-8b-8192',
            'instructions' => $instructions,
            'openai_fallback_key' => $config['openai_api_key'] ?? '',
            'tools' => $tools
        ]);
        exit;
    }

    // Default: OpenAI
    $apiKey = null;
    if (!empty($config['openai_api_key'])) {
        $apiKey = $config['openai_api_key'];
    }
    if (!$apiKey) {
        $apiKey = getenv('OPENAI_API_KEY');
    }

    if (!$apiKey) {
        http_response_code(400);
        echo json_encode(['error' => 'OpenAI API key not configured']);
        exit;
    }

    // 5. Create Ephemeral Token via OpenAI API
    $ch = curl_init('https://api.openai.com/v1/realtime/sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    
    $body = [
        'model' => 'gpt-4o-realtime-preview-2024-12-17',
        'modalities' => ['audio', 'text'],
        'instructions' => $instructions,
        'tools' => $tools,
        'voice' => $config['agente_voz'] ?? 'alloy'
    ];
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode !== 200) {
        throw new Exception("OpenAI API Error ($httpCode): " . $response);
    }
    
    curl_close($ch);
    
    // Return the response from OpenAI (which contains the client_secret/token)
    echo $response;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>