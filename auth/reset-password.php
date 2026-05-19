<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$data            = json_decode(file_get_contents('php://input'), true);
$email           = strtolower(trim($data['email']          ?? ''));
$fullName        = trim($data['fullName']                  ?? '');
$securityAnswer  = trim($data['securityAnswer']            ?? '');
$newPassword     = trim($data['newPassword']               ?? '');
$checkOnly       = (bool)($data['checkOnly']               ?? false);
$getQuestion     = (bool)($data['getQuestion']             ?? false);

if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Email is required.']);
    exit;
}

$db   = getDB();

// ── Step 0: return the security question for this email ───────────────────────
if ($getQuestion) {
    $stmt = $db->prepare("
        SELECT ac.security_question
        FROM admins a
        JOIN admin_statuses ast ON ast.id = a.status_id
        JOIN admin_credentials ac ON ac.admin_id = a.id
        WHERE a.email = ? AND ast.status_name = 'Active'
        LIMIT 1
    ");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $db->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'No active admin account found with that email.']);
        exit;
    }
    // Account exists but was created before security questions were required
    if (!$row['security_question']) {
        echo json_encode(['success' => true, 'question' => null, 'nameOnly' => true]);
        exit;
    }
    echo json_encode(['success' => true, 'question' => $row['security_question'], 'nameOnly' => false]);
    exit;
}

// ── Steps 1 & 2: fetch admin + credentials ────────────────────────────────────
$stmt = $db->prepare("
    SELECT a.id, a.full_name, ac.security_answer_hash
    FROM admins a
    JOIN admin_statuses ast ON ast.id = a.status_id
    JOIN admin_credentials ac ON ac.admin_id = a.id
    WHERE a.email = ? AND ast.status_name = 'Active'
    LIMIT 1
");
$stmt->bind_param('s', $email);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'No active admin account found with that email.']);
    $db->close();
    exit;
}

// Verify full name (case-insensitive)
if (strtolower(trim($fullName)) !== strtolower($row['full_name'])) {
    echo json_encode(['success' => false, 'message' => 'The name or security answer does not match our records.']);
    $db->close();
    exit;
}

// Verify security answer (skip if account has none — legacy account)
if ($row['security_answer_hash'] && !password_verify(strtolower($securityAnswer), $row['security_answer_hash'])) {
    echo json_encode(['success' => false, 'message' => 'The name or security answer does not match our records.']);
    $db->close();
    exit;
}

// ── Step 1: identity check only ───────────────────────────────────────────────
if ($checkOnly) {
    echo json_encode(['success' => true]);
    $db->close();
    exit;
}

// ── Step 2: reset password ────────────────────────────────────────────────────
if (strlen($newPassword) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
    $db->close();
    exit;
}

$hash = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $db->prepare("UPDATE admin_credentials SET password_hash = ? WHERE admin_id = ?");
$stmt->bind_param('si', $hash, $row['id']);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
$db->close();
