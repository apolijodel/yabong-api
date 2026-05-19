<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$adminId = (int)($_POST['adminId'] ?? 0);
if (!$adminId) {
    echo json_encode(['success' => false, 'message' => 'Admin ID required.']);
    exit;
}

if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded.']);
    exit;
}

$file     = $_FILES['avatar'];
$maxBytes = 2 * 1024 * 1024; // 2 MB

if ($file['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'message' => 'Image must be under 2 MB.']);
    exit;
}

$allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$mime    = mime_content_type($file['tmp_name']);
if (!in_array($mime, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, WebP, or GIF images are allowed.']);
    exit;
}

$ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime];
$uploadDir = __DIR__ . '/../uploads/avatars/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// Remove old avatar file if it exists
$db   = getDB();
$stmt = $db->prepare("SELECT profile_image FROM admins WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $adminId);
$stmt->execute();
$old = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!empty($old['profile_image'])) {
    // Extract filename from the stored URL and delete it
    $oldFile = $uploadDir . basename($old['profile_image']);
    if (file_exists($oldFile)) unlink($oldFile);
}

$filename = $adminId . '_' . time() . '.' . $ext;
$dest     = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save image.']);
    $db->close();
    exit;
}

$url  = 'http://localhost/yabong-api/uploads/avatars/' . $filename;
$stmt = $db->prepare("UPDATE admins SET profile_image = ? WHERE id = ?");
$stmt->bind_param('si', $url, $adminId);
$stmt->execute();
$stmt->close();

$nameRow = $db->query("SELECT full_name FROM admins WHERE id = $adminId LIMIT 1")->fetch_assoc();
$name    = $nameRow['full_name'] ?? "Admin #$adminId";
$desc    = "Updated profile photo for $name";
$db->prepare("INSERT INTO admin_activity_logs (admin_id, action_type, target_type, target_id, description) VALUES (?, 'Updated Profile Photo', 'Admin', ?, ?)")
   ->execute([$adminId, $adminId, $desc]);

echo json_encode(['success' => true, 'url' => $url]);
$db->close();
