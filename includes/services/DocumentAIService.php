<?php
// includes/services/DocumentAIService.php

require_once __DIR__ . '/../../config/dotenv.php';

class DocumentAIService {
    private $apiKey;
    private $processorId;
    private $projectId;

    public function __construct() {
        // Google Cloud project — see .env
        $this->projectId = getenv('FIREBASE_PROJECT_ID');
        $this->apiKey = getenv('FIREBASE_API_KEY');
        $this->processorId = 'YOUR_PROCESSOR_ID'; // Get this from step 3
    }
    
    /**
     * Process document using Google Document AI REST API
     */
    public function processDocument($filePath, $mimeType = 'image/jpeg') {
        // API endpoint
        $url = "https://documentai.googleapis.com/v1/projects/{$this->projectId}/locations/us/processors/{$this->processorId}:process?key={$this->apiKey}";
        
        // Read and encode file
        $content = base64_encode(file_get_contents($filePath));
        
        // Prepare request data
        $data = [
            'rawDocument' => [
                'content' => $content,
                'mimeType' => $mimeType
            ]
        ];
        
        // Make API call
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1'); // verify in production, skip only on local XAMPP (no CA bundle)
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            error_log("Document AI CURL Error: " . $error);
            return ['success' => false, 'error' => 'Connection error'];
        }
        
        if ($httpCode != 200) {
            error_log("Document AI HTTP Error: $httpCode - $response");
            return ['success' => false, 'error' => 'API error: ' . $httpCode];
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['error'])) {
            error_log("Document AI Error: " . print_r($result['error'], true));
            return ['success' => false, 'error' => $result['error']['message'] ?? 'Unknown error'];
        }
        
        // Extract text from response
        $text = $this->extractTextFromResponse($result);
        
        return [
            'success' => true,
            'text' => $text,
            'full_response' => $result
        ];
    }
    
    /**
     * Extract text from Document AI response
     */
    private function extractTextFromResponse($response) {
        $text = '';
        
        if (isset($response['document']['text'])) {
            $text = $response['document']['text'];
        } elseif (isset($response['document']['pages'])) {
            foreach ($response['document']['pages'] as $page) {
                if (isset($page['paragraphs'])) {
                    foreach ($page['paragraphs'] as $paragraph) {
                        if (isset($paragraph['layout']['textAnchor']['textSegments'])) {
                            foreach ($paragraph['layout']['textAnchor']['textSegments'] as $segment) {
                                if (isset($segment['content'])) {
                                    $text .= $segment['content'] . "\n";
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return trim($text);
    }
    
    /**
     * Extract text from ID
     */
    public function extractIDInfo($filePath) {
        $result = $this->processDocument($filePath);
        
        if (!$result['success']) {
            return $result;
        }
        
        $text = $result['text'];
        
        // Extract common fields using regex
        $extracted = [
            'full_name' => '',
            'birth_date' => '',
            'id_number' => '',
            'address' => '',
            'nationality' => 'Filipino',
            'text' => $text
        ];
        
        // Philippine ID patterns
        $patterns = [
            'full_name' => [
                '/name[:\s]+([A-Z\s,]+)/i',
                '/([A-Z]+,\s+[A-Z]+)/',
                '/name[:\s]+([A-Z\s]+)/i'
            ],
            'birth_date' => [
                '/birth[\s]*date[:\s]+(\d{1,2}[-/]\d{1,2}[-/]\d{2,4})/i',
                '/born[:\s]+(\d{1,2}[-/]\d{1,2}[-/]\d{2,4})/i',
                '/(\d{1,2}[-\/]\d{1,2}[-\/]\d{4})/'
            ],
            'id_number' => [
                '/id[:\s]*no[.:\s]*(\d+[-]\d+)/i',
                '/(\d{4}-\d{4}-\d{4})/',
                '/(\d{4}-\d{5}-\d{4})/',
                '/(\d{4}\s\d{4}\s\d{4})/'
            ],
            'address' => [
                '/address[:\s]+([^\n]+)/i',
                '/(?:address|addr)[:\s]+([A-Z\s,]+)/i'
            ]
        ];
        
        foreach ($patterns as $field => $regexArray) {
            foreach ($regexArray as $regex) {
                if (preg_match($regex, $text, $matches)) {
                    $extracted[$field] = trim($matches[1]);
                    break;
                }
            }
        }
        
        return [
            'success' => true,
            'data' => $extracted
        ];
    }
    
    /**
     * Extract NC certificate information
     */
    public function extractNCInfo($filePath) {
        $result = $this->processDocument($filePath);
        
        if (!$result['success']) {
            return $result;
        }
        
        $text = $result['text'];
        $extracted = [
            'certificate_type' => '',
            'full_name' => '',
            'certificate_number' => '',
            'issue_date' => '',
            'level' => '',
            'text' => $text
        ];
        
        // Detect NC level
        if (preg_match('/NC\s*I/i', $text)) {
            $extracted['level'] = 'NC I';
        } elseif (preg_match('/NC\s*II/i', $text)) {
            $extracted['level'] = 'NC II';
        } elseif (preg_match('/NC\s*III/i', $text)) {
            $extracted['level'] = 'NC III';
        } elseif (preg_match('/National Certificate\s*I/i', $text)) {
            $extracted['level'] = 'NC I';
        } elseif (preg_match('/National Certificate\s*II/i', $text)) {
            $extracted['level'] = 'NC II';
        } elseif (preg_match('/National Certificate\s*III/i', $text)) {
            $extracted['level'] = 'NC III';
        }
        
        // Extract name
        $namePatterns = [
            '/name[:\s]+([A-Z\s,]+)/i',
            '/([A-Z]+,\s+[A-Z]+)/',
            '/holder[:\s]+([A-Z\s]+)/i'
        ];
        
        foreach ($namePatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $extracted['full_name'] = trim($matches[1]);
                break;
            }
        }
        
        // Extract certificate number
        $numberPatterns = [
            '/(?:certificate|serial)[\s#.:]*(\w+[-]\w+)/i',
            '/(?:certificate|serial)[\s#.:]*(\d+-\d+-\d+)/i',
            '/(\d{4}-\d{4}-\d{4})/'
        ];
        
        foreach ($numberPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $extracted['certificate_number'] = $matches[1];
                break;
            }
        }
        
        // Extract issue date
        if (preg_match('/(?:issued|date)[:\s]+(\d{1,2}[-/]\d{1,2}[-/]\d{2,4})/i', $text, $matches)) {
            $extracted['issue_date'] = $matches[1];
        }
        
        return [
            'success' => true,
            'data' => $extracted
        ];
    }
    
    /**
     * Simple OCR - just get text
     */
    public function extractText($filePath) {
        $result = $this->processDocument($filePath);
        
        if (!$result['success']) {
            return $result;
        }
        
        return [
            'success' => true,
            'text' => $result['text']
        ];
    }
}