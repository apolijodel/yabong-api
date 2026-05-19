<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$data         = json_decode(file_get_contents('php://input'), true);
$feedbackId   = (int)($data['feedbackId']   ?? 0);
$adminId      = (int)($data['adminId']      ?? 0);
$assignedById = (int)($data['assignedById'] ?? 0);

if (!$feedbackId || !$adminId) {
    echo json_encode(['success' => false, 'message' => 'feedbackId and adminId are required.']);
    exit;
}

$db = getDB();

// Add assigned_to column if it doesn't exist yet (split into two statements to avoid FK issues)
$colExists = $db->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'feedback'
       AND COLUMN_NAME  = 'assigned_to'"
)->num_rows > 0;

if (!$colExists) {
    $db->query("ALTER TABLE feedback ADD COLUMN assigned_to INT NULL DEFAULT NULL");
}

$hasByCol = $db->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'feedback'
       AND COLUMN_NAME  = 'assigned_by'"
)->num_rows > 0;

if (!$hasByCol) {
    $db->query("ALTER TABLE feedback ADD COLUMN assigned_by INT NULL DEFAULT NULL");
}

// Verify admin exists
$aStmt = $db->prepare("SELECT id, full_name FROM admins WHERE id = ? LIMIT 1");
$aStmt->bind_param('i', $adminId);
$aStmt->execute();
$admin = $aStmt->get_result()->fetch_assoc();
$aStmt->close();

if (!$admin) {
    echo json_encode(['success' => false, 'message' => 'Admin not found.']);
    $db->close();
    exit;
}

// Verify feedback exists
$fStmt = $db->prepare("SELECT id FROM feedback WHERE id = ? LIMIT 1");
$fStmt->bind_param('i', $feedbackId);
$fStmt->execute();
$fbExists = $fStmt->get_result()->fetch_assoc();
$fStmt->close();

if (!$fbExists) {
    echo json_encode(['success' => false, 'message' => 'Feedback not found.']);
    $db->close();
    exit;
}

// Fetch assigner name if provided
$assignerName = null;
if ($assignedById) {
    $byStmt = $db->prepare("SELECT full_name FROM admins WHERE id = ? LIMIT 1");
    $byStmt->bind_param('i', $assignedById);
    $byStmt->execute();
    $byRow = $byStmt->get_result()->fetch_assoc();
    $byStmt->close();
    $assignerName = $byRow['full_name'] ?? null;
}

// Update — always succeeds even if same admin (re-assignment allowed)
$stmt = $db->prepare("UPDATE feedback SET assigned_to = ?, assigned_by = ? WHERE id = ?");
$stmt->bind_param('iii', $adminId, $assignedById, $feedbackId);
$stmt->execute();
$stmt->close();

// Fetch tracking code + category for descriptive log
$info = $db->query("
    SELECT f.tracking_code, COALESCE(fc.name, 'Others') AS category
    FROM feedback f
    LEFT JOIN feedback_categories fc ON fc.id = f.category_id
    WHERE f.id = $feedbackId
    LIMIT 1
")->fetch_assoc();
$desc = $info
    ? "Assigned feedback {$info['tracking_code']} ({$info['category']}) to {$admin['full_name']}"
    : "Assigned feedback #$feedbackId to {$admin['full_name']}";

$db->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, target_type, target_id, description) VALUES (?, 'Assigned Feedback', 'Feedback', ?, ?)")
   ->execute([$adminId, $feedbackId, $desc]);

// Notify the assigned admin
if ($assignedById && $assignedById === $adminId) {
    $msg = "You assigned yourself to feedback #$feedbackId.";
} elseif ($assignerName) {
    $msg = "$assignerName assigned you to feedback #$feedbackId.";
} else {
    $msg = "You have been assigned to feedback #$feedbackId.";
}
$nStmt = $db->prepare("INSERT INTO notifications (admin_id, type, message) VALUES (?, 'assigned', ?)");
$nStmt->bind_param('is', $adminId, $msg);
$nStmt->execute();
$nStmt->close();

echo json_encode(['success' => true, 'assignedTo' => $admin['full_name'], 'assignedBy' => $assignerName]);
$db->close();
