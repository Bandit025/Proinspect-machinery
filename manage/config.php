<?php
// config.php — ตั้งค่าฐานข้อมูล + เริ่ม session
date_default_timezone_set('Asia/Bangkok');

$DB_HOST = 'localhost';
$DB_NAME = 'maccro';
$DB_USER = 'root';
$DB_PASS = 'root';

try {
  $pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Throwable $e) {
  exit('ไม่สามารถเชื่อมต่อฐานข้อมูลได้');
}

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}


if (!function_exists('status_label_th')) {
  function status_label_th(int $c): string {
    return [1=>'รับเข้า',2=>'พร้อมขาย',3=>'จอง',4=>'ขายแล้ว',5=>'ตัดจำหน่าย'][$c] ?? 'รับเข้า';
  }
}

if (!function_exists('current_fullname')) {
  function current_fullname(): string {
    $u = $_SESSION['user'] ?? [];
    if (!empty($u['name'])) return (string)$u['name'];
    $fn = trim(($u['f_name'] ?? '').' '.($u['l_name'] ?? ''));
    if ($fn !== '') return $fn;
    return $u['email'] ?? 'ผู้ใช้ระบบ';
  }
}

