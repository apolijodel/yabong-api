<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$data = json_decode(file_get_contents('php://input'), true);

$embedId       = $data['embedId']      ?? '';
$description   = trim($data['description'] ?? '');
$suggestionsRaw = trim($data['suggestions'] ?? '');
$suggestions   = $suggestionsRaw !== '' ? $suggestionsRaw : null;
$isAnonymous   = (int)($data['isAnonymous'] ?? 0);
$includeName   = (int)($data['includeName'] ?? 0);
$submitterName = (!$isAnonymous && $includeName) ? trim($data['submitterName'] ?? '') : null;
$categoryName  = $data['category']     ?? 'Others';
$deviceInfo    = $data['deviceInfo']   ?? [];
$knnClient     = $data['knn']          ?? [];   // client-provided KNN (UI hint only)
$trackingCode  = $data['trackingCode'] ?? '';
$likert        = $data['likert']       ?? [];

$likertTimeliness     = isset($likert['timeliness'])     && $likert['timeliness']     > 0 ? (int)$likert['timeliness']     : 0;
$likertClientHandling = isset($likert['clientHandling']) && $likert['clientHandling'] > 0 ? (int)$likert['clientHandling'] : 0;
$likertQuality        = isset($likert['quality'])        && $likert['quality']        > 0 ? (int)$likert['quality']        : 0;
$likertOverall        = isset($likert['overall'])        && $likert['overall']        > 0 ? (int)$likert['overall']        : 0;

if (!$description || !$trackingCode) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
    exit;
}

// ── ML helpers (server-side Python calls) ─────────────────────────────────────

function mlPost(string $endpoint, array $body): ?array {
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => json_encode($body),
        'timeout'       => 5,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents("http://localhost:5001{$endpoint}", false, $ctx);
    if (!$raw) return null;
    return json_decode($raw, true) ?: null;
}

// Keyword-based negative detection — runs as PHP fallback and as a post-ML safety net.
// Only covers unambiguous complaint words so false positives stay near zero.
function isObviouslyNegative(string $text): bool {
    return (bool) preg_match(
        '/\b(bad|terrible|awful|horrible|poor|disgusting|worst|unacceptable|rude|nasty'
        . '|broken|defective|faulty|slow|delayed|unreliable|inadequate|unavailable'
        . '|disconnected|inefficient|unresponsive|dirty|smelly|overcrowded|overpriced'
        . '|mabagal|sira|sirang|pangit|masamang|bulok|pakiayos|problema|hindi\s+gumagana)\b/i',
        $text
    );
}

// PHP fallback used when the Python server is unreachable.
function phpSentiment(string $text): array {
    $neg = isObviouslyNegative($text);
    return [
        'label'            => $neg ? 'Negative' : 'Neutral',
        'confidence'       => $neg ? 0.75 : 0.50,
        'positiveScore'    => 0.0,
        'neutralScore'     => $neg ? 0.0 : 1.0,
        'negativeScore'    => $neg ? 0.75 : 0.0,
        'processingTimeMs' => 0,
    ];
}

// ── Run ML ────────────────────────────────────────────────────────────────────

$sentimentResult = mlPost('/analyze', ['text' => $description]);
if (!$sentimentResult || !isset($sentimentResult['label'])) {
    $sentimentResult = phpSentiment($description);
}

$knnResult = mlPost('/predict', ['text' => $description]);
// Fall back to client-provided KNN if Python is down
if (!$knnResult && $knnClient) $knnResult = $knnClient;

// ── DB setup ──────────────────────────────────────────────────────────────────

$db = getDB();

// Detect whether migration has been run (new columns present)
$migrated = $db->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'feedback'
       AND COLUMN_NAME  = 'likert_timeliness'"
)->num_rows > 0;

// Resolve embed system ID
$stmt = $db->prepare("SELECT id FROM embed_systems WHERE embed_id = ? LIMIT 1");
$stmt->bind_param('s', $embedId);
$stmt->execute();
$embedRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
$embedSystemId = $embedRow ? $embedRow['id'] : null;

// Resolve category IDs
$getCatId = function($name) use ($db) {
    $s = $db->prepare("SELECT id FROM feedback_categories WHERE name = ? LIMIT 1");
    $s->bind_param('s', $name);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    $s->close();
    return $r ? $r['id'] : null;
};
$categoryId          = $getCatId($categoryName);
$suggestedCategoryId = $knnResult ? $getCatId($knnResult['category'] ?? $categoryName) : $categoryId;

$sentimentLabel = $sentimentResult['label']      ?? 'Neutral';
$sentimentScore = $sentimentResult['confidence'] ?? 0;
$knnConfidence  = $knnResult['confidence']       ?? 0;

$validLabels = ['Positive', 'Negative', 'Neutral', 'Mixed'];
if (!in_array($sentimentLabel, $validLabels)) $sentimentLabel = 'Neutral';

// Mixed → resolve by comparing raw scores
if ($sentimentLabel === 'Mixed') {
    $negRaw = (float)($sentimentResult['negativeScore'] ?? 0.0);
    $posRaw = (float)($sentimentResult['positiveScore'] ?? 0.0);
    $sentimentLabel = ($negRaw >= $posRaw) ? 'Negative' : 'Neutral';
}

// Before migration the ENUM doesn't have Mixed — map it to Neutral
if (!$migrated && $sentimentLabel === 'Mixed') $sentimentLabel = 'Neutral';

// Post-ML override: if the feedback text is obviously negative but the ML returned
// Neutral or Positive, force Negative. The Likert scale is not considered.
if ($sentimentLabel !== 'Negative' && isObviouslyNegative($description)) {
    $sentimentLabel = 'Negative';
    $sentimentScore = max((float)$sentimentScore, 0.75);
}

$deviceName = $deviceInfo['deviceName']     ?? null;
$deviceType = $deviceInfo['deviceType']     ?? null;
$os         = $deviceInfo['os']             ?? null;
$browser    = $deviceInfo['browserName']    ?? null;
$browserVer = $deviceInfo['browserVersion'] ?? null;
$ip         = $deviceInfo['ipAddress']      ?? null;
$hostname   = $deviceInfo['hostname']       ?? null;

// ── INSERT ─────────────────────────────────────────────────────────────────────
if ($migrated) {
    $stmt = $db->prepare("
        INSERT INTO feedback
          (tracking_code, category_id, suggested_category_id, embed_system_id,
           submitter_name, is_anonymous, include_name, description, suggestions,
           likert_timeliness, likert_client_handling, likert_quality, likert_overall,
           sentiment_label, sentiment_score, predicted_category_confidence,
           device_name, device_type, operating_system,
           browser_name, browser_version, ip_address, hostname)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->bind_param(
        'siiisiissiiiisddsssssss',
        $trackingCode, $categoryId, $suggestedCategoryId, $embedSystemId,
        $submitterName, $isAnonymous, $includeName, $description, $suggestions,
        $likertTimeliness, $likertClientHandling, $likertQuality, $likertOverall,
        $sentimentLabel, $sentimentScore, $knnConfidence,
        $deviceName, $deviceType, $os,
        $browser, $browserVer, $ip, $hostname
    );
} else {
    $stmt = $db->prepare("
        INSERT INTO feedback
          (tracking_code, category_id, suggested_category_id, embed_system_id,
           submitter_name, is_anonymous, include_name, description,
           sentiment_label, sentiment_score, predicted_category_confidence,
           device_name, device_type, operating_system,
           browser_name, browser_version, ip_address, hostname)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->bind_param(
        'siiisiissddsssssss',
        $trackingCode, $categoryId, $suggestedCategoryId, $embedSystemId,
        $submitterName, $isAnonymous, $includeName, $description,
        $sentimentLabel, $sentimentScore, $knnConfidence,
        $deviceName, $deviceType, $os,
        $browser, $browserVer, $ip, $hostname
    );
}

$stmt->execute();
$feedbackId = $db->insert_id;
$stmt->close();

// Log sentiment
if ($feedbackId) {
    $posScore = $sentimentResult['positiveScore'] ?? 0;
    $neuScore = $sentimentResult['neutralScore']  ?? 0;
    $negScore = $sentimentResult['negativeScore'] ?? 0;
    $conf     = $sentimentResult['confidence']         ?? 0;
    $ms       = (int)($sentimentResult['processingTimeMs'] ?? 0);
    $input    = $description;
    $stmt = $db->prepare("
        INSERT INTO sentiment_analysis_logs
          (feedback_id, input_text, predicted_label,
           positive_score, neutral_score, negative_score,
           confidence_score, processing_time_ms)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmt->bind_param('issddddi', $feedbackId, $input, $sentimentLabel,
                      $posScore, $neuScore, $negScore, $conf, $ms);
    $stmt->execute();
    $stmt->close();
}

// Log K-NN prediction
if ($feedbackId && $knnResult && $suggestedCategoryId) {
    $conf     = $knnResult['confidence']           ?? 0;
    $keywords = implode(', ', array_column($knnResult['topK'] ?? [], 'category'));
    $stmt = $db->prepare("
        INSERT INTO category_prediction_logs
          (feedback_id, predicted_category_id, confidence_score, keywords_detected)
        VALUES (?,?,?,?)
    ");
    $stmt->bind_param('iids', $feedbackId, $suggestedCategoryId, $conf, $keywords);
    $stmt->execute();
    $stmt->close();
}

// Increment total_submissions
if ($embedSystemId) {
    $stmt = $db->prepare("UPDATE embed_systems SET total_submissions = total_submissions + 1 WHERE id = ?");
    $stmt->bind_param('i', $embedSystemId);
    $stmt->execute();
    $stmt->close();
}

// Create notification
$msg = "New {$sentimentLabel} feedback submitted — {$categoryName}.";
$stmt = $db->prepare("INSERT INTO notifications (admin_id, type, message) VALUES (1, 'feedback', ?)");
$stmt->bind_param('s', $msg);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true, 'trackingCode' => $trackingCode]);
$db->close();
