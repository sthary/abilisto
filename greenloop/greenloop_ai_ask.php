<?php
/**
 * ============================================================
 * GreenLoop AI — OpenRouter Backend (FULLY DEBUGGED v2)
 * ============================================================
 * KEY FIXES:
 * 1. Corrected all OpenRouter model IDs to verified working ones
 * 2. Added debug_errors array surfaced in JSON response
 * 3. Fixed SSL options (CURLOPT_SSL_VERIFYHOST must be 0 int)
 * 4. Added raw response logging for failed calls
 * 5. Simplified fallback chain — fewer models, all verified free
 * 6. Added /api/v1/models check helper endpoint
 * ============================================================
 */

session_start();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── DEBUG ENDPOINT: GET ?action=test_models ──────────────────
// Visit greenloop_ai_ask.php?action=test_models to verify which models work
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'test_models') {
    $results = [];
    foreach (Config::MODELS as $key => $model) {
        $ok = AILayer::testModel($model);
        $results[$key] = ['model' => $model, 'working' => $ok];
    }
    echo json_encode(['model_tests' => $results], JSON_PRETTY_PRINT);
    exit();
}

// ── CONFIGURATION ────────────────────────────────────────────
require_once __DIR__ . '/../config/dotenv.php';

final class Config {
    // OpenRouter API key — see .env (class constants can't call getenv(), hence static property)
    public static string $API_KEY = '';

    const OPENROUTER_URL = 'https://openrouter.ai/api/v1/chat/completions';
    const HTTP_REFERER   = 'https://abilisto.com';
    const APP_TITLE      = 'GreenLoop AI';

    /**
     * VERIFIED FREE MODELS on OpenRouter as of 2025
     * These IDs are confirmed correct. Do NOT add version suffixes
     * like ":preview" or date strings — they break the lookup.
     *
     * To verify any model, visit:
     * https://openrouter.ai/models?q=free
     */
    const MODELS = [
        // Vision-capable (for image uploads)
        'vision_primary'  => 'anthropic/claude-opus-4.7',
        'vision_fallback' => 'openai/gpt-5.4-nano',

        // Text-only identification
        'text_primary'    => 'anthropic/claude-opus-4.7',
        'text_fallback_1' => 'anthropic/claude-opus-4.6-fast',
        'text_fallback_2' => 'openai/gpt-5.4-nano',
        'text_fallback_3' => 'openai/gpt-5.4-mini',

        // Chat assistant
        'chat'            => 'openai/gpt-oss-120b:free',
    ];

    const TIMEOUT_CONNECT  = 15;
    const TIMEOUT_RESPONSE = 45;
    const MAX_RETRIES      = 1;  // Keep low — free tier rate-limits fast

    const HAZARD_KEYWORDS = [
        'mercury'      => 'mercury',
        'lithium'      => 'lithium_battery',
        'lead'         => 'lead',
        'battery acid' => 'battery_acid',
        'asbestos'     => 'asbestos',
        'pcb'          => 'pcb',
        'burn'         => 'burn_risk',
        'burned'       => 'burn_risk',
        'burnt'        => 'burn_risk',
        'smoke'        => 'smoke_damage',
        'corrosive'    => 'corrosive',
        'toxic'        => 'toxic_materials',
        'freon'        => 'refrigerant',
        'cfc'          => 'refrigerant',
        'acid'         => 'battery_acid',
    ];

    const MAT_SCORES = [
        'copper'           => 8,
        'motor_coil'       => 7,
        'gold'             => 6,
        'silver'           => 5,
        'brass'            => 5,
        'bronze'           => 4,
        'aluminum'         => 4,
        'transformer_core' => 4,
        'circuit_board'    => 3,
        'steel'            => 3,
        'iron'             => 2,
        'tin'              => 2,
        'lead'             => 2,
        'lithium'          => 2,
        'rubber'           => 1,
        'plastic'          => 0,
        'glass'            => 0,
    ];

    const ALLOWED_MATERIALS = [
        'copper','aluminum','steel','iron','plastic','rubber','glass',
        'lead','tin','brass','bronze','gold','silver','lithium',
        'motor_coil','circuit_board','transformer_core',
    ];
}
Config::$API_KEY = getenv('OPENROUTER_API_KEY') ?: '';

// ── DEBUG LOG COLLECTOR ──────────────────────────────────────
class DebugLog {
    private static array $entries = [];
    public static function add(string $msg): void {
        self::$entries[] = date('H:i:s') . ' ' . $msg;
    }
    public static function all(): array {
        return self::$entries;
    }
}

// ── REQUEST ROUTER ───────────────────────────────────────────
function route(): void {
    $mode       = 'scan';
    $item_desc  = '';
    $image_b64  = '';
    $image_mime = '';
    $is_image   = false;

    if (!empty($_FILES['scrap_image']['tmp_name']) && $_FILES['scrap_image']['error'] === UPLOAD_ERR_OK) {
        [$image_b64, $image_mime] = processUpload($_FILES['scrap_image']);
        $item_desc = trim($_POST['item_description'] ?? '');
        $is_image  = true;
        DebugLog::add("Mode: image upload ($image_mime)");
    } else {
        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];
        $item_desc = trim($body['item_description'] ?? $body['message'] ?? '');
        $mode      = $body['mode'] ?? 'scan';

        if ($mode === 'chat') {
            echo json_encode(handleChat($item_desc, $body['history'] ?? []));
            return;
        }

        if (strlen($item_desc) < 3) {
            http_response_code(400);
            echo json_encode(['error' => 'Please describe the item (at least 3 characters).']);
            return;
        }
        DebugLog::add("Mode: text scan — \"$item_desc\"");
    }

    echo json_encode(handleScan($item_desc, $image_b64, $image_mime, $is_image));
}

// ── IMAGE PROCESSOR ──────────────────────────────────────────
function processUpload(array $file): array {
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!is_uploaded_file($file['tmp_name'])) {
        http_response_code(400);
        die(json_encode(['error' => 'Invalid file upload.']));
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']) ?: ($file['type'] ?? 'image/jpeg');
    finfo_close($finfo);
    if (!in_array($mime, $allowed, true)) {
        http_response_code(400);
        die(json_encode(['error' => 'Only JPG, PNG, or WebP images accepted.']));
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        http_response_code(400);
        die(json_encode(['error' => 'Image must be under 5MB.']));
    }
    $content = file_get_contents($file['tmp_name']);
    return [base64_encode($content), $mime];
}

// ═══════════════════════════════════════════════════════════════
// SCAN MODE — LAYERED FALLBACK
// ═══════════════════════════════════════════════════════════════
function handleScan(string $desc, string $image_b64, string $image_mime, bool $is_image): array {
    $identification = null;
    $fallback_used  = false;

    if (Config::$API_KEY === '' || str_contains(Config::$API_KEY, 'YOUR-ACTUAL')) {
        DebugLog::add('No API key — using rule engine');
        $identification = RuleEngine::identify($desc);
        $fallback_used  = true;
    } else {
        // ── LAYER 1: Vision (if image provided) ──────────────
        if ($is_image && $image_b64) {
            DebugLog::add('Trying vision_primary: ' . Config::MODELS['vision_primary']);
            $identification = AILayer::callVision($image_b64, $image_mime, $desc, Config::MODELS['vision_primary']);

            if (!$identification) {
                DebugLog::add('vision_primary failed, trying vision_fallback: ' . Config::MODELS['vision_fallback']);
                $identification = AILayer::callVision($image_b64, $image_mime, $desc, Config::MODELS['vision_fallback']);
            }

            // If vision fails entirely, fall through to text with description
            if (!$identification && !empty($desc)) {
                DebugLog::add('Vision failed — falling back to text with hint');
            }
        }

        // ── LAYER 2: Text models ──────────────────────────────
        if (!$identification) {
            $query = $desc ?: 'unidentified scrap item';

            $text_models = [
                Config::MODELS['text_primary'],
                Config::MODELS['text_fallback_1'],
                Config::MODELS['text_fallback_2'],
                Config::MODELS['text_fallback_3'],
            ];

            foreach ($text_models as $model) {
                DebugLog::add("Trying text model: $model");
                $identification = AILayer::callText($query, $model);
                if ($identification) {
                    DebugLog::add("Success with: $model");
                    break;
                }
                DebugLog::add("Failed: $model");
            }
        }

        // ── LAYER 3: Rule engine ──────────────────────────────
        if (!$identification) {
            DebugLog::add('All AI models failed — using rule engine');
            $identification = RuleEngine::identify($desc);
            $fallback_used  = true;
        }
    }

    $identification = Sanitizer::clean($identification, $desc);
    $identification = HazardDetector::enhance($identification, $desc);
    $scored         = ScoringEngine::calculate($identification);
    $explanation    = ExplainerEngine::generate($identification, $scored, $fallback_used);

    return ResponseFormatter::format(
        $identification, $scored, $explanation,
        $desc, $is_image ? 'image' : 'text', $fallback_used
    );
}

// ═══════════════════════════════════════════════════════════════
// CHAT MODE
// ═══════════════════════════════════════════════════════════════
function handleChat(string $message, array $history = []): array {
    if (strlen($message) < 1) {
        return ['mode' => 'chat', 'response' => 'Kumusta! Describe any scrap item and I\'ll estimate its value.', 'timestamp' => date('c')];
    }

    $response = null;

    if (Config::$API_KEY !== '' && !str_contains(Config::$API_KEY, 'YOUR-ACTUAL')) {
        $history = array_slice($history, -10);
        $system  = "You are GreenLoop AI, a friendly scrap recycling assistant for the Philippines. "
                 . "Help users identify recyclable materials and estimate scrap value in Philippine Pesos. "
                 . "Be warm and concise. Use Filipino phrases occasionally. Keep responses under 100 words.";
        $messages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $history,
            [['role' => 'user', 'content' => $message]]
        );
        $response = AILayer::callChat($messages, Config::MODELS['chat']);
    }

    if (!$response) {
        $response = getRuleBasedChatResponse($message);
    }

    return ['mode' => 'chat', 'response' => $response, 'timestamp' => date('c')];
}

function getRuleBasedChatResponse(string $message): string {
    $m = strtolower($message);
    if (str_contains($m, 'copper') || str_contains($m, 'wire'))
        return "Copper wire is high-value scrap! Currently around ₱400-500 per kilo in the Philippines. Strip and clean it for the best price. Salamat for recycling!";
    if (str_contains($m, 'aluminum') || str_contains($m, 'aluminium'))
        return "Aluminum scrap goes for about ₱60-80 per kilo. Clean aluminum fetches better prices. Grabe, every bit helps the environment!";
    if (str_contains($m, 'battery') || str_contains($m, 'lithium'))
        return "Batteries need special handling! Car batteries fetch ₱200-400 each. Lithium batteries should go to proper e-waste facilities. Never throw in regular trash!";
    if (str_contains($m, 'motor') || str_contains($m, 'fan'))
        return "Electric motors and fans contain valuable copper windings! A typical electric fan motor has about ₱50-100 worth of copper. Working units can be resold for more!";
    if (str_contains($m, 'plastic'))
        return "PET bottles: ₱8-15/kg. Hard plastics: ₱5-10/kg. Clean them first for better rates!";
    return "Salamat for your question! Describe what scrap you have — like 'old electric fan' or 'copper wires' — and I'll estimate the value for you!";
}

// ═══════════════════════════════════════════════════════════════
// AI LAYER
// ═══════════════════════════════════════════════════════════════
class AILayer {

    public static function testModel(string $model): bool {
        $messages = [['role' => 'user', 'content' => 'Reply with only the word: OK']];
        $result   = self::request($messages, $model, 10, false);
        return $result !== null;
    }

    public static function callVision(string $b64, string $mime, string $hint, string $model): ?array {
        $prompt   = ($hint ? "The user describes this as: \"{$hint}\". " : '') . self::identifyPrompt();
        $messages = [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$b64}"]],
            ],
        ]];
        return self::request($messages, $model, 700, true);
    }

    public static function callText(string $description, string $model): ?array {
        $prompt   = "Item to analyze: \"{$description}\"\n\n" . self::identifyPrompt();
        $messages = [['role' => 'user', 'content' => $prompt]];
        return self::request($messages, $model, 700, true);
    }

    public static function callChat(array $messages, string $model): ?string {
        $result = self::request($messages, $model, 250, false);
        return $result['__text'] ?? null;
    }

    private static function request(array $messages, string $model, int $max_tokens, bool $parse_json): ?array {
        $payload = json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.2,
            'max_tokens'  => $max_tokens,
        ]);

        for ($attempt = 0; $attempt <= Config::MAX_RETRIES; $attempt++) {
            $ch = curl_init(Config::OPENROUTER_URL);
            if ($ch === false) {
                DebugLog::add("[$model] cURL init failed");
                return null;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . Config::$API_KEY,
                    'Content-Type: application/json',
                    'HTTP-Referer: ' . Config::HTTP_REFERER,
                    'X-Title: ' . Config::APP_TITLE,
                ],
                CURLOPT_TIMEOUT        => Config::TIMEOUT_RESPONSE,
                CURLOPT_CONNECTTIMEOUT => Config::TIMEOUT_CONNECT,
                CURLOPT_SSL_VERIFYPEER => getenv('APP_DEBUG') !== '1', // verify in production, skip only on local XAMPP (no CA bundle)
                CURLOPT_SSL_VERIFYHOST => getenv('APP_DEBUG') !== '1' ? 2 : 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
            ]);

            $raw       = curl_exec($ch);
            $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_err  = curl_error($ch);
            curl_close($ch);

            if ($curl_err) {
                DebugLog::add("[$model] cURL error: $curl_err");
                if ($attempt < Config::MAX_RETRIES) { usleep(800000); continue; }
                return null;
            }

            // Surface the raw OpenRouter error for debugging
            if ($http_code !== 200) {
                $decoded = json_decode($raw, true);
                $or_err  = $decoded['error']['message'] ?? substr($raw, 0, 200);
                DebugLog::add("[$model] HTTP $http_code: $or_err");
                return null;
            }

            $data    = json_decode($raw, true);
            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!$content) {
                $finish = $data['choices'][0]['finish_reason'] ?? 'unknown';
                DebugLog::add("[$model] Empty content, finish_reason=$finish");
                return null;
            }

            if (!$parse_json) {
                return ['__text' => trim($content)];
            }

            $parsed = self::parseJson($content);
            if ($parsed) return $parsed;

            DebugLog::add("[$model] JSON parse failed. Raw: " . substr($content, 0, 200));
            return null;
        }
        return null;
    }

    private static function parseJson(string $text): ?array {
        // Strip markdown code fences
        $clean = preg_replace('/^```(?:json)?\s*/im', '', trim($text));
        $clean = preg_replace('/\s*```$/m', '', $clean);
        $clean = trim($clean);

        $decoded = json_decode($clean, true);
        if (is_array($decoded) && isset($decoded['item_name'])) return $decoded;

        // Try to extract JSON object from surrounding text
        if (preg_match('/\{(?:[^{}]|\{[^{}]*\})*\}/s', $clean, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded) && isset($decoded['item_name'])) return $decoded;
        }

        return null;
    }

    private static function identifyPrompt(): string {
        $mats = implode(', ', Config::ALLOWED_MATERIALS);
        return <<<PROMPT
You are a scrap material identification expert in the Philippines. Analyze the item and return ONLY valid JSON — no explanation, no markdown.

{
  "item_name": "short descriptive name (max 40 chars)",
  "condition": "Good|Fair|Damaged|Severely Damaged",
  "visible_materials": ["list from: {$mats}"],
  "possible_recoverable_parts": ["list of salvageable parts"],
  "is_appliance": true or false,
  "is_electrical": true or false,
  "is_automotive": true or false,
  "hazard_flags": ["mercury","lithium_battery","lead","battery_acid","asbestos","pcb","burn_risk","smoke_damage","refrigerant","corrosive"],
  "confidence": "high|medium|low",
  "notes": "one short helpful sentence or null"
}

IMPORTANT: Return ONLY the JSON object. No text before or after. No backticks.
PROMPT;
    }
}

// ═══════════════════════════════════════════════════════════════
// RULE ENGINE
// ═══════════════════════════════════════════════════════════════
class RuleEngine {
    private static array $ELEC_KW  = ['fan','motor','wire','circuit','battery','charger','tv','television','radio','computer','laptop','inverter','ups','generator','pump','dynamo'];
    private static array $APPL_KW  = ['refrigerator','ref','fridge','aircon','air conditioner','washing machine','dryer','oven','microwave','freezer','dispenser'];
    private static array $AUTO_KW  = ['car','auto','vehicle','engine','alternator','radiator','starter','transmission','motorcycle'];
    private static array $MAT_KW   = [
        'copper'        => ['copper','wire','cable','coil','winding'],
        'aluminum'      => ['aluminum','aluminium','alloy'],
        'steel'         => ['steel','stainless'],
        'iron'          => ['iron','cast iron'],
        'motor_coil'    => ['motor','dynamo','generator','stator'],
        'circuit_board' => ['circuit','pcb','board','electronic'],
        'brass'         => ['brass','valve','fitting'],
        'lead'          => ['lead','solder'],
        'lithium'       => ['lithium','li-ion','lifepo'],
        'rubber'        => ['rubber','hose','belt','gasket'],
        'plastic'       => ['plastic','pvc','polyethylene'],
        'glass'         => ['glass','lcd','screen','display'],
    ];

    public static function identify(string $description): array {
        $d        = strtolower($description);
        $mats     = self::detectMaterials($d);
        $is_elec  = self::matchAny($d, self::$ELEC_KW);
        $is_appl  = self::matchAny($d, self::$APPL_KW);
        $is_auto  = self::matchAny($d, self::$AUTO_KW);
        $cond     = self::detectCondition($d);
        $name     = self::extractName($description);
        $parts    = self::detectParts($d);

        if (empty($mats)) {
            if ($is_elec || $is_appl) $mats = ['copper','plastic','steel'];
            elseif ($is_auto)          $mats = ['steel','aluminum'];
            else                       $mats = ['steel'];
        }

        return [
            'item_name'                  => $name,
            'condition'                  => $cond,
            'visible_materials'          => array_values(array_unique($mats)),
            'possible_recoverable_parts' => $parts,
            'is_appliance'               => $is_appl,
            'is_electrical'              => $is_elec,
            'is_automotive'              => $is_auto,
            'hazard_flags'               => [],
            'confidence'                 => 'low',
            'notes'                      => 'Rule-based identification — AI unavailable',
        ];
    }

    private static function detectMaterials(string $d): array {
        $found = [];
        foreach (self::$MAT_KW as $mat => $kws) {
            foreach ($kws as $kw) {
                if (str_contains($d, $kw)) { $found[] = $mat; break; }
            }
        }
        return array_values(array_unique($found));
    }

    private static function detectParts(string $d): array {
        $parts = [];
        if (str_contains($d, 'motor'))   $parts[] = 'electric motor';
        if (str_contains($d, 'wire') || str_contains($d, 'cable'))  $parts[] = 'copper wiring';
        if (str_contains($d, 'circuit') || str_contains($d, 'board')) $parts[] = 'circuit board';
        if (str_contains($d, 'battery')) $parts[] = 'battery';
        if (str_contains($d, 'coil'))    $parts[] = 'copper coil';
        return $parts;
    }

    private static function detectCondition(string $d): string {
        if (str_contains($d, 'burned') || str_contains($d, 'burnt') || str_contains($d, 'crushed')) return 'Severely Damaged';
        if (str_contains($d, 'broken') || str_contains($d, 'damaged') || str_contains($d, 'rust'))  return 'Damaged';
        if (str_contains($d, 'new') || str_contains($d, 'good') || str_contains($d, 'working'))     return 'Good';
        return 'Fair';
    }

    private static function extractName(string $d): string {
        $name = trim(ucwords(strtolower($d)));
        return strlen($name) > 40 ? substr($name, 0, 37) . '…' : ($name ?: 'Scrap Item');
    }

    private static function matchAny(string $hay, array $needles): bool {
        foreach ($needles as $n) { if (str_contains($hay, $n)) return true; }
        return false;
    }
}

// ═══════════════════════════════════════════════════════════════
// HAZARD DETECTOR
// ═══════════════════════════════════════════════════════════════
class HazardDetector {
    public static function enhance(array $id, string $description): array {
        $hazards = $id['hazard_flags'] ?? [];
        $search  = strtolower($description . ' ' . ($id['item_name'] ?? '') . ' ' . implode(' ', $id['visible_materials'] ?? []));

        foreach (Config::HAZARD_KEYWORDS as $kw => $flag) {
            if (str_contains($search, $kw) && !in_array($flag, $hazards, true)) $hazards[] = $flag;
        }

        $mats = array_map('strtolower', $id['visible_materials'] ?? []);
        if (in_array('lithium', $mats, true) && !in_array('lithium_battery', $hazards, true)) $hazards[] = 'lithium_battery';
        if (in_array('lead', $mats, true)    && !in_array('lead', $hazards, true))            $hazards[] = 'lead';

        $id['hazard_flags'] = array_values(array_unique($hazards));
        return $id;
    }
}

// ═══════════════════════════════════════════════════════════════
// SANITIZER
// ═══════════════════════════════════════════════════════════════
class Sanitizer {
    public static function clean(array $id, string $fallback): array {
        $defaults = [
            'item_name'                  => $fallback ?: 'Scrap Item',
            'condition'                  => 'Fair',
            'visible_materials'          => [],
            'possible_recoverable_parts' => [],
            'is_appliance'               => false,
            'is_electrical'              => false,
            'is_automotive'              => false,
            'hazard_flags'               => [],
            'confidence'                 => 'low',
            'notes'                      => null,
        ];

        $id = array_merge($defaults, $id);
        $id['item_name'] = substr(strip_tags((string)$id['item_name']), 0, 60) ?: ($fallback ?: 'Scrap Item');

        if (!in_array($id['condition'], ['Good','Fair','Damaged','Severely Damaged'], true)) $id['condition'] = 'Fair';

        $id['visible_materials'] = array_values(array_intersect(
            array_map('strtolower', (array)$id['visible_materials']),
            Config::ALLOWED_MATERIALS
        ));

        if (!in_array($id['confidence'], ['high','medium','low'], true)) $id['confidence'] = 'low';

        foreach (['is_appliance','is_electrical','is_automotive'] as $k) {
            $id[$k] = (bool)($id[$k] ?? false);
        }

        $id['possible_recoverable_parts'] = array_slice(array_map('strval', (array)$id['possible_recoverable_parts']), 0, 8);
        $id['hazard_flags']               = array_values(array_unique(array_map('strval', (array)$id['hazard_flags'])));
        $id['notes']                      = $id['notes'] ? substr(strip_tags((string)$id['notes']), 0, 200) : null;

        return $id;
    }
}

// ═══════════════════════════════════════════════════════════════
// SCORING ENGINE
// ═══════════════════════════════════════════════════════════════
class ScoringEngine {
    public static function calculate(array $id): array {
        $materials = $id['visible_materials'] ?? [];
        $condition = $id['condition'] ?? 'Fair';

        $mat_score = 0;
        $valuable  = [];
        foreach ($materials as $mat) {
            $s = Config::MAT_SCORES[$mat] ?? 0;
            $mat_score += $s;
            if ($s >= 3) $valuable[] = $mat;
        }

        $cond_mods = ['Good' => 2, 'Fair' => 0, 'Damaged' => -1, 'Severely Damaged' => -3];
        $cond_mod  = $cond_mods[$condition] ?? 0;

        $cat_bonus = 0;
        if ($id['is_automotive'] ?? false) $cat_bonus += 2;
        if ($id['is_electrical'] ?? false) $cat_bonus += 1;
        if ($id['is_appliance']  ?? false) $cat_bonus += 1;

        $hazard_penalty = count($id['hazard_flags'] ?? []) * -1;
        $total          = max(0, min(12, $mat_score + $cond_mod + $cat_bonus + $hazard_penalty));

        if ($total >= 8)      { $recyclability = 'High Value';      $verdict = 'worth_recycling'; $label = '✅ High Value — Worth Recycling!'; $cls = 'accept'; }
        elseif ($total >= 4)  { $recyclability = 'Medium Value';    $verdict = 'worth_recycling'; $label = '✅ Worth Recycling';               $cls = 'accept'; }
        elseif ($total >= 1)  { $recyclability = 'Low Value';       $verdict = 'low_value';       $label = '⚠️ Low Scrap Value';              $cls = 'warn';   }
        else                  { $recyclability = 'Not Recyclable';  $verdict = 'not_accepted';    $label = '❌ Not in GreenLoop Program';      $cls = 'reject'; }

        $accepted = ($verdict === 'worth_recycling');
        $coins    = $accepted ? max(5, $total * 3) : 0;

        return [
            'total'              => $total,
            'mat_score'          => $mat_score,
            'cond_mod'           => $cond_mod,
            'cat_bonus'          => $cat_bonus,
            'hazard_penalty'     => $hazard_penalty,
            'recyclability'      => $recyclability,
            'verdict'            => $verdict,
            'verdict_label'      => $label,
            'verdict_class'      => $cls,
            'accepted'           => $accepted,
            'coins'              => $coins,
            'valuable_materials' => $valuable,
        ];
    }
}

// ═══════════════════════════════════════════════════════════════
// EXPLAINER ENGINE — text-only, no AI call (avoids extra latency)
// ═══════════════════════════════════════════════════════════════
class ExplainerEngine {
    public static function generate(array $id, array $scored, bool $rule_based): string {
        $name   = $id['item_name'] ?? 'this item';
        $coins  = $scored['coins'];
        $mats   = implode(' and ', array_slice($scored['valuable_materials'], 0, 2));
        $hazards = $id['hazard_flags'] ?? [];

        if ($scored['accepted']) {
            $mat_note = $mats ? " It contains {$mats}, which has good scrap value." : '';
            $suffix   = $rule_based ? ' (Rule-based estimate)' : '';
            return "Your {$name} qualifies for the GreenLoop program — salamat for recycling!{$mat_note} Report it now to earn ~{$coins} Green Coins once verified.{$suffix}";
        }

        if (!empty($hazards)) {
            $flags = implode(', ', array_map(fn($h) => str_replace('_', ' ', $h), $hazards));
            return "Your {$name} contains hazardous materials ({$flags}) and requires special disposal. Please contact our team for safe handling instructions.";
        }

        return "Unfortunately your {$name} doesn't have enough recoverable material for our standard program. Items with copper wiring, aluminum frames, or working motors are always welcome — try those next!";
    }
}

// ═══════════════════════════════════════════════════════════════
// RESPONSE FORMATTER
// ═══════════════════════════════════════════════════════════════
class ResponseFormatter {
    public static function format(array $id, array $scored, string $explanation, string $original_desc, string $mode, bool $fallback_used): array {
        return [
            // Item data
            'item_name'         => $id['item_name'],
            'condition'         => $id['condition'],
            'visible_materials' => $id['visible_materials'],
            'recoverable_parts' => $id['possible_recoverable_parts'],
            'hazard_flags'      => $id['hazard_flags'],
            'ai_confidence'     => $id['confidence'],
            'notes'             => $id['notes'],
            'is_electrical'     => $id['is_electrical'],
            'is_appliance'      => $id['is_appliance'],
            'is_automotive'     => $id['is_automotive'],

            // Scoring
            'material_score'     => $scored['mat_score'],
            'condition_modifier' => $scored['cond_mod'],
            'category_bonus'     => $scored['cat_bonus'],
            'hazard_penalty'     => $scored['hazard_penalty'],
            'total_score'        => $scored['total'],
            'recyclability'      => $scored['recyclability'],
            'verdict'            => $scored['verdict'],
            'verdict_label'      => $scored['verdict_label'],
            'verdict_class'      => $scored['verdict_class'],
            'valuable_materials' => $scored['valuable_materials'],

            // Reward
            'accepted'           => $scored['accepted'],
            'green_coins'        => $scored['coins'],
            'message'            => $explanation,

            // Meta
            'mode'               => $mode,
            'fallback_used'      => $fallback_used,
            'timestamp'          => date('c'),
            'unit'               => self::determineUnit($id['item_name']),

            // Debug info — shows in browser devtools, won't affect UI
            '_debug'             => DebugLog::all(),
        ];
    }

    private static function determineUnit(string $name): string {
        $n = strtolower($name);
        if (str_contains($n, 'wire') || str_contains($n, 'cable')) return 'kg';
        return 'piece';
    }
}

// ── EXECUTE ──────────────────────────────────────────────────
try {
    route();
} catch (Throwable $e) {
    error_log('[GreenLoop] FATAL: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'error'         => 'Internal server error: ' . $e->getMessage(),
        'fallback_used' => true,
        'timestamp'     => date('c'),
        '_debug'        => DebugLog::all(),
    ]);
}