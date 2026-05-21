<?php
require_once '../config/db.php';

echo json_encode([
  "database" => DB_NAME
]);