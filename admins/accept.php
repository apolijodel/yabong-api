<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$data      = json_decode(file_get_contents('php://input'), true);
$adminId   = (int)($data['adminId']  ?? 0);
$actorId   = (int)($data['actorId']  ?? 1);

if (!$adminId) { echo json_encode(['success' => false, 'message' => 'Admin ID required.']); exit; }

$db   = getDB();
$stmt = $db->prepare("
    UPDATE admins SET status_id = (SELECT id FROM admin_statuses WHERE status_name = 'Active')
    WHERE id = ?
");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$stmt->close();

// Log
$nameRow = $db->query("SELECT full_name FROM admins WHERE id = $adminId LIMIT 1")->fetch_assoc();
$name    = $nameRow['full_name'] ?? "Admin #$adminId";
$desc    = "Accepted admin account for $name";
$db->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, target_type, target_id, description) VALUES (?, 'Accepted Admin', 'Admin', ?, ?)")
   ->execute([$actorId, $adminId, $desc]);

echo json_encode(['success' => true]);
$db->close();
