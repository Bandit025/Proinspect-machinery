<?php
// brand_delete.php
require __DIR__ . '/config.php';

// อนุญาตเฉพาะแอดมิน
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit;
}

// ต้อง POST เท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: index.php?error=' . urlencode('วิธีการเรียกไม่ถูกต้อง')); exit;
}

// ตรวจ CSRF
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  header('Location: index.php?error=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
}

// เตรียม path สำหรับ redirect กลับ
function back_path(): string {
  // 1) ใช้ค่าที่มาจากฟอร์ม (เช่น /maccro/brands_list.php?page=2)
  if (!empty($_POST['return'])) {
    $ret = parse_url($_POST['return'], PHP_URL_PATH);
    if ($ret) return $ret; // เฉพาะ path ปลอดภัย
  }
  // 2) ลองเอาจาก Referer
  if (!empty($_SERVER['HTTP_REFERER'])) {
    $ret = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    if ($ret) return $ret;
  }
  // 3) ดีฟอลต์: กลับไปหน้าที่อยู่โฟลเดอร์เดียวกันชื่อ brands.php
  return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/brands.php';
}
function redirect_with(string $path, array $params) {
  $sep = (strpos($path,'?') !== false) ? '&' : '?';
  header('Location: '.$path.$sep.http_build_query($params)); exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  redirect_with(back_path(), ['error' => 'พารามิเตอร์ไม่ถูกต้อง']);
}

// ดึงชื่อแบรนด์เพื่อแสดงข้อความ
$stm = $pdo->prepare('SELECT brand_name FROM brands WHERE brand_id = ? LIMIT 1');
$stm->execute([$id]);
$brand = $stm->fetch();
if (!$brand) {
  redirect_with(back_path(), ['error' => 'ไม่พบบรนด์ที่ต้องการลบ']);
}

// ตรวจการอ้างอิงก่อนลบ
$cntModels = $pdo->prepare('SELECT COUNT(*) FROM models WHERE brand_id = ?');
$cntModels->execute([$id]);
$nModels = (int)$cntModels->fetchColumn();

$cntMachines = $pdo->prepare('SELECT COUNT(*) FROM machines WHERE brand_id = ?');
$cntMachines->execute([$id]);
$nMachines = (int)$cntMachines->fetchColumn();

if ($nModels > 0 || $nMachines > 0) {
  $msg = 'ไม่สามารถลบ "' . $brand['brand_name'] . '" เพราะถูกใช้อยู่';
  $parts = [];
  if ($nModels > 0)   $parts[] = "ในรุ่น {$nModels} รายการ";
  if ($nMachines > 0) $parts[] = "ในรถ {$nMachines} คัน";
  if ($parts) $msg .= ' (' . implode(' / ', $parts) . ')';
  redirect_with(back_path(), ['error' => $msg]);
}

// ลบ
try {
  $del = $pdo->prepare('DELETE FROM brands WHERE brand_id = ? LIMIT 1');
  $del->execute([$id]);

  if ($del->rowCount() > 0) {
    redirect_with(back_path(), ['ok' => 'ลบยี่ห้อเรียบร้อย']);
  } else {
    redirect_with(back_path(), ['error' => 'ลบไม่สำเร็จ']);
  }
} catch (PDOException $e) {
  if (($e->errorInfo[1] ?? null) === 1451) {
    redirect_with(back_path(), ['error' => 'ไม่สามารถลบ: ข้อมูลถูกอ้างอิงอยู่']);
  }
  redirect_with(back_path(), ['error' => 'เกิดข้อผิดพลาด: '.$e->getCode()]);
}
