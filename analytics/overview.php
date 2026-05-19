<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$db = getDB();

$total    = $db->query("SELECT COUNT(*) AS c FROM feedback")->fetch_assoc()['c'];
$resolved = $db->query("SELECT COUNT(*) AS c FROM feedback WHERE resolved_at IS NOT NULL")->fetch_assoc()['c'];
$avgDays  = $db->query("SELECT ROUND(AVG(TIMESTAMPDIFF(SECOND, created_at, resolved_at))/86400, 1) AS avg_days FROM feedback WHERE resolved_at IS NOT NULL")->fetch_assoc()['avg_days'];

// Sentiment breakdown
$sentimentRows = $db->query("SELECT sentiment_label, COUNT(*) AS count FROM feedback GROUP BY sentiment_label");
$sentiment = ['Positive' => 0, 'Neutral' => 0, 'Negative' => 0, 'Mixed' => 0];
while ($row = $sentimentRows->fetch_assoc()) {
    $sentiment[$row['sentiment_label']] = (int)$row['count'];
}

// Category breakdown
$catRows = $db->query("
    SELECT fc.name, COUNT(f.id) AS count
    FROM feedback f
    LEFT JOIN feedback_categories fc ON fc.id = f.category_id
    GROUP BY fc.name
    ORDER BY count DESC
");
$categories = [];
$colors = ['#0E6B3D','#11773B','#79A98C','#4CAF72','#A8D5B5','#C9D8CE','#DCE8DF','#E8F0EA'];
$i = 0;
while ($row = $catRows->fetch_assoc()) {
    $categories[] = [
        'category' => $row['name'] ?? 'Others',
        'count'    => (int)$row['count'],
        'color'    => $colors[$i % count($colors)],
    ];
    $i++;
}

// Top category
$topCategory = $categories[0] ?? ['category' => '—', 'count' => 0];

echo json_encode([
    'success'     => true,
    'total'       => (int)$total,
    'resolved'    => (int)$resolved,
    'avgDays'     => $avgDays ?? 0,
    'sentiment'   => $sentiment,
    'categories'  => $categories,
    'topCategory' => $topCategory,
]);
$db->close();
