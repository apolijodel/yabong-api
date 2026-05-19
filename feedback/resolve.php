<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$data       = json_decode(file_get_contents('php://input'), true);
$feedbackId = (int)($data['feedbackId'] ?? 0);
$adminId    = (int)($data['adminId']    ?? 1);
$notes      = trim($data['notes']       ?? '');

if (!$feedbackId) {
    echo json_encode(['success' => false, 'message' => 'Feedback ID required.']);
    exit;
}

$db   = getDB();
$stmt = $db->prepare("
    UPDATE feedback
    SET resolved_at = NOW(), resolved_by_admin_id = ?, resolution_notes = ?
    WHERE id = ? AND resolved_at IS NULL
");
$stmt->bind_param('isi', $adminId, $notes, $feedbackId);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Already resolved or not found.']);
    $stmt->close();
    $db->close();
    exit;
}
$stmt->close();

// Fetch tracking code + category for descriptive log
$info = $db->query("
    SELECT f.tracking_code, COALESCE(fc.name, 'Others') AS category, f.sentiment_label
    FROM feedback f
    LEFT JOIN feedback_categories fc ON fc.id = f.category_id
    WHERE f.id = $feedbackId
    LIMIT 1
")->fetch_assoc();
$desc = $info
    ? "Resolved feedback {$info['tracking_code']} ({$info['category']}, {$info['sentiment_label']})"
    : "Resolved feedback #$feedbackId";

$logStmt = $db->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, target_type, target_id, description) VALUES (?, 'Resolved Feedback', 'Feedback', ?, ?)");
$logStmt->bind_param('iis', $adminId, $feedbackId, $desc);
$logStmt->execute();
$logStmt->close();

// Notification
$adminName = "Admin";
$nameRow = $db->query("SELECT full_name FROM admins WHERE id = $adminId LIMIT 1")->fetch_assoc();
if ($nameRow) $adminName = $nameRow['full_name'];
$msg  = "You ($adminName) marked Feedback #$feedbackId as resolved.";
$stmt = $db->prepare("INSERT INTO notifications (admin_id, type, message) VALUES (?, 'resolved', ?)");
$stmt->bind_param('is', $adminId, $msg);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
$db->close();
