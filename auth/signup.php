<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$data             = json_decode(file_get_contents('php://input'), true);
$fullName         = trim($data['fullName']         ?? '');
$email            = strtolower(trim($data['email'] ?? ''));
$password         = trim($data['password']         ?? '');
$securityQuestion = trim($data['securityQuestion'] ?? '');
$securityAnswer   = trim($data['securityAnswer']   ?? '');
$position         = trim($data['position']         ?? '');

if (!$fullName || !$email || !$password || !$securityQuestion || !$securityAnswer) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

$db = getDB();

// Add position column if it doesn't exist yet
$posColExists = $db->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'admins'
       AND COLUMN_NAME  = 'position'"
)->num_rows > 0;

if (!$posColExists) {
    $db->query("ALTER TABLE admins ADD COLUMN position VARCHAR(100) NULL DEFAULT NULL AFTER email");
}

// Check email not already taken
$stmt = $db->prepare("SELECT id FROM admins WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    echo json_encode(['success' => false, 'message' => 'An account with that email already exists.']);
    $db->close();
    exit;
}

// Get Pending status ID
$row = $db->query("SELECT id FROM admin_statuses WHERE status_name = 'Pending' LIMIT 1")->fetch_assoc();
if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Server configuration error (missing Pending status).']);
    $db->close();
    exit;
}
$pendingStatusId = $row['id'];

// Insert admin (role_id = 1 = Admin)
$roleId = 1;
$stmt = $db->prepare("INSERT INTO admins (full_name, email, position, status_id, role_id) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param('sssii', $fullName, $email, $position, $pendingStatusId, $roleId);
$stmt->execute();
$newAdminId = $db->insert_id;
$stmt->close();

// Insert credentials (hashed password + security answer)
$hash           = password_hash($password, PASSWORD_DEFAULT);
$answerHash     = password_hash(strtolower($securityAnswer), PASSWORD_DEFAULT);
$stmt = $db->prepare("INSERT INTO admin_credentials (admin_id, password_hash, security_question, security_answer_hash) VALUES (?, ?, ?, ?)");
$stmt->bind_param('isss', $newAdminId, $hash, $securityQuestion, $answerHash);
$stmt->execute();
$stmt->close();

// Notify existing admins
$msg = "New admin registration: {$fullName} ({$email}) is awaiting approval.";
$stmt = $db->prepare("INSERT INTO notifications (admin_id, type, message) VALUES (1, 'admin', ?)");
$stmt->bind_param('s', $msg);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
$db->close();
