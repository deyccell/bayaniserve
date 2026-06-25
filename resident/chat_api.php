<?php
/**
 * BayaniServe - Chat API (Ollama streaming, medicine-chatbot model)
 *
 * Fixes applied vs previous version:
 *  - CURLOPT_RETURNTRANSFER and CURLOPT_POSTFIELDS now present in callOllamaModel()
 *  - Model name pulled from OLLAMA_MODEL constant (medicine-chatbot), not hardcoded
 *  - OLLAMA_URL constant used everywhere instead of a bare string
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

session_name('bayaniserve_resident_session');
session_start();

// CPU-only inference is slow (~1-2 tokens/sec on typical hardware), so a
// real response can take 30-90+ seconds. Override both the script timeout
// and the socket timeout so PHP doesn't kill the connection mid-generation,
// regardless of php.ini's max_execution_time setting.
set_time_limit(300);
ini_set('default_socket_timeout', 300);

require_once __DIR__ . '/../config/database.php';

$message   = trim($_REQUEST['message'] ?? '');
$sessionId = $_REQUEST['session_id'] ?? session_id();

if ($message === '') {
    echo "data: " . json_encode(['reply' => 'Please type a message.', 'done' => true]) . "\n\n";
    exit;
}

$pdo = getDB();

// Save resident message
$pdo->prepare("INSERT INTO chat_messages (session_id, sender, message) VALUES (?, 'resident', ?)")
    ->execute([$sessionId, $message]);

// ================================================================
// STEP 1: Pull REAL stock data from DB
// ================================================================
$stockRows = $pdo->query("
    SELECT h.barangay_name, m.name AS medicine, i.quantity, i.status
    FROM inventory i
    JOIN health_stations h ON h.id = i.station_id
    JOIN medicines m       ON m.id = i.medicine_id
    ORDER BY h.barangay_name, m.name
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($stockRows)) {
    $stockContext = "CURRENT STOCK: No medicines have been added to the inventory yet. "
                  . "Tell the resident honestly that no stock data is available.";
} else {
    $stockContext = "CURRENT STOCK DATA (from the live database):\n";
    $lastBrgy = '';
    foreach ($stockRows as $r) {
        if ($r['barangay_name'] !== $lastBrgy) {
            $stockContext .= "\n[" . $r['barangay_name'] . "]\n";
            $lastBrgy = $r['barangay_name'];
        }
        $statusLabel = $r['status'] === 'in_stock'
            ? 'Available (' . $r['quantity'] . ' units)'
            : ($r['status'] === 'low_stock'
                ? 'Low stock (' . $r['quantity'] . ' units)'
                : 'Out of stock (0 units)');
        $stockContext .= "  - " . $r['medicine'] . ": " . $statusLabel . "\n";
    }
}

// ================================================================
// STEP 2: Build strict multi-dialect system prompt
// ================================================================
$systemPrompt = <<<PROMPT
You are "Ate Inday", the friendly, local health station assistant for BayaniServe in Negros Occidental. 
YOUR MISSION: Help residents check medicine stocks naturally. Talk exactly like a compassionate, real human healthcare worker.

STRICT CONVERSATIONAL DIALECT RULES:
1. NO MIXING: Never mix Tagalog, Bisaya, and Hiligaynon phrases in a single response. Use pure conversational dialects.
2. HILIGAYNON / ILONGGO (Primary Local Dialect):
   - If the user asks "ara subong", "sa hilamonan", "nga mga bulong", or uses Ilonggo terms, reply in pure, natural Hiligaynon.
   - Use natural connectors like "bale", "galing", "pa", "na".
   - Instead of "May magagamit", use "Ara subong", "May ari kita diri", or "May natabilin pa nga...".
   - Out of stock: "Pasensya gid, subong wala gid kita sing stock sang..." or "Ubos gid subong ang...".
   - Example tone: "Sa Brgy. Hilamonan, may ara pa kita natabilin nga 95 units sang Amlodipine 5mg kag 24 units sang Amoxicillin. Gusto mo ipareserve ini para sa imo?"
3. BISAYA / CEBUANO:
   - If the user uses Cebuano words ("naa ba", "unsa", "karon"), reply in clear Bisaya.
   - Out of stock: "Hurot na jud ang stock sa..." or "Walay stock subong sa...".
4. TAGALOG:
   - If the user writes in Tagalog, reply in casual, professional Tagalog.
5. CONCISE & WARM: Keep your responses to 2 or 3 sentences maximum. Address the exact location requested right away. Do not look or sound like a data spreadsheet.

$stockContext
PROMPT;

// ================================================================
// STEP 3: Stream response
// ================================================================
$fullReply = streamOllamaModel($systemPrompt, $message);

if (!empty($fullReply)) {
    $pdo->prepare("INSERT INTO chat_messages (session_id, sender, message) VALUES (?, 'ai', ?)")
        ->execute([$sessionId, $fullReply]);
}

// ================================================================
// STEP 4: Intent extraction
// ================================================================
$extractPrompt =
    "Analyze this message from a barangay health station resident and convert it into the requested JSON schema.\n" .
    "CRITICAL: Output ONLY valid JSON. No code fences, no markdown, no extra text.\n" .
    'JSON Schema: {"intent":"reserve"|"request"|"none","resident_name":null,"mobile_number":null,"medicine_name":null,"barangay":null,"pickup_date":null}' .
    "\n\nMessage: \"" . addslashes($message) . "\"";

$extractRaw  = callOllamaModel('You extract structured information and map it to valid JSON schemas.', $extractPrompt);
$extractRaw  = preg_replace('/```json|```/', '', $extractRaw);
$intent      = json_decode(trim($extractRaw), true);
$actionTaken = null;

if (is_array($intent) && in_array($intent['intent'] ?? '', ['reserve', 'request'])) {
    $stationId = null;
    if (!empty($intent['barangay'])) {
        $s = $pdo->prepare("SELECT id FROM health_stations WHERE barangay_name LIKE ?");
        $s->execute(['%' . $intent['barangay'] . '%']);
        $stationId = $s->fetchColumn();
    }

    $residentName = !empty($intent['resident_name']) ? $intent['resident_name'] : 'Unknown (via chat)';
    $mobile       = !empty($intent['mobile_number'])  ? $intent['mobile_number']  : null;
    $medicineName = !empty($intent['medicine_name'])  ? $intent['medicine_name']  : null;

    if ($stationId && $medicineName) {
        if ($intent['intent'] === 'reserve') {
            $s = $pdo->prepare("SELECT id FROM medicines WHERE name LIKE ?");
            $s->execute(['%' . $medicineName . '%']);
            $medicineId = $s->fetchColumn();

            if ($medicineId) {
                $pdo->prepare("
                    INSERT INTO reservations
                        (resident_name, mobile_number, station_id, medicine_id, pickup_date, source, raw_message)
                    VALUES (?, ?, ?, ?, ?, 'chatbot', ?)
                ")->execute([$residentName, $mobile, $stationId, $medicineId, $intent['pickup_date'] ?: null, $message]);
                $actionTaken = "Reservation recorded for $medicineName.";
            }
        } elseif ($intent['intent'] === 'request') {
            $pdo->prepare("
                INSERT INTO medicine_requests
                    (resident_name, mobile_number, station_id, medicine_name, source, raw_message)
                VALUES (?, ?, ?, ?, 'chatbot', ?)
            ")->execute([$residentName, $mobile, $stationId, $medicineName, $message]);
            $actionTaken = "Request for $medicineName recorded.";
        }
    }
}

echo "data: " . json_encode(['done' => true, 'action_taken' => $actionTaken]) . "\n\n";


// ================================================================
// STREAMING — uses file-based stream (works with Ollama /api/chat)
// ================================================================
function streamOllamaModel(string $system, string $userPrompt): string {
    $payload = json_encode([
        'model'   => OLLAMA_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userPrompt]
        ],
        'stream'  => true,
        'options' => [
            'temperature' => 0.1,
            'num_predict' => 300
        ]
    ]);

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 300,
            'ignore_errors' => true
        ]
    ]);

    $stream = @fopen(OLLAMA_URL, 'r', false, $context);
    if (!$stream) {
        echo "data: " . json_encode(['reply' => "Hindi makakonekta sa AI model. Siguraduhin na tumatakbo ang Ollama.", 'done' => true]) . "\n\n";
        return "";
    }

    $accumulatedText = "";
    while (!feof($stream)) {
        $line = fgets($stream);
        if (!empty(trim($line))) {
            $data = json_decode($line, true);
            if (isset($data['message']['content'])) {
                $token = $data['message']['content'];
                $accumulatedText .= $token;
                echo "data: " . json_encode(['reply' => $token, 'done' => false]) . "\n\n";
                ob_flush();
                flush();
            }
            if (isset($data['done']) && $data['done'] === true) break;
        }
    }
    fclose($stream);
    return $accumulatedText;
}

// ================================================================
// BLOCKING call — for JSON entity extraction
// ================================================================
function callOllamaModel(string $system, string $userPrompt): string {
    $payload = json_encode([
        'model'   => OLLAMA_MODEL,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userPrompt]
        ],
        'stream'  => false,
        'format'  => 'json',
        'options' => [
            'temperature' => 0.0,
            'num_predict' => 150
        ]
    ]);

    $ch = curl_init(OLLAMA_URL);
    curl_setopt($ch, CURLOPT_IPRESOLVE,      CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT,        300);

    $res = curl_exec($ch);
    if (curl_errno($ch)) {
        curl_close($ch);
        return "{}";
    }
    curl_close($ch);

    $data = json_decode($res, true);
    return trim($data['message']['content'] ?? '{}');
}