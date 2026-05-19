<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$db   = getDB();
$rows = $db->query("
    SELECT a.id, a.full_name, a.email, a.profile_image, a.position, a.created_at, ast.status_name AS status
    FROM admins a
    JOIN admin_statuses ast ON ast.id = a.status_id
    ORDER BY a.created_at ASC
");

$data = [];
while ($row = $rows->fetch_assoc()) {
    $data[] = [
        'id'           => $row['id'],
        'name'         => $row['full_name'],
        'email'        => $row['email'],
        'profile_image'=> $row['profile_image'],
        'position'     => $row['position'] ?? '',
        'status'       => $row['status'],
        'joinedDate'   => date('m/d/y', strtotime($row['created_at'])),
    ];
}

echo json_encode(['success' => true, 'data' => $data]);
$db->close();
