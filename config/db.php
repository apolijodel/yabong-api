<?php
// Online Database connection - InfinityFree
define('DB_HOST', 'sql308.infinityfree.com');
define('DB_USER', 'if0_41945413');
define('DB_PASS', 'Apoli2006');
define('DB_NAME', 'if0_41945413_yabong_db');

function getDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Database connection failed: ' . $conn->connect_error
        ]);
        exit;
    }

    $conn->set_charset('utf8mb4');
    return $conn;
}
?>