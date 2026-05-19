<?php
/**
 * YABONG DB Migration
 * Run once: http://localhost/yabong-api/migrate.php
 *
 * 1. Adds new columns to feedback (suggestions, likert_*)
 * 2. Extends sentiment ENUMs to include 'Mixed'
 * 3. Inserts new feedback categories (INSERT IGNORE)
 * 4. Seeds likert scores on all existing rows that have likert_timeliness = 0
 */
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

$db  = getDB();
$log = [];

// ── helpers ───────────────────────────────────────────────────────────────────

function run($db, &$log, $label, $sql) {
    if ($db->query($sql)) {
        $log[] = "OK  $label";
    } else {
        $log[] = "ERR $label — " . $db->error;
    }
}

function colExists($db, $table, $col) {
    $r = $db->query(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = '$table'
           AND COLUMN_NAME  = '$col'"
    );
    return $r && $r->num_rows > 0;
}

// ── 1. Add new columns to feedback ───────────────────────────────────────────

if (!colExists($db, 'feedback', 'suggestions')) {
    run($db, $log, 'ADD feedback.suggestions',
        "ALTER TABLE feedback ADD COLUMN suggestions TEXT DEFAULT NULL AFTER description");
} else {
    $log[] = "SKIP feedback.suggestions (already exists)";
}

if (!colExists($db, 'feedback', 'likert_timeliness')) {
    run($db, $log, 'ADD feedback.likert_timeliness',
        "ALTER TABLE feedback ADD COLUMN likert_timeliness TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER suggestions");
} else {
    $log[] = "SKIP feedback.likert_timeliness (already exists)";
}

if (!colExists($db, 'feedback', 'likert_client_handling')) {
    run($db, $log, 'ADD feedback.likert_client_handling',
        "ALTER TABLE feedback ADD COLUMN likert_client_handling TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER likert_timeliness");
} else {
    $log[] = "SKIP feedback.likert_client_handling (already exists)";
}

if (!colExists($db, 'feedback', 'likert_quality')) {
    run($db, $log, 'ADD feedback.likert_quality',
        "ALTER TABLE feedback ADD COLUMN likert_quality TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER likert_client_handling");
} else {
    $log[] = "SKIP feedback.likert_quality (already exists)";
}

if (!colExists($db, 'feedback', 'likert_overall')) {
    run($db, $log, 'ADD feedback.likert_overall',
        "ALTER TABLE feedback ADD COLUMN likert_overall TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER likert_quality");
} else {
    $log[] = "SKIP feedback.likert_overall (already exists)";
}

// ── 2. Extend sentiment ENUMs ─────────────────────────────────────────────────

run($db, $log, 'MODIFY feedback.sentiment_label ENUM',
    "ALTER TABLE feedback
     MODIFY COLUMN sentiment_label
       ENUM('Positive','Neutral','Negative','Mixed') NOT NULL DEFAULT 'Neutral'");

run($db, $log, 'MODIFY sentiment_analysis_logs.predicted_label ENUM',
    "ALTER TABLE sentiment_analysis_logs
     MODIFY COLUMN predicted_label
       ENUM('Positive','Neutral','Negative','Mixed') NOT NULL");

// ── 3. Insert new categories ──────────────────────────────────────────────────

$newCategories = [
    ['Canteen',                 'Food quality, menu, and cafeteria services'],
    ['Guidance Counseling',     'Counseling services, mental health, and academic advising'],
    ['Student Affairs',         'Student organizations, OSA, and student welfare'],
    ['Bazaar',                  'Campus trade fair and student marketplace events'],
    ['Blood Donation Drive',    'Campus blood donation and health drive activities'],
    ['Buwan ng Wika',           'Filipino language month activities and programs'],
    ['College Events',          'Department days, inter-college programs, and college fairs'],
    ['Freshmen Orientation',    'New student orientation and welcome week programs'],
    ['Graduation',              'Commencement exercises and graduation ceremonies'],
    ['Leadership Summit',       'Student leader training, summits, and workshops'],
    ['Research Congress',       'Research forums, thesis presentations, and symposiums'],
    ['Seminars / Career Talks', 'Industry talks, career guidance, and professional seminars'],
    ['Sports Festival',         'Intramurals, athletic competitions, and sports events'],
    ['University Week',         'Founding anniversary and university-wide celebrations'],
];

$stmt = $db->prepare(
    "INSERT IGNORE INTO feedback_categories (name, description, is_active) VALUES (?, ?, 1)"
);
foreach ($newCategories as [$name, $desc]) {
    $stmt->bind_param('ss', $name, $desc);
    if ($stmt->execute()) {
        $affected = $db->affected_rows;
        $log[] = ($affected > 0 ? "INS " : "DUP ") . "category: $name";
    } else {
        $log[] = "ERR category: $name — " . $stmt->error;
    }
}
$stmt->close();

// ── 4. Seed likert scores on existing rows (likert_timeliness = 0) ────────────
// Scores are derived from the existing sentiment label so they are coherent:
//   Positive → 3-5   |   Negative → 1-3   |   Neutral/Mixed → 2-4

run($db, $log, 'SEED likert scores for existing feedback',
    "UPDATE feedback
     SET
       likert_timeliness = CASE
         WHEN sentiment_label = 'Positive' THEN 3 + FLOOR(RAND() * 3)
         WHEN sentiment_label = 'Negative' THEN 1 + FLOOR(RAND() * 3)
         ELSE 2 + FLOOR(RAND() * 3)
       END,
       likert_client_handling = CASE
         WHEN sentiment_label = 'Positive' THEN 3 + FLOOR(RAND() * 3)
         WHEN sentiment_label = 'Negative' THEN 1 + FLOOR(RAND() * 3)
         ELSE 2 + FLOOR(RAND() * 3)
       END,
       likert_quality = CASE
         WHEN sentiment_label = 'Positive' THEN 3 + FLOOR(RAND() * 3)
         WHEN sentiment_label = 'Negative' THEN 1 + FLOOR(RAND() * 3)
         ELSE 2 + FLOOR(RAND() * 3)
       END,
       likert_overall = CASE
         WHEN sentiment_label = 'Positive' THEN 3 + FLOOR(RAND() * 3)
         WHEN sentiment_label = 'Negative' THEN 1 + FLOOR(RAND() * 3)
         ELSE 2 + FLOOR(RAND() * 3)
       END
     WHERE likert_timeliness = 0");

$affected = $db->affected_rows;
$log[] = "    → $affected rows updated with likert scores";

// ── Done ──────────────────────────────────────────────────────────────────────

$db->close();

echo "YABONG Migration\n";
echo str_repeat("=", 50) . "\n";
echo implode("\n", $log) . "\n";
echo str_repeat("=", 50) . "\n";
echo "Done.\n";
