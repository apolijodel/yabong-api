<?php
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();

echo "Creating tables...\n";

/* -------------------------------------------------
   CREATE feedback TABLE FIRST
------------------------------------------------- */

$db->query("
CREATE TABLE IF NOT EXISTS feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT DEFAULT NULL,
    title VARCHAR(255) DEFAULT NULL,
    description TEXT,
    suggestions TEXT DEFAULT NULL,

    sentiment_label ENUM('Positive','Neutral','Negative','Mixed')
    NOT NULL DEFAULT 'Neutral',

    likert_timeliness TINYINT UNSIGNED NOT NULL DEFAULT 0,
    likert_client_handling TINYINT UNSIGNED NOT NULL DEFAULT 0,
    likert_quality TINYINT UNSIGNED NOT NULL DEFAULT 0,
    likert_overall TINYINT UNSIGNED NOT NULL DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

/* -------------------------------------------------
   CREATE feedback_categories
------------------------------------------------- */

$db->query("
CREATE TABLE IF NOT EXISTS feedback_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) UNIQUE,
    description TEXT,
    is_active TINYINT(1) DEFAULT 1
)
");

/* -------------------------------------------------
   CREATE sentiment_analysis_logs
------------------------------------------------- */

$db->query("
CREATE TABLE IF NOT EXISTS sentiment_analysis_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,

    predicted_label ENUM('Positive','Neutral','Negative','Mixed')
    NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");

/* -------------------------------------------------
   INSERT categories
------------------------------------------------- */

$categories = [
    'Canteen',
    'Guidance Counseling',
    'Student Affairs',
    'Bazaar',
    'Blood Donation Drive',
    'Buwan ng Wika',
    'College Events',
    'Freshmen Orientation',
    'Graduation',
    'Leadership Summit',
    'Research Congress',
    'Seminars / Career Talks',
    'Sports Festival',
    'University Week'
];

foreach ($categories as $cat) {
    $stmt = $db->prepare("
        INSERT IGNORE INTO feedback_categories(name)
        VALUES(?)
    ");

    $stmt->bind_param("s", $cat);
    $stmt->execute();
}

echo "Migration completed successfully!";
?>