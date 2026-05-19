<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$data     = json_decode(file_get_contents('php://input'), true);
$email    = trim($data['email']    ?? '');
$password = trim($data['password'] ?? '');

if (!$email || !$password) {
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}

$db   = getDB();
$stmt = $db->prepare("
    SELECT a.id, a.full_name, a.email, a.profile_image, ac.password_hash
    FROM admins a
    JOIN admin_credentials ac ON ac.admin_id = a.id
    JOIN admin_statuses ast   ON ast.id = a.status_id
    WHERE a.email = ? AND ast.status_name = 'Active'
    LIMIT 1
");
$stmt->bind_param('s', $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Invalid credentials or account inactive.']);
    $db->close();
    exit;
}

// Plain-text comparison for dev. In production use password_verify($password, $row['password_hash'])
$valid = ($password === $row['password_hash'])
      || password_verify($password, $row['password_hash']);

if (!$valid) {
    echo json_encode(['success' => false, 'message' => 'Invalid credentials.']);
    $db->close();
    exit;
}

// Update last_login
$db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")
   ->execute([$row['id']]);

// Log login activity
$adminId = (int)$row['id'];
$desc    = "Logged in as {$row['full_name']}";
$db->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, target_type, target_id, description) VALUES (?, 'Admin Login', 'Admin', ?, ?)")
   ->execute([$adminId, $adminId, $desc]);

echo json_encode([
    'success' => true,
    'admin'   => [
        'id'           => $row['id'],
        'name'         => $row['full_name'],
        'email'        => $row['email'],
        'profile_image'=> $row['profile_image'],
    ],
]);
$db->close();
