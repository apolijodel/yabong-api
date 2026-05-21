<?php
require_once './config/db.php';

echo json_encode([
  "database" => DB_NAME,
  "host" => DB_HOST,
  "port" => DB_PORT
]);