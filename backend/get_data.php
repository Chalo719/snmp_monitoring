<?php
require_once '../db/db_connect.php';

$devices = [];
$trafficStats = [];
$resourcesStats = [];
$logMessages = [];

$device_query = $conn->query("SELECT * FROM devices");
while ($device = $device_query->fetch_assoc()) {
  $devices[] = $device;
}

$traffic_query = $conn->query("SELECT * FROM interface_traffic");
while ($traffic = $traffic_query->fetch_assoc()) {
  $trafficStats[] = $traffic;
}

$resources_query = $conn->query("SELECT * FROM device_resources");
while ($resources = $resources_query->fetch_assoc()) {
  $resourcesStats[] = $resources;
}

$disks_query = $conn->query("SELECT * FROM disks");
$disks = [];
while ($disk = $disks_query->fetch_assoc()) {
  $disks[] = $disk;
}

foreach ($resourcesStats as &$resources) {
  $device_disks = array_filter($disks, function ($disk) use ($resources) {
    return $disk['device_id'] == $resources['device_id'];
  });

  $resources['disks'] = array_values($device_disks);
}

// Получаем логи
// $logMessages = get_log_messages(); // Предполагается, что у вас есть функция для получения логов

echo json_encode([
  'devices' => $devices,
  'trafficStats' => $trafficStats,
  'resourcesStats' => $resourcesStats,
  'logMessages' => $logMessages
]);

$conn->close();
