<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$data             = json_decode(file_get_contents('php://input'), true);
$adminId          = (int)($data['adminId']          ?? 0);
$securityQuestion = trim($data['securityQuestion']   ?? '');
$securityAnswer   = trim($data['securityAnswer']     ?? '');

if (!$adminId || !$securityQuestion || !$securityAnswer) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit;
}

$db         = getDB();
$answerHash = password_hash(strtolower($securityAnswer), PASSWORD_DEFAULT);

$stmt = $db->prepare("UPDATE admin_credentials SET security_question = ?, security_answer_hash = ? WHERE admin_id = ?");
$stmt->bind_param('ssi', $securityQuestion, $answerHash, $adminId);
$stmt->execute();
$stmt->close();

echo json_encode(['success' => true]);
$db->close();
