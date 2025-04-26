<?php

// Функция для получения IP-адреса текущего устройства и вычисления сети
function get_local_network()
{
  $ip_output = shell_exec("wmic nicconfig where IPEnabled=true get IPAddress /value");
  $mask_output = shell_exec("wmic nicconfig where IPEnabled=true get IPSubnet /value");

  preg_match_all('/IPAddress=\{([^}]+)\}/', $ip_output, $ip_matches);
  preg_match_all('/IPSubnet=\{([^}]+)\}/', $mask_output, $mask_matches);

  if (empty($ip_matches[1]) || empty($mask_matches[1])) {
    return null;
  }

  $ip_list = explode('","', trim($ip_matches[1][0], '"'));
  $mask_list = explode('","', trim($mask_matches[1][0], '"'));

  $ipv4 = null;
  foreach ($ip_list as $candidate) {
    if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
      $ipv4 = $candidate;
      break;
    }
  }

  $subnet_mask = $mask_list[0];

  if (!$ipv4 || !$subnet_mask) {
    return null;
  }

  $network = long2ip(ip2long($ipv4) & ip2long($subnet_mask));
  $cidr = substr_count(decbin(ip2long($subnet_mask)), '1');

  return [
    'network' => $network,
    'cidr' => $cidr,
    'subnet_mask' => $subnet_mask
  ];
}

// Функция для получения списка IP-адресов устройств в сети
function get_ip_addresses($local_network = null)
{
  if (!$local_network) {
    $local_network = get_local_network();
    if (!$local_network) {
      return "Ошибка определения локальной сети.";
    }
  }

  $output = shell_exec('arp -a');
  if (!$output) {
    return "Ошибка выполнения команды arp.";
  }

  $lines = explode("\n", $output);
  $ips = [];

  foreach ($lines as $line) {
    if (preg_match('/(\d+\.\d+\.\d+\.\d+)/', $line, $matches)) {
      $ip = $matches[1];

      if (substr($ip, 0, 3) !== '224' && substr($ip, 0, 3) !== '255' && substr($ip, -3, 3) !== '255' && filter_var($ip, FILTER_VALIDATE_IP)) {
        if ((ip2long($ip) & ip2long($local_network['subnet_mask'])) === ip2long($local_network['network'])) {
          $ips[] = $ip;
        }
      }
    }
  }

  return $ips;
}

// Функция для получения информации через SNMP
function get_device_info($device_ip, $oid)
{
  $community = 'public';
  $timeout = 5;
  $retries = 10;

  $response = [
    'ip' => $device_ip,
    'oid' => $oid,
    'status' => 'failure',
    'message' => '',
    'data' => []
  ];

  try {
    $device_info = @snmpwalk($device_ip, $community, $oid, $timeout, $retries);
    if ($device_info === false) {
      throw new Exception("Нет ответа от устройства $device_ip для OID $oid");
    }

    $formatted_data = [];
    foreach ($device_info as $entry) {
      $formatted_data[] = trim(str_replace('STRING: ', '', $entry));
    }

    $response['status'] = 'success';
    $response['message'] = "Получены данные от устройства $device_ip для OID $oid";
    $response['data'] = $formatted_data;
  } catch (Exception $e) {
    $response['message'] = $e->getMessage();
  }

  return $response;
}

// Функция для получения статистики по интерфейсам
function get_interface_traffic($device_ip)
{
  $oids = [
    'IF-MIB::ifDescr',
    'IF-MIB::ifInUcastPkts',
    'IF-MIB::ifOutUcastPkts',
    'IF-MIB::ifInMulticastPkts',
    'IF-MIB::ifOutMulticastPkts'
  ];

  $interfaces_data = [];
  $log_info = [];

  foreach ($oids as $oid) {
    $interface_info = get_device_info($device_ip, $oid);
    $log_info[] = $interface_info;

    if ($interface_info['status'] == 'success') {
      foreach ($interface_info['data'] as $index => $data) {
        if (!isset($interfaces_data[$index])) {
          $interfaces_data[$index] = [
            'interface' => '',
            'in_unicast' => 0,
            'out_unicast' => 0,
            'in_multicast' => 0,
            'out_multicast' => 0
          ];
        }

        switch ($oid) {
          case 'IF-MIB::ifDescr':
            $interfaces_data[$index]['interface'] = $data;
            break;
          case 'IF-MIB::ifInUcastPkts':
            $interfaces_data[$index]['in_unicast'] = parse_traffic_data($data);
            break;
          case 'IF-MIB::ifOutUcastPkts':
            $interfaces_data[$index]['out_unicast'] = parse_traffic_data($data);
            break;
          case 'IF-MIB::ifInMulticastPkts':
            $interfaces_data[$index]['in_multicast'] = parse_traffic_data($data);
            break;
          case 'IF-MIB::ifOutMulticastPkts':
            $interfaces_data[$index]['out_multicast'] = parse_traffic_data($data);
            break;
        }
      }
    }
  }

  return [
    'data' => $interfaces_data,
    'log' => $log_info
  ];
}

// Функция для парсинга данных о траффике
function parse_traffic_data($data)
{
  if (preg_match('/Counter32:\s*(\d+)/', $data, $matches)) {
    return (int)$matches[1];
  }
  return 0;
}

// Функция для получения ресурсов устройства (CPU, оперативная память, дисковое пространство)
function get_device_resources($device_ip)
{
  $oids = [
    'HOST-RESOURCES-MIB::hrProcessorLoad',  // Загрузка CPU
    'HOST-RESOURCES-MIB::hrStorageDescr',   // Описание диска
    'HOST-RESOURCES-MIB::hrStorageUsed',    // Использование диска
    'HOST-RESOURCES-MIB::hrStorageSize',    // Общий размер диска
    'HOST-RESOURCES-MIB::hrMemoryUsed',     // Использование оперативной памяти
    'HOST-RESOURCES-MIB::hrMemorySize'      // Общий объём оперативной памяти
  ];

  $resources_data = [
    'cpu_load' => [
      'total' => 0,
      'count' => 0,
      'result' => '<Неизвестно>'
    ],
    'disks' => [],
    'memory_usage' => '<Неизвестно>',
    'memory_total' => '<Неизвестно>'
  ];
  $log_info = [];

  foreach ($oids as $oid) {
    $resources_info = get_device_info($device_ip, $oid);
    $log_info[] = $resources_info;

    if ($resources_info['status'] == 'success') {
      foreach ($resources_info['data'] as $index => $data) {
        switch ($oid) {
          case 'HOST-RESOURCES-MIB::hrProcessorLoad':
            $resources_data['cpu_load']['total'] += parse_usage_data($data);
            $resources_data['cpu_load']['count']++;
            $resources_data['cpu_load']['result'] = $resources_data['cpu_load']['count'] > 0 ?
              round($resources_data['cpu_load']['total'] / $resources_data['cpu_load']['count'], 2) :
              '<Неизвестно>';
            break;

          case 'HOST-RESOURCES-MIB::hrStorageDescr':
            $disk_name = parse_disk_name($data);
            $resources_data['disks'][$index] = [
              'disk_name' => $disk_name,
              'disk_usage' => '<Неизвестно>',
              'disk_total' => '<Неизвестно>'
            ];
            break;

          case 'HOST-RESOURCES-MIB::hrStorageUsed':
            $resources_data['disks'][$index]['disk_usage'] = bytes_to_gb(parse_usage_data($data));
            break;

          case 'HOST-RESOURCES-MIB::hrStorageSize':
            $resources_data['disks'][$index]['disk_total'] = bytes_to_gb(parse_usage_data($data));
            break;

          case 'HOST-RESOURCES-MIB::hrMemoryUsed':
            $resources_data['memory_usage'] = bytes_to_gb(parse_usage_data($data));
            break;

          case 'HOST-RESOURCES-MIB::hrMemorySize':
            $resources_data['memory_total'] = kb_to_gb(parse_memory_data($data));
            break;
        }
      }
    } else {

      switch ($oid) {
        case 'HOST-RESOURCES-MIB::hrProcessorLoad':
          $resources_data['cpu_load']['result'] = '<Неизвестно>';
          break;

        case 'HOST-RESOURCES-MIB::hrStorageDescr':
          $resources_data['disks'][] = [
            'disk_name' => '<Неизвестно>',
            'disk_usage' => '<Неизвестно>',
            'disk_total' => '<Неизвестно>'
          ];
          break;

        case 'HOST-RESOURCES-MIB::hrMemoryUsed':
          $resources_data['memory_usage'] = '<Неизвестно>';
          break;

        case 'HOST-RESOURCES-MIB::hrMemorySize':
          $resources_data['memory_total'] = '<Неизвестно>';
          break;
      }
    }
  }

  return [
    'data' => $resources_data,
    'log' => $log_info
  ];
}

// Функция для парсинга данных об оперативной памяти
function parse_memory_data($data)
{
  if (preg_match('/INTEGER:\s*(\d+)\s*(KBytes|Bytes)?/', $data, $matches)) {
    return (int)$matches[1];
  }
  return 0;
}

// Функция для парсинга данных о загруженности
function parse_usage_data($data)
{
  if (preg_match('/INTEGER:\s*(\d+)/', $data, $matches)) {
    return (int)$matches[1];
  }
  return 0;
}

// Функция для парсинга данных об имени диска
function parse_disk_name($data)
{
  if (preg_match('/^[A-Za-z]:\\\\/', $data)) {
    return $data[0];
  } else {
    return $data;
  }
}

// Функция для перевода байт в гигабайты
function bytes_to_gb($bytes)
{
  return round($bytes / (1024 ** 3), 4);
}

// Функция для перевода килобайт в гигабайты
function kb_to_gb($kb)
{
  return round($kb / (1024 ** 2), 4);
}

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
      'cpu' => $resources_data['data']['cpu_load']['result'] . ' %',
      'disks' => [],
      'memory' => $resources_data['data']['memory_usage'] . ' ГБ / ' . $resources_data['data']['memory_total'] . ' ГБ'
    ];

    foreach ($resources_data['data']['disks'] as $disk) {
      if ($disk['disk_name'] != '<Неизвестно>') {
        $resources_stat['disks'][] = $disk['disk_name'] . ': ' . $disk['disk_usage'] . ' ГБ / ' . $disk['disk_total'] . ' ГБ';
      } else {
        $resources_stat['disks'][] = '<Неизвестно>';
      }
    }

    $resourcesStats[] = $resources_stat;
  }
}

?>

<!DOCTYPE html>
<html lang="ru">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Мониторинг сетевых устройств</title>
  <style>
    table {
      width: 100%;
      border-collapse: collapse;
    }

    th,
    td {
      border: 1px solid #ddd;
      padding: 8px;
      text-align: left;
    }

    th {
      background-color: #f2f2f2;
    }

    .hidden {
      display: none;
    }
  </style>
</head>

<body>
  <h1>Мониторинг сетевых устройств</h1>

  <!-- Первая таблица: Информация о сетевых устройствах -->
  <table>
    <tr>
      <th>ID</th>
      <th>Имя устройства</th>
      <th>IP-адрес</th>
      <th>Маска подсети</th>
      <th>Логин</th>
      <th>Пароль</th>
      <th>Passwd_enabled</th>
      <th>Действия</th>
    </tr>
    <?php foreach ($deviceInfo as $device): ?>
      <tr>
        <td><?= htmlspecialchars($device['id']); ?></td>
        <td><?= htmlspecialchars($device['hostname']); ?></td>
        <td><?= htmlspecialchars($device['ip_addr']); ?></td>
        <td><?= htmlspecialchars($device['netmask']); ?></td>
        <td><?= htmlspecialchars($device['login']); ?></td>
        <td><?= htmlspecialchars($device['passwd']); ?></td>
        <td><?= htmlspecialchars($device['passwd_enabled']); ?></td>
        <td>
          <button onclick="toggleTraffic(<?= $device['id']; ?>)">Показать интерфейсы</button>
          <button onclick="toggleResources(<?= $device['id']; ?>)">Показать статистику ресурсов</button>
        </td>
      </tr>

      <!-- Таблица с трафиком для каждого устройства -->
      <tr id="traffic-<?= $device['id']; ?>" class="hidden">
        <td colspan="8">
          <table>
            <tr>
              <th>Интерфейс</th>
              <th>In Unicast</th>
              <th>Out Unicast</th>
              <th>In Multicast</th>
              <th>Out Multicast</th>
            </tr>
            <?php
            $deviceTraffic = array_filter($trafficStats, function ($traffic) use ($device) {
              return $traffic['device_id'] == $device['id'];
            });

            foreach ($deviceTraffic as $traffic):
            ?>
              <tr>
                <td><?= htmlspecialchars($traffic['interface']); ?></td>
                <td><?= htmlspecialchars($traffic['in_unicast']); ?></td>
                <td><?= htmlspecialchars($traffic['out_unicast']); ?></td>
                <td><?= htmlspecialchars($traffic['in_multicast']); ?></td>
                <td><?= htmlspecialchars($traffic['out_multicast']); ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </td>
      </tr>

      <!-- Таблица с ресурсами для каждого устройства -->
      <tr id="resources-<?= $device['id']; ?>" class="hidden">
        <td colspan="8">
          <table>
            <tr>
              <th>Загруженность CPU</th>
              <th>Диск: Используется / Всего</th>
              <th>Оперативная память: Используется / Всего</th>
            </tr>
            <?php
            $deviceResources = array_values(array_filter($resourcesStats, function ($resources) use ($device) {
              return $resources['device_id'] == $device['id'];
            }))[0];
            ?>
            <tr>
              <td><?= htmlspecialchars($deviceResources['cpu']); ?></td>
              <td>
                <?php foreach ($deviceResources['disks'] as $disk): ?>
                  <?= htmlspecialchars($disk); ?> <br>
                <?php endforeach; ?>
              </td>
              <td><?= htmlspecialchars($deviceResources['memory']); ?></td>
            </tr>
          </table>
        </td>
      </tr>

    <?php endforeach; ?>
  </table>

  <script>
    function toggleTraffic(deviceId) {
      const trafficTable = document.getElementById(`traffic-${deviceId}`);
      trafficTable.classList.toggle('hidden');
    }

    function toggleResources(deviceId) {
      const resourceTable = document.getElementById(`resources-${deviceId}`);
      resourceTable.classList.toggle('hidden');
    }

    const logMessages = <?php echo json_encode($logMessages); ?>;
    logMessages.forEach(log => {
      if (log.status === 'success') {
        console.log(`✅ Успех: ${log.message}`);
      } else {
        console.error(`❌ Ошибка: ${log.message}`);
      }
    });
  </script>
</body>

</html>
