<?php
require_once 'utils.php';

function generateData($conn)
{

  $conn->query("TRUNCATE TABLE interface_traffic");
  $conn->query("TRUNCATE TABLE device_resources");
  $conn->query("TRUNCATE TABLE disks");
  $conn->query("DELETE FROM devices");
  $conn->query("ALTER TABLE devices AUTO_INCREMENT = 1");
  $conn->query("TRUNCATE TABLE log_messages");

  $local_network = get_local_network();
  if (!$local_network) {
    send_error("Ошибка получения локальной сети.");
  }

  $ip_addresses = get_ip_addresses($local_network);
  if (is_string($ip_addresses)) {
    send_error($ip_addresses);
  }

  $deviceInfo = [];
  $logMessages = [];
  $trafficStats = [];
  $resourcesStats = [];
  $id = 1;

  foreach ($ip_addresses as $ip) {
    $oids = [
      'SNMPv2-MIB::sysName.0',
    ];

    $device_data = [
      'id' => '',
      'hostname' => '',
      'ip_addr' => $ip,
      'netmask' => $local_network['subnet_mask'],
      'login' => '-',
      'passwd' => '-',
      'passwd_enabled' => 'false'
    ];

    foreach ($oids as $oid) {
      $info = get_device_info($ip, $oid);
      $logMessages[] = $info;

      if ($info['status'] == 'success') {
        if (strpos($oid, 'sysName') !== false) {
          $device_data['hostname'] = $info['data'][0] ?? '';
        }
      }
    }

    if ($device_data['hostname']) {
      $device_data['id'] = $id++;
      $deviceInfo[] = $device_data;

      $interface_traffic = get_interface_traffic($ip);
      $logMessages = array_merge($logMessages, $interface_traffic['log']);

      foreach ($interface_traffic['data'] as $interface_data) {
        $interface_stat = [
          'device_id' => $device_data['id'],
          'interface' => $interface_data['interface'],
          'in_unicast' => $interface_data['in_unicast'],
          'out_unicast' => $interface_data['out_unicast'],
          'in_multicast' => $interface_data['in_multicast'],
          'out_multicast' => $interface_data['out_multicast']
        ];

        $trafficStats[] = $interface_stat;
      }

      $resources_data = get_device_resources($ip);
      $logMessages = array_merge($logMessages, $resources_data['log']);

      $resources_stat = [
        'device_id' => $device_data['id'],
        'cpu' => $resources_data['data']['cpu_load']['result'],
        'disks' => [],
        'memory_usage' => $resources_data['data']['memory_usage'],
        'memory_total' => $resources_data['data']['memory_total']
      ];

      foreach ($resources_data['data']['disks'] as $disk) {
        if ($disk['disk_name'] != '<Неизвестно>') {
          $resources_stat['disks'][] = [
            'disk_name' => $disk['disk_name'],
            'disk_usage' => $disk['disk_usage'],
            'disk_total' => $disk['disk_total']
          ];
        } else {
          $resources_stat['disks'][] = '<Неизвестно>';
        }
      }

      $resourcesStats[] = $resources_stat;
    }
  }

  foreach ($deviceInfo as $device) {
    $stmt = $conn->prepare("INSERT INTO devices (hostname, ip_addr, netmask, login, passwd, passwd_enabled) VALUES (?, ?, ?, ?, ?, ?)");
    $passwd_enabled = $device['passwd_enabled'] === 'true' ? 1 : 0;
    $stmt->bind_param("sssssi", $device['hostname'], $device['ip_addr'], $device['netmask'], $device['login'], $device['passwd'], $passwd_enabled);
    $stmt->execute();
    $device_id = $conn->insert_id;

    foreach ($trafficStats as $traffic) {
      if ($traffic['device_id'] == $device['id']) {
        $stmt2 = $conn->prepare("INSERT INTO interface_traffic (device_id, interface, in_unicast, out_unicast, in_multicast, out_multicast) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt2->bind_param("isiiii", $device_id, $traffic['interface'], $traffic['in_unicast'], $traffic['out_unicast'], $traffic['in_multicast'], $traffic['out_multicast']);
        $stmt2->execute();
      }
    }

    foreach ($resourcesStats as $resources) {
      if ($resources['device_id'] == $device['id']) {
        $stmt3 = $conn->prepare("INSERT INTO device_resources (device_id, cpu_load, memory_usage, memory_total) VALUES (?, ?, ?, ?)");
        $stmt3->bind_param("iddd", $device_id, $resources['cpu'], $resources['memory_usage'], $resources['memory_total']);
        $stmt3->execute();

        foreach ($resources['disks'] as $disk) {
          if ($disk !== '<Неизвестно>') {
            $stmt4 = $conn->prepare("INSERT INTO disks (device_id, disk_name, disk_usage, disk_total) VALUES (?, ?, ?, ?)");
            $stmt4->bind_param("isdd", $device_id, $disk['disk_name'], $disk['disk_usage'], $disk['disk_total']);
            $stmt4->execute();
          }
        }
      }
    }
  }

  $stmt5 = $conn->prepare("INSERT INTO log_messages (status, message) VALUES (?, ?)");
  foreach ($logMessages as $log) {
    $stmt5->bind_param("ss", $log['status'], $log['message']);
    $stmt5->execute();
  }

  return "ok";
}

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
  require_once '../db/db_connect.php';

  $result = generateData($conn);

  if ($result !== "ok") {
    echo "Ошибка генерации данных: " . htmlspecialchars($result);
    exit;
  }

  $conn->close();
}
