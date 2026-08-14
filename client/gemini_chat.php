<?php
// client/gemini_chat.php
require_once __DIR__ . '/../config/dotenv.php';
header('Content-Type: application/json');

// 1. Get the User's Message
$input = json_decode(file_get_contents("php://input"), true);
$userMessage = $input['message'] ?? '';

if (empty($userMessage)) {
    echo json_encode(["reply" => "Please say something!"]);
    exit();
}

$apiKey = getenv('GEMINI_API_KEY');

// 2. Define the AI's Persona (ENHANCED VERSION)
$context = "You are 'Orbit', the intelligent AI assistant for Abilisto, a service platform in Surigao del Sur (covering Cantilan, Cortes, Madrid, Carrascal, Lanuza, and Carmen). 

CORE KNOWLEDGE:
- SERVICES: We connect clients with experts in Electrical, Plumbing, Carpentry, Welding, Automotive, Electronics, and more.
- VERIFICATION: Workers have 4 badge levels: Bronze (NC I), Silver (NC II), Gold (NC III/Master), and Community Verified. Gold is the highest TESDA-verified tier.
- MOBILIZATION FEE: Every booking has a base fee. It is P50 for Cash payments, but only P39 if paid via GCash or Maya (Online).
- TRUST & SAFETY: We have a 3-strike penalty system. If a user 'ditches' a worker, they lose the ability to pay with Cash and must use Online payment for future bookings. 3 reports lead to a ban.

TONE:
- keep responses concise, friendly, and helpful but short.
- Be helpful, local-friendly, adapt to language (Bisaya, Tagalog, and English) and brief. 
- If user asks about payment methods, always encourage digital payments over cash payment.
- If user asks about verification, always encourage them to verify workers for safety.
- If a user asks about safety, explain our verification and penalty systems.

IMPORTANT: Do NOT use any Markdown formatting like asterisks (*) or other special characters. Respond in plain text only, without any formatting symbols.";

$finalPrompt = $context . "\n\nUser: " . $userMessage . "\nOrbit:";

// 3. Prepare Data (using stable model)
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=" . $apiKey;
$data = [
    "contents" => [
        [
            "parts" => [
                ["text" => $finalPrompt]
            ]
        ]
    ],
    "generationConfig" => [
        "temperature" => 0.7,
        "maxOutputTokens" => 500
    ]
];

// 4. Send Request via cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

// --- THE FIX FOR LOCALHOST (XAMPP) ---
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1'); // verify in production, skip only on local XAMPP (no CA bundle)
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, getenv('APP_DEBUG') !== '1' ? 2 : 0);

$response = curl_exec($ch);

// 5. Check for Connection Errors
if ($response === false) {
    $curlError = curl_error($ch);
    curl_close($ch);
    echo json_encode(["reply" => "Connection Failed: " . $curlError]);
    exit();
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 6. Process Response
if ($httpCode === 200) {
    $decoded = json_decode($response, true);
    $aiReply = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? "I'm having trouble thinking right now.";
    
    // Clean up the response - remove markdown formatting
    $aiReply = cleanMarkdown($aiReply);
    
    echo json_encode(["reply" => $aiReply]);
} else {
    // This will show the exact error message from Google if it fails again
    echo json_encode(["reply" => "API Error (Code $httpCode): " . $response]);
}

/**
 * Clean markdown formatting from text
 * Removes **, *, __, etc. and returns plain text
 */
function cleanMarkdown($text) {
    // Remove bold/italic markdown
    $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text); // **bold**
    $text = preg_replace('/__(.*?)__/', '$1', $text);     // __bold__
    $text = preg_replace('/\*(.*?)\*/', '$1', $text);     // *italic*
    $text = preg_replace('/_(.*?)_/', '$1', $text);       // _italic_
    
    // Remove code blocks and inline code
    $text = preg_replace('/```(.*?)```/s', '$1', $text);  // ```code```
    $text = preg_replace('/`(.*?)`/', '$1', $text);       // `code`
    
    // Remove headers
    $text = preg_replace('/^#+\s?(.*?)$/m', '$1', $text); // # Header
    
    // Remove bullet points (but keep the text)
    $text = preg_replace('/^\s*[-*+]\s+(.*?)$/m', '• $1', $text); // convert to simple bullet
    
    return trim($text);
}
?>