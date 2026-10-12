<?php
require_once 'db.php';

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$mode = $data['mode'] ?? null;
$productNames = $data['productNames'] ?? [];
$containerType = $data['container'] ?? null;
$imageUrl = $data['imageUrl'] ?? null;

if ($mode !== 'combo_text' && !$imageUrl) {
    http_response_code(400);
    echo json_encode(['error' => 'Image URL is required']);
    exit;
}

// Helper to handle Data URIs
function parseDataUri($uri) {
    if (preg_match('/^data:image\/(\w+);base64,/', $uri, $matches)) {
        return [
            'mime_type' => 'image/' . $matches[1],
            'data' => substr($uri, strpos($uri, ',') + 1),
            'is_data_uri' => true
        ];
    }
    return ['is_data_uri' => false];
}

try {
    // 1. Get Config from DB
    $stmt = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
    $config = $stmt->fetch();

    if (!$config) {
        throw new Exception('Configuração não encontrada');
    }

    $openAiKey = $config['openai_api_key'] ?? ($config['product_ai_api_key'] ?? '');

    if ($mode === 'combo_image') {
        if (empty($openAiKey)) {
            echo json_encode(['imageUrl' => $imageUrl]);
            exit;
        }

        $namesText = '';
        if (is_array($productNames) && count($productNames) > 0) {
            $namesText = implode(', ', array_map('trim', $productNames));
        }
        $containerLabel = $containerType ? $containerType : 'cesta';
        $containerText = $containerLabel === 'sacola'
            ? 'sacola de papel com o logo da loja estampado'
            : ($containerLabel === 'caixa' ? 'caixa com o logo da loja estampado' : 'cesta de compras');

        $imageInfo = parseDataUri($imageUrl);
        if ($imageInfo['is_data_uri']) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'combo_');
            file_put_contents($tmpFile, base64_decode($imageInfo['data']));
        } else {
            $content = file_get_contents($imageUrl);
            if ($content === false) {
                echo json_encode(['imageUrl' => $imageUrl]);
                exit;
            }
            $tmpFile = tempnam(sys_get_temp_dir(), 'combo_');
            file_put_contents($tmpFile, $content);
        }

        $prompt = "Crie uma imagem profissional de um kit combo de produtos de limpeza. Mostre os itens juntos dentro de {$containerText}. Não inclua textos, preços ou selos. Fundo claro, aparência realista e preserve as proporções reais dos produtos: itens maiores devem parecer maiores e itens pequenos não podem parecer grandes. Use a imagem base como referência de escala. Produtos: {$namesText}.";

        $payload = [
            'model' => 'gpt-image-1',
            'prompt' => $prompt,
            'size' => '1024x1024',
        ];

        $ch = curl_init('https://api.openai.com/v1/images/edits');
        $payload['image'] = curl_file_create($tmpFile, 'image/png', 'combo.png');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $openAiKey
        ]);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            @unlink($tmpFile);
            echo json_encode(['imageUrl' => $imageUrl]);
            exit;
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($tmpFile);

        if ($httpCode !== 200) {
            echo json_encode(['imageUrl' => $imageUrl]);
            exit;
        }

        $result = json_decode($response, true);
        $b64 = $result['data'][0]['b64_json'] ?? null;
        if ($b64) {
            echo json_encode(['imageUrl' => 'data:image/png;base64,' . $b64]);
            exit;
        }

        echo json_encode(['imageUrl' => $imageUrl]);
        exit;
    }

    // Determine Provider, Model, and Key for Product AI
    // Prioritize product-specific config, fallback to general config
    $provider = $config['product_ai_provider'] ?? $config['active_provider'] ?? 'openai';
    
    // Default models if not specified
    $defaultModels = [
        'openai' => 'gpt-4o-mini',
        'gemini' => 'gemini-1.5-flash',
        'groq'   => 'llama-3.2-11b-vision-preview'
    ];
    
    $model = $config['product_ai_model'] ?? $defaultModels[$provider] ?? $defaultModels['openai'];
    if (empty($model)) $model = $defaultModels[$provider] ?? 'gpt-4o-mini';

    // Get API Key
    $apiKey = $config['product_ai_api_key'] ?? '';
    if (empty($apiKey)) {
        // Fallback to provider specific keys
        if ($provider === 'openai') $apiKey = $config['openai_api_key'] ?? '';
        elseif ($provider === 'gemini') $apiKey = $config['gemini_api_key'] ?? '';
        elseif ($provider === 'groq') $apiKey = $config['groq_api_key'] ?? '';
    }

    if (empty($apiKey)) {
        throw new Exception("Chave API não configurada para o provedor: $provider");
    }
    
    if ($mode === 'combo_text') {
        $namesText = '';
        if (is_array($productNames) && count($productNames) > 0) {
            $namesText = implode(', ', array_map('trim', $productNames));
        }

        $comboPrompt = "Crie um nome curto e forte e uma descrição rápida para um combo de produtos de limpeza. A descrição deve ter no máximo 30 palavras, sem preços e sem emojis. Responda apenas com JSON no formato: {\"nome\": \"\", \"descricao\": \"\"}. Produtos: {$namesText}.";

        if ($provider === 'groq') {
            $payload = [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $comboPrompt
                    ]
                ],
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object']
            ];
            $responseContent = callApi('https://api.groq.com/openai/v1/chat/completions', $apiKey, $payload);
        } elseif ($provider === 'gemini') {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $comboPrompt]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.2
                ]
            ];
            $responseContent = callApi($url, $apiKey, $payload);
            $result = json_decode($responseContent, true);
            $responseContent = $result['candidates'][0]['content']['parts'][0]['text'] ?? $responseContent;
        } else {
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => $comboPrompt]
                ],
                'max_tokens' => 300,
                'response_format' => ['type' => 'json_object']
            ];
            $responseContent = callApi('https://api.openai.com/v1/chat/completions', $apiKey, $payload);
        }

        if (preg_match('/```json\s*(.*?)\s*```/s', $responseContent, $matches)) {
            $responseContent = $matches[1];
        }
        $comboData = json_decode($responseContent, true);
        if (!$comboData) {
            throw new Exception('Falha ao decodificar JSON da IA: ' . $responseContent);
        }

        echo json_encode($comboData);
        exit;
    }

    // Default system prompt
    $systemPrompt = 'Você é um especialista em produtos de limpeza. Analise a imagem e retorne um JSON com os dados do produto.
    O JSON deve ter exatamente este formato:
    {
        "nome": "Nome comercial do produto (ex: Detergente Limpol 500ml)",
        "descricao": "Texto simples, curto e de leitura rápida sobre o produto (MÁXIMO 30 PALAVRAS).",
        "categoria": "Categoria (ex: limpeza_geral, roupas, cozinha, banheiro). use snake_case.",
        "preco_sugerido": "valor numérico estimado (ex: 15.90)",
        "unidade": "unidade (ex: 500ml, 1L, un)",
        "especificacoes": "Destaques técnicos muito curtos em tópicos.",
        "instrucoes": "Instrução de uso resumida e direta. Ex: Aplique, esfregue e enxágue.",
        "ncm": "NCM com 8 dígitos. Se não souber, use string vazia.",
        "cfop": "CFOP. Se não souber, use string vazia.",
        "cest": "CEST. Se não souber, use string vazia.",
        "origem": "Origem 0-8. Se não souber, use string vazia.",
        "aliquota_icms": "Percentual ICMS. Se não souber, use string vazia.",
        "aliquota_ipi": "Percentual IPI. Se não souber, use string vazia.",
        "aliquota_pis": "Percentual PIS. Se não souber, use string vazia.",
        "aliquota_cofins": "Percentual COFINS. Se não souber, use string vazia.",
        "cst_csosn": "CST/CSOSN. Se não souber, use string vazia.",
        "unidade_fiscal": "Unidade fiscal (UN, KG, CX, etc.). Se não souber, use string vazia.",
        "gtin": "GTIN/EAN. Se não souber, use string vazia."
    }';

    $responseContent = '';

    if ($provider === 'groq') {
        // Groq Logic
        // Ensure model is vision capable if it's a default text model
        if (strpos($model, 'vision') === false && strpos($model, 'llama-3.2') === false) {
             $model = 'llama-3.2-11b-vision-preview';
        }

        $payload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'user', 
                    'content' => [
                        ['type' => 'text', 'text' => $systemPrompt . " Responda apenas com o JSON válido."],
                        ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]]
                    ]
                ]
            ],
            'temperature' => 0.1,
            'response_format' => ['type' => 'json_object']
        ];

        $responseContent = callApi('https://api.groq.com/openai/v1/chat/completions', $apiKey, $payload);

    } elseif ($provider === 'gemini') {
        // Gemini Logic
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        
        $imageDataInfo = parseDataUri($imageUrl);
        $base64Data = '';
        $mimeType = 'image/jpeg';

        if ($imageDataInfo['is_data_uri']) {
            $base64Data = $imageDataInfo['data'];
            $mimeType = $imageDataInfo['mime_type'];
        } else {
            // It's a URL, download it
            $content = file_get_contents($imageUrl);
            if ($content === false) throw new Exception("Falha ao baixar imagem da URL");
            $base64Data = base64_encode($content);
            // Try to guess mime type or default to jpeg
            $mimeType = 'image/jpeg'; 
        }
        
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $systemPrompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $base64Data
                            ]
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'response_mime_type' => 'application/json'
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) throw new Exception('Curl error: ' . curl_error($ch));
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            throw new Exception('Gemini API Error: ' . ($err['error']['message'] ?? $response));
        }

        $result = json_decode($response, true);
        $responseContent = $result['candidates'][0]['content']['parts'][0]['text'];

    } else {
        // Default to OpenAI
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                [
                    'role' => 'user', 
                    'content' => [
                        ['type' => 'text', 'text' => 'Analise este produto.'],
                        ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]]
                    ]
                ]
            ],
            'max_tokens' => 500,
            'response_format' => ['type' => 'json_object']
        ];

        $responseContent = callApi('https://api.openai.com/v1/chat/completions', $apiKey, $payload);
    }

    // Extract JSON from response if it contains markdown code blocks
    if (preg_match('/```json\s*(.*?)\s*```/s', $responseContent, $matches)) {
        $responseContent = $matches[1];
    }

    $productData = json_decode($responseContent, true);
    
    if (!$productData) {
        throw new Exception('Falha ao decodificar JSON da IA: ' . $responseContent);
    }

    echo json_encode($productData);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function callApi($url, $apiKey, $payload) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);

    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        throw new Exception('Curl error: ' . curl_error($ch));
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $errorBody = json_decode($response, true);
        $errorMessage = $errorBody['error']['message'] ?? $response;
        throw new Exception('API Error (' . $httpCode . '): ' . $errorMessage);
    }

    $result = json_decode($response, true);
    return $result['choices'][0]['message']['content'];
}
?>