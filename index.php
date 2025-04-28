<?php
require_once './db/db_connect.php';
require_once './backend/generate_data.php';

$result = generateData($conn);

if ($result !== "ok") {
  echo "Ошибка генерации данных: " . htmlspecialchars($result);
  exit;
}

$conn->close();

header("Location: frontend/index.html");
exit;
