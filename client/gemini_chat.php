<?php
// client/gemini_chat.php
require_once __DIR__ . '/../config/dotenv.php';
header('Content-Type: application/json');

// 1. Get the User's Message (+ optional conversation history)
$input = json_decode(file_get_contents("php://input"), true);
$userMessage = $input['message'] ?? '';
$history = is_array($input['history'] ?? null) ? $input['history'] : [];

if (empty($userMessage)) {
    echo json_encode(["reply" => "Please say something!"]);
    exit();
}

$apiKey = getenv('GEMINI_API_KEY');

// 2. Define Abi's persona as a system instruction (kept separate from the
// conversation turns, per Gemini's own recommended structure, rather than
// prepended into a single flattened prompt string).
$systemInstruction = "You are 'Abi', the intelligent AI assistant for Abilisto, a service marketplace platform in Surigao del Sur (covering Cantilan, Cortes, Madrid, Carrascal, Lanuza, and Carmen) connecting clients with verified local workers.

CORE KNOWLEDGE:
- SERVICES: We connect clients with experts in Electrical, Plumbing, Carpentry, Masonry, Welding, Automotive, Motorcycle repair, Electronics, Computer Tech, Domestic Work, Caregiving, Beauty Care, and more.
- BOOKING FLOW: A client picks a worker, books a job, and pays a mobilization fee (based on travel distance + urgency: Normal/High/Emergency). Paying online (GCash, Maya, or Card, via our PayMongo gateway) gives a 10% discount versus paying cash on arrival. Once the worker accepts, the job proceeds; after the work is done, the client pays the final labor + materials cost (again with a small online discount vs cash) and the booking is marked complete.
- VERIFICATION TIERS: Workers carry one of four badge levels — Bronze (TESDA NC I, entry-level), Silver (TESDA NC II, standard residential/commercial work), Gold (TESDA NC III, master-level), or Community (not a TESDA credential — vouched for directly by neighbors, for simple tasks). Gold is the highest official TESDA tier.
- QUICK MATCH: An instant-matching feature — the client's job gets broadcast live to nearby available workers, and whoever accepts first gets the job. Faster than browsing profiles manually.
- WE MAP: A live map showing nearby available workers to clients before they book.
- GREENLOOP: A separate scrap-recycling side feature — users report recyclable/e-waste scrap, get it picked up, and earn Green Coins they can redeem for rewards in the GreenLoop Wallet.
- WORKER WALLET: Workers earn money into an in-app wallet from completed jobs (a small platform commission is deducted), can top up their wallet online to cover the small per-booking acceptance fee, and withdraw earnings to GCash.
- TRUST & SAFETY: We have a 3-strike penalty system. If a client repeatedly cancels or 'ditches' a worker after booking, they lose the ability to pay cash and must pay online for future bookings. Repeated serious reports can lead to a suspension.

TONE:
- Keep responses concise, friendly, and genuinely helpful — a few sentences is usually enough, not a wall of text.
- Adapt naturally to whatever language the user writes in (Bisaya, Tagalog, or English).
- If asked about payment methods, mention the online discount as a real incentive, not just a generic recommendation.
- If asked about worker trust/safety, explain the verification tiers and penalty system concretely.
- If you don't actually know something about the app (e.g. a very specific policy detail), say so honestly rather than guessing — don't invent numbers, fees, or rules that aren't listed above.
- Use conversation history to stay consistent with what's already been discussed in this chat — don't repeat yourself or ask something the user already answered.

IMPORTANT: Do NOT use any Markdown formatting like asterisks (*) or other special characters. Respond in plain text only, without any formatting symbols.";

// 3. Build multi-turn contents from history + the new message (Gemini's
// actual multi-turn format — previously every message was sent in total
// isolation with zero memory of anything said earlier in the same chat).
$contents = [];
foreach ($history as $turn) {
    $role = ($turn['role'] ?? '') === 'model' ? 'model' : 'user';
    $text = trim((string)($turn['text'] ?? ''));
    if ($text === '') continue;
    $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
}
$contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

// 4. Prepare request
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=" . $apiKey;
$data = [
    "systemInstruction" => ["parts" => [["text" => $systemInstruction]]],
    "contents"          => $contents,
    "generationConfig"  => [
        "temperature"     => 0.7,
        "maxOutputTokens" => 1024,
    ],
];

// 5. Send Request via cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

// --- THE FIX FOR LOCALHOST (XAMPP) ---
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, getenv('APP_DEBUG') !== '1'); // verify in production, skip only on local XAMPP (no CA bundle)
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, getenv('APP_DEBUG') !== '1' ? 2 : 0);

$response = curl_exec($ch);

// 6. Check for Connection Errors
if ($response === false) {
    $curlError = curl_error($ch);
    curl_close($ch);
    echo json_encode(["reply" => "Connection Failed: " . $curlError]);
    exit();
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 7. Process Response
if ($httpCode === 200) {
    $decoded = json_decode($response, true);
    $candidate = $decoded['candidates'][0] ?? null;
    $aiReply = $candidate['content']['parts'][0]['text'] ?? null;
    $finishReason = $candidate['finishReason'] ?? null;

    if ($aiReply === null) {
        error_log("gemini_chat: no reply text in response — " . $response);
        $aiReply = "I'm having trouble thinking right now.";
    } elseif ($finishReason === 'MAX_TOKENS') {
        // Previously a truncated reply (maxOutputTokens hit mid-sentence)
        // was returned as-is with no indication anything was cut off.
        error_log("gemini_chat: reply truncated at MAX_TOKENS");
        $aiReply = rtrim($aiReply) . "…";
    }

    $aiReply = cleanMarkdown($aiReply);
    echo json_encode(["reply" => $aiReply]);
} else {
    error_log("gemini_chat: API error (HTTP $httpCode): " . $response);
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
