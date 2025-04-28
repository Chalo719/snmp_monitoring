<?php

$host = 'localhost';
$user = 'root';
$pass = 'f1M55ab0';
$dbname = 'snmp_monitoring';

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
  die("Ошибка подключения к БД: " . $conn->connect_error);
}
