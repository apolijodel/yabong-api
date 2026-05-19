<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$adminId = (int)($_GET['adminId'] ?? 1);
$db      = getDB();

$stmt = $db->prepare("
    SELECT id, type, message, is_read, created_at
    FROM notifications
    WHERE admin_id = ? OR admin_id IS NULL
    ORDER BY created_at DESC
    LIMIT 50
");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$rows = $stmt->get_result();

$data = [];
while ($row = $rows->fetch_assoc()) {
    $data[] = [
        'id'        => (int)$row['id'],
        'type'      => $row['type'],
        'message'   => $row['message'],
        'isRead'    => (bool)$row['is_read'],
        'timestamp' => date('m/d/y g:iA', strtotime($row['created_at'])),
    ];
}
$stmt->close();

echo json_encode(['success' => true, 'data' => $data]);
$db->close();
