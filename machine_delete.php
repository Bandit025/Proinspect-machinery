<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: machines.php?error=' . urlencode('วิธีการเรียกไม่ถูกต้อง')); exit;
}
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  header('Location: machines.php?error=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
}

/* ===== helpers ===== */
function table_exists(PDO $pdo, string $t): bool {
  $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
  $q->execute([$t]); return (int)$q->fetchColumn()>0;
}
function safe_return_url(string $fallback = '/machines.php'): string {
  $candidates = [];
  if (!empty($_POST['return']))        $candidates[] = $_POST['return'];
  if (!empty($_SERVER['HTTP_REFERER']))$candidates[] = $_SERVER['HTTP_REFERER'];
  foreach ($candidates as $u) {
    $parts = @parse_url($u);
    if ($parts === false) continue;
    if (!isset($parts['scheme']) && !isset($parts['host'])) {
      $path = $parts['path']  ?? $fallback;
      $qs   = isset($parts['query']) ? ('?' . $parts['query']) : '';
      if (strpos($path, '/') !== 0) $path = '/' . $path;
      return $path . $qs;
    }
  }
  return $fallback;
}
function redirect_with(string $path, array $params) {
  $sep = (strpos($path,'?')!==false) ? '&' : '?';
  header('Location: ' . $path . $sep . http_build_query($params)); exit;
}
/* =================== */

$back = safe_return_url('/machines.php');

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect_with($back, ['error'=>'พารามิเตอร์ไม่ถูกต้อง']);

/* ดึงข้อมูลคันรถ (ดึง * เพื่อความปลอดภัยเรื่องชื่อคอลัมน์) */
$chk = $pdo->prepare('SELECT * FROM machines WHERE machine_id=? LIMIT 1');
$chk->execute([$id]); 
$m = $chk->fetch(PDO::FETCH_ASSOC);
if (!$m) redirect_with($back, ['error'=>'ไม่พบข้อมูลรถ']);

$img = '';
if (array_key_exists('image_path',$m) && $m['image_path']) $img = (string)$m['image_path'];
elseif (array_key_exists('photo_main',$m) && $m['photo_main']) $img = (string)$m['photo_main'];

/* กันลบถ้ามีการอ้างอิง */
$used = [];
if (table_exists($pdo,'acquisitions')) {
  $c=$pdo->prepare('SELECT COUNT(*) FROM acquisitions WHERE machine_id=?'); $c->execute([$id]);
  if ((int)$c->fetchColumn()>0) $used[]='ถูกใช้ในเอกสารซื้อ (acquisitions)';
}
if (table_exists($pdo,'sales')) {
  $c=$pdo->prepare('SELECT COUNT(*) FROM sales WHERE machine_id=?'); $c->execute([$id]);
  if ((int)$c->fetchColumn()>0) $used[]='ถูกใช้ในเอกสารขาย (sales)';
}
if (table_exists($pdo,'machine_expenses')) {
  $c=$pdo->prepare('SELECT COUNT(*) FROM machine_expenses WHERE machine_id=?'); $c->execute([$id]);
  if ((int)$c->fetchColumn()>0) $used[]='มีค่าใช้จ่ายผูกกับคันนี้ (machine_expenses)';
}
if (table_exists($pdo,'machine_status_history')) {
  $c=$pdo->prepare('SELECT COUNT(*) FROM machine_status_history WHERE machine_id=?'); $c->execute([$id]);
  if ((int)$c->fetchColumn()>0) $used[]='มีประวัติสถานะ (machine_status_history)';
}

if ($used) redirect_with($back, ['error'=>'ไม่สามารถลบ: '.implode(' / ', $used)]);

/* ลบ */
try {
  $del = $pdo->prepare('DELETE FROM machines WHERE machine_id=? LIMIT 1');
  $del->execute([$id]);
  if ($del->rowCount() > 0) {
    if ($img) {
      $full = __DIR__ . '/' . ltrim($img,'/');
      if (is_file($full)) @unlink($full);
    }
    redirect_with($back, ['ok'=>'ลบรถเรียบร้อย']);
  }
  redirect_with($back, ['error'=>'ลบไม่สำเร็จ']);
} catch (PDOException $e) {
  if (($e->errorInfo[1] ?? null) === 1451) {
    redirect_with($back, ['error'=>'ไม่สามารถลบ: ข้อมูลถูกอ้างอิงอยู่']);
  }
  redirect_with($back, ['error'=>'เกิดข้อผิดพลาด: '.$e->getCode()]);
}
