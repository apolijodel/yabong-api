<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';

$db = getDB();

// Detect whether the migration has been run by checking for one of the new columns.
$migrated = $db->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'feedback'
       AND COLUMN_NAME  = 'likert_timeliness'"
)->num_rows > 0;

$hasAssigned = $db->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'feedback'
       AND COLUMN_NAME  = 'assigned_to'"
)->num_rows > 0;

$hasAssignedBy = $db->query(
    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'feedback'
       AND COLUMN_NAME  = 'assigned_by'"
)->num_rows > 0;

$extraCols = $migrated
    ? ", f.suggestions, f.likert_timeliness, f.likert_client_handling,
          f.likert_quality, f.likert_overall"
    : "";

$assignedCols = $hasAssigned
    ? ", aa.id AS assigned_to_id, aa.full_name AS assigned_to_name"
    : ", NULL AS assigned_to_id, NULL AS assigned_to_name";
$assignedJoin = $hasAssigned
    ? "LEFT JOIN admins aa ON aa.id = f.assigned_to"
    : "";

$assignedByCols = $hasAssignedBy
    ? ", ab.full_name AS assigned_by_name"
    : ", NULL AS assigned_by_name";
$assignedByJoin = $hasAssignedBy
    ? "LEFT JOIN admins ab ON ab.id = f.assigned_by"
    : "";

$result = $db->query("
    SELECT
        f.id,
        f.tracking_code,
        f.submitter_name,
        f.is_anonymous,
        f.include_name,
        f.description,
        f.sentiment_label,
        f.sentiment_score,
        f.predicted_category_confidence,
        f.resolution_notes,
        f.resolved_at,
        f.device_name,
        f.device_type,
        f.operating_system,
        f.browser_name,
        f.browser_version,
        f.ip_address,
        f.hostname,
        f.created_at
        $extraCols,
        fc.name        AS category,
        es.system_name AS source,
        ra.full_name   AS resolved_by
        $assignedCols
        $assignedByCols
    FROM feedback f
    LEFT JOIN feedback_categories fc ON fc.id = f.category_id
    LEFT JOIN embed_systems es        ON es.id = f.embed_system_id
    LEFT JOIN admins ra               ON ra.id = f.resolved_by_admin_id
    $assignedJoin
    $assignedByJoin
    ORDER BY f.created_at DESC
");

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'id'             => (int)$row['id'],
        'tracking'       => $row['tracking_code'],
        'title'          => strlen($row['description']) > 60
                              ? substr($row['description'], 0, 60) . '…'
                              : $row['description'],
        'category'       => $row['category']  ?? 'Others',
        'source'         => $row['source']     ?? 'YABONG Feedback System',
        'sentiment'      => $row['sentiment_label'],
        'status'         => $row['resolved_at'] ? 'Resolved' : 'Pending',
        'date'           => date('m/d/y', strtotime($row['created_at'])),
        'isAnonymous'    => (bool)$row['is_anonymous'],
        'submitterName'  => $row['submitter_name'],
        'description'    => $row['description'],
        'suggestions'    => $migrated ? ($row['suggestions'] ?? null) : null,
        'likert'         => $migrated ? [
            'timeliness'     => (int)($row['likert_timeliness']      ?? 0),
            'clientHandling' => (int)($row['likert_client_handling']  ?? 0),
            'quality'        => (int)($row['likert_quality']          ?? 0),
            'overall'        => (int)($row['likert_overall']          ?? 0),
        ] : ['timeliness' => 0, 'clientHandling' => 0, 'quality' => 0, 'overall' => 0],
        'assignedTo'     => $row['assigned_to_name'],
        'assignedToId'   => $row['assigned_to_id'] ? (int)$row['assigned_to_id'] : null,
        'assignedBy'     => $row['assigned_by_name'],
        'resolvedBy'     => $row['resolved_by'],
        'resolvedAt'     => $row['resolved_at'],
        'resolutionNotes'=> $row['resolution_notes'],
        'deviceInfo'     => [
            'deviceName'    => $row['device_name']      ?? 'Unknown',
            'deviceType'    => $row['device_type']      ?? 'Unknown',
            'os'            => $row['operating_system'] ?? 'Unknown',
            'browserName'   => $row['browser_name']     ?? 'Unknown',
            'browserVersion'=> $row['browser_version']  ?? '',
            'ipAddress'     => $row['ip_address']       ?? '',
            'hostname'      => $row['hostname']         ?? '',
        ],
    ];
}

echo json_encode(['success' => true, 'data' => $rows]);
$db->close();
