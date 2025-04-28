<?php
require_once 'utils.php';

$local_network = get_local_network();
if (!$local_network) {
  echo "Ошибка получения локальной сети.";
  exit;
}

$ip_addresses = get_ip_addresses($local_network);
if (is_string($ip_addresses)) {
  echo $ip_addresses;
  exit;
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

echo json_encode([
  'devices' => $deviceInfo,
  'trafficStats' => $trafficStats,
  'resourcesStats' => $resourcesStats,
  'logMessages' => $logMessages
]);
