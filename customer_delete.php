<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: customers.php?error=' . urlencode('วิธีการเรียกไม่ถูกต้อง')); exit;
}
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  header('Location: customers.php?error=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
}

function back_path(): string {
  if (!empty($_POST['return'])) {
    $ret = parse_url($_POST['return'], PHP_URL_PATH);
    if ($ret) return $ret;
  }
  if (!empty($_SERVER['HTTP_REFERER'])) {
    $ret = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    if ($ret) return $ret;
  }
  return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/customers.php';
}
function redirect_with(string $path, array $params){
  $sep = (strpos($path,'?')!==false) ? '&' : '?';
  header('Location: '.$path.$sep.http_build_query($params)); exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  redirect_with(back_path(), ['error'=>'พารามิเตอร์ไม่ถูกต้อง']);
}

// ตรวจว่ามีข้อมูลจริงไหม
$chk = $pdo->prepare('SELECT customer_name FROM customers WHERE customer_id=? LIMIT 1');
$chk->execute([$id]);
$cust = $chk->fetch(PDO::FETCH_ASSOC);
if (!$cust) {
  redirect_with(back_path(), ['error'=>'ไม่พบลูกค้า']);
}

// ลบ (ถ้ามี FK อ้างอิง เช่น sales ให้ฐานข้อมูลบล็อกไว้เอง และเราดัก error)
try {
  $del = $pdo->prepare('DELETE FROM customers WHERE customer_id=? LIMIT 1');
  $del->execute([$id]);
  if ($del->rowCount() > 0) {
    redirect_with(back_path(), ['ok'=>'ลบลูกค้าเรียบร้อย']);
  }
  redirect_with(back_path(), ['error'=>'ลบไม่สำเร็จ']);
} catch (PDOException $e) {
  if (($e->errorInfo[1] ?? null) === 1451) {
    redirect_with(back_path(), ['error'=>'ไม่สามารถลบ: ข้อมูลถูกอ้างอิงอยู่']);
  }
  redirect_with(back_path(), ['error'=>'เกิดข้อผิดพลาด: '.$e->getCode()]);
}
?>