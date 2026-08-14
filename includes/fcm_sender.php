<?php
// includes/fcm_sender.php
// Upgraded to OneSignal API, but keeping the FCMv1 class name so your other files don't break!

require_once __DIR__ . '/../config/dotenv.php';

class FCMv1 {
    private $appId;
    private $restApiKey;

    public function __construct() {
        // OneSignal credentials — see .env
        $this->appId = getenv('ONESIGNAL_APP_ID');
        $this->restApiKey = getenv('ONESIGNAL_REST_API_KEY');
    }
    
    // NOTE: $token now expects your database User ID, NOT a Firebase token string!
    public function send($token, $title, $body, $data = []) {
        try {
            // Convert ALL data values to strings
            $stringData = [];
            foreach ($data as $key => $value) {
                $stringData[$key] = $value !== null ? (string)$value : '';
            }
            
            $message = [
                'app_id' => $this->appId,
                'target_channel' => 'push',
                'include_aliases' => [
                    'external_id' => [(string)$token] // Maps to the specific User ID
                ],
                'headings' => ['en' => $title],
                'contents' => ['en' => $body],
                'data' => $stringData
            ];
            
            $url = "https://onesignal.com/api/v1/notifications";
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . $this->restApiKey,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
            
            // 🔥 CRITICAL FIX: Kept your XAMPP override intact here!
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1'); // verify in production, skip only on local XAMPP (no CA bundle)
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return [
                    'success' => false, 
                    'error' => 'CURL Error: ' . $curlError,
                    'http_code' => $httpCode
                ];
            }
            
            if ($httpCode === 200) {
                return [
                    'success' => true, 
                    'response' => json_decode($response, true)
                ];
            } else {
                return [
                    'success' => false, 
                    'error' => $response,
                    'http_code' => $httpCode
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false, 
                'error' => $e->getMessage()
            ];
        }
    }
}
?>