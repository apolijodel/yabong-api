<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$data = json_decode(file_get_contents('php://input'), true);
$id   = (int)($data['id'] ?? 0);
$all  = (bool)($data['all'] ?? false);

$db = getDB();

if ($all) {
    $adminId = (int)($data['adminId'] ?? 1);
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE admin_id = ? OR admin_id IS NULL");
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $stmt->close();
} elseif ($id) {
    $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

echo json_encode(['success' => true]);
$db->close();
