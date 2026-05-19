<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$db = getDB();

// Quick stats
$total    = (int)$db->query("SELECT COUNT(*) AS c FROM feedback")->fetch_assoc()['c'];
$pending  = (int)$db->query("SELECT COUNT(*) AS c FROM feedback WHERE resolved_at IS NULL")->fetch_assoc()['c'];
$resolved = (int)$db->query("SELECT COUNT(*) AS c FROM feedback WHERE resolved_at IS NOT NULL")->fetch_assoc()['c'];
$avgDays  = $db->query("SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, created_at, resolved_at))/86400, 1) AS avg_days FROM feedback WHERE resolved_at IS NOT NULL")->fetch_assoc()['avg_days'];

// Top 3 most urgent pending feedback (Negative first, then oldest waiting)
$recentStmt = $db->query("
    SELECT f.id,
           CONCAT(LEFT(f.description, 60), IF(CHAR_LENGTH(f.description) > 60, '…', '')) AS title,
           COALESCE(fc.name, 'Others') AS category,
           f.sentiment_label,
           'Pending' AS status,
           DATE_FORMAT(f.created_at, '%b %d, %Y') AS date,
           TIMESTAMPDIFF(DAY, f.created_at, NOW()) AS days_waiting
    FROM feedback f
    LEFT JOIN feedback_categories fc ON fc.id = f.category_id
    WHERE f.resolved_at IS NULL
    ORDER BY
        FIELD(f.sentiment_label, 'Negative', 'Mixed', 'Neutral', 'Positive'),
        f.created_at ASC
    LIMIT 3
");
$recentFeedback = [];
while ($row = $recentStmt->fetch_assoc()) {
    $recentFeedback[] = $row;
}

// Recent activity log (last 15)
$stmt = $db->query("
    SELECT al.action_type, al.target_type, al.target_id, al.description, al.created_at,
           a.full_name AS admin_name
    FROM admin_activity_logs al
    LEFT JOIN admins a ON a.id = al.admin_id
    ORDER BY al.created_at DESC
    LIMIT 5
");

$logs = [];
while ($row = $stmt->fetch_assoc()) {
    $detail = $row['description'] ?? '';
    if (!$detail && $row['target_type'] && $row['target_id']) {
        $detail = $row['target_type'] . ' #' . $row['target_id'];
    }
    $logs[] = [
        'timestamp'  => date('m/d/y g:iA', strtotime($row['created_at'])),
        'admin'      => $row['admin_name'] ?? 'System',
        'action'     => $row['action_type'],
        'detail'     => $detail,
    ];
}

echo json_encode([
    'success'        => true,
    'stats'          => [
        'total'    => $total,
        'pending'  => $pending,
        'resolved' => $resolved,
        'avgDays'  => $avgDays ?? 0,
    ],
    'recentFeedback' => $recentFeedback,
    'logs'           => $logs,
]);
$db->close();
