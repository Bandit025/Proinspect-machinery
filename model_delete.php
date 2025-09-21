<?php
require __DIR__ . '/config.php';


$back = $_POST['return'] ?? 'models.php';

# ตรวจ CSRF
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  header('Location: '.$back.'?error=' . urlencode('CSRF ไม่ถูกต้อง')); exit;
}

# รับ id
$idRaw = $_POST['id'] ?? '';
if ($idRaw === '' || !ctype_digit((string)$idRaw)) {
  header('Location: '.$back.'?error=' . urlencode('รหัสไม่ถูกต้อง')); exit;
}
$id = (int)$idRaw;

# ค้นหาชื่อคอลัมน์คีย์หลัก (รองรับทั้ง model_id หรือ id)
$pk = null;
try {
  $q = $pdo->prepare("SHOW COLUMNS FROM `models` LIKE 'model_id'");
  $q->execute();
  if ($q->fetch()) {
    $pk = 'model_id';
  } else {
    $q = $pdo->prepare("SHOW COLUMNS FROM `models` LIKE 'id'");
    $q->execute();
    if ($q->fetch()) $pk = 'id';
  }
} catch (Throwable $e) {
  // ปล่อยให้ $pk เป็น null แล้วแจ้ง error ด้านล่าง
}

if (!$pk) {
  header('Location: '.$back.'?error=' . urlencode('ไม่พบคอลัมน์รหัสรุ่น (model_id หรือ id) ในตาราง models')); exit;
}

# ตรวจว่ามีแถวนี้จริง
$chk = $pdo->prepare("SELECT 1 FROM `models` WHERE `$pk` = ? LIMIT 1");
$chk->execute([$id]);
if (!$chk->fetch()) {
  header('Location: '.$back.'?error=' . urlencode('ไม่พบรายการ')); exit;
}

# ลบ
try {
  $del = $pdo->prepare("DELETE FROM `models` WHERE `$pk` = ? LIMIT 1");
  $del->execute([$id]);
  header('Location: '.$back.'?ok=' . urlencode('ลบรุ่นเรียบร้อย')); exit;
} catch (PDOException $e) {
  # กรณีติด Foreign Key หรืออื่น ๆ
  header('Location: '.$back.'?error=' . urlencode('ลบไม่สำเร็จ: ' . $e->getCode())); exit;
}
