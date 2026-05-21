<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$data = json_decode(file_get_contents('php://input'), true);

$email = trim($data['email'] ?? '');
$password = trim($data['password'] ?? '');

if (!$email || !$password) {
    echo json_encode([
        'success' => false,
        'message' => 'Email and password are required.'
    ]);
    exit;
}

$db = getDB();

$stmt = $db->prepare("
    SELECT 
        a.id,
        a.full_name,
        a.email,
        a.profile_image,
        ac.password_hash
    FROM admins a
    JOIN admin_credentials ac 
        ON ac.admin_id = a.id
    JOIN admin_statuses ast 
        ON ast.id = a.status_id
    WHERE a.email = ?
    AND ast.status_name = 'Active'
    LIMIT 1
");

$stmt->bind_param('s', $email);
$stmt->execute();

$result = $stmt->get_result();
$row = $result->fetch_assoc();

$stmt->close();

if (!$row) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid credentials or inactive account.'
    ]);

    $db->close();
    exit;
}

$valid =
    ($password === $row['password_hash']) ||
    password_verify($password, $row['password_hash']);

if (!$valid) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid credentials.'
    ]);

    $db->close();
    exit;
}

$adminId = (int)$row['id'];

$update = $db->prepare("
    UPDATE admins
    SET last_login = NOW()
    WHERE id = ?
");

$update->bind_param('i', $adminId);
$update->execute();
$update->close();

$desc = "Logged in as {$row['full_name']}";

$log = $db->prepare("
    INSERT INTO admin_activity_logs
    (admin_id, action_type, target_type, target_id, description)
    VALUES (?, 'Admin Login', 'Admin', ?, ?)
");

$log->bind_param('iis', $adminId, $adminId, $desc);
$log->execute();
$log->close();

echo json_encode([
    'success' => true,
    'admin' => [
        'id' => $row['id'],
        'name' => $row['full_name'],
        'email' => $row['email'],
        'profile_image' => $row['profile_image']
    ]
]);

$db->close();
?>