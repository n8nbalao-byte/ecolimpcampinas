<?php
require_once 'db.php';

class WhatsAppService {
    private $apiUrl;
    private $instance;
    private $apiKey;
    private $pdo;

    public function __construct() {
        global $pdo;
        $this->pdo = $pdo;
        $this->loadConfig();
    }

    private function loadConfig() {
        try {
            $stmt = $this->pdo->query("SELECT evolution_api_url, evolution_instance, evolution_apikey FROM configuracoes WHERE id = 1");
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($config) {
                // Remove trailing slash from URL if present
                $this->apiUrl = rtrim($config['evolution_api_url'] ?? 'https://api.n8nbalao.com', '/');
                $this->instance = $config['evolution_instance'] ?? '';
                $this->apiKey = $config['evolution_apikey'] ?? '';
            }
        } catch (PDOException $e) {
            error_log("Error loading WhatsApp config: " . $e->getMessage());
        }
    }

    public function sendText($to, $message) {
        if (empty($this->instance) || empty($this->apiKey)) {
            error_log("WhatsApp API not configured (Missing Instance or API Key)");
            return ['error' => 'Not configured'];
        }

        // Format phone number
        $to = $this->formatPhone($to);
        
        if (!$to) {
            return ['error' => 'Invalid phone number'];
        }

        $url = "{$this->apiUrl}/message/sendText/{$this->instance}";
        
        $data = [
            'number' => $to,
            'text' => $message
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'apikey: ' . $this->apiKey
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("WhatsApp API Error (Curl): " . $curlError);
            return ['error' => $curlError];
        }

        $decoded = json_decode($response, true);
        
        // Log for debugging
        if ($httpCode >= 400) {
            error_log("WhatsApp API Error (HTTP $httpCode): " . $response);
        }

        return $decoded;
    }

    private function formatPhone($phone) {
        // Remove non-digits
        $phone = preg_replace('/\D/', '', $phone);
        
        // Basic validation
        if (empty($phone)) return null;

        // If it's a Brazilian number (10 or 11 digits), add 55
        // 10 digits: 11 9999-9999 (Landline? rare for WA but possible)
        // 11 digits: 11 99999-9999 (Mobile)
        if (strlen($phone) >= 10 && strlen($phone) <= 11) {
            return '55' . $phone;
        }
        
        // If it already has 12 or 13 digits (5511...)
        if (strlen($phone) >= 12 && strlen($phone) <= 13) {
            return $phone;
        }

        // Return as is if we can't determine (or let the API handle validation)
        return $phone;
    }
}