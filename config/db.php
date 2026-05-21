<?php

define('DB_HOST', $_ENV['MYSQLHOST']);
define('DB_USER', $_ENV['MYSQLUSER']);
define('DB_PASS', $_ENV['MYSQLPASSWORD']);
define('DB_NAME', $_ENV['APP_DB_NAME']);
define('DB_PORT', $_ENV['MYSQLPORT']);

function getDB() {

    $conn = new mysqli(
        DB_HOST,
        DB_USER,
        DB_PASS,
        DB_NAME,
        DB_PORT
    );

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