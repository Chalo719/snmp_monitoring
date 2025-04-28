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
    'HOST-RESOURCES-MIB::hrProcessorLoad', // Загрузка CPU
    'HOST-RESOURCES-MIB::hrStorageDescr', // Описание диска
    'HOST-RESOURCES-MIB::hrStorageUsed', // Использование диска
    'HOST-RESOURCES-MIB::hrStorageSize', // Общий размер диска
    'HOST-RESOURCES-MIB::hrMemoryUsed', // Использование оперативной памяти
    'HOST-RESOURCES-MIB::hrMemorySize' // Общий объём оперативной памяти
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
            $resources_data['memory_usage'] = kb_to_gb(parse_usage_data($data));
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

// Функция для отправки ошибки в поддерживаемом клиентом формате
function send_error($message)
{
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'devices' => [],
    'trafficStats' => [],
    'resourcesStats' => [],
    'logMessages' => [
      ['status' => 'failure', 'message' => $message]
    ]
  ]);
  exit;
}
