<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: suppliers.php?error=' . urlencode('วิธีการเรียกไม่ถูกต้อง')); exit;
}
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  header('Location: suppliers.php?error=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
}

function back_path(): string {
  if (!empty($_POST['return'])) { $p = parse_url($_POST['return'], PHP_URL_PATH); if ($p) return $p; }
  if (!empty($_SERVER['HTTP_REFERER'])) { $p = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH); if ($p) return $p; }
  return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/suppliers.php';
}
function redirect_with(string $path, array $params){
  $sep = (strpos($path,'?')!==false) ? '&' : '?';
  header('Location: '.$path.$sep.http_build_query($params)); exit;
}
function table_exists(PDO $pdo, string $t): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
  $q->execute([$t]); return (int)$q->fetchColumn() > 0;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect_with(back_path(), ['error'=>'พารามิเตอร์ไม่ถูกต้อง']);

$chk = $pdo->prepare('SELECT supplier_name FROM suppliers WHERE supplier_id=? LIMIT 1');
$chk->execute([$id]);
$row = $chk->fetch(PDO::FETCH_ASSOC);
if (!$row) redirect_with(back_path(), ['error'=>'ไม่พบผู้ขาย']);

$used = [];
if (table_exists($pdo,'machine_expenses')) {
  $cnt = $pdo->prepare('SELECT COUNT(*) FROM machine_expenses WHERE supplier_id=?');
  $cnt->execute([$id]); $n = (int)$cnt->fetchColumn();
  if ($n>0) $used[] = "ถูกใช้ใน machine_expenses: {$n} รายการ";
}
if (table_exists($pdo,'acquisitions')) {
  $cnt = $pdo->prepare('SELECT COUNT(*) FROM acquisitions WHERE supplier_id=?');
  $cnt->execute([$id]); $n = (int)$cnt->fetchColumn();
  if ($n>0) $used[] = "ถูกใช้ใน acquisitions: {$n} รายการ";
}
if ($used) {
  redirect_with(back_path(), ['error'=> 'ไม่สามารถลบ "'.$row['supplier_name'].'" เพราะ '.implode(' / ', $used)]);
}

try {
  $del = $pdo->prepare('DELETE FROM suppliers WHERE supplier_id=? LIMIT 1');
  $del->execute([$id]);
  if ($del->rowCount()>0) redirect_with(back_path(), ['ok'=>'ลบผู้ขายเรียบร้อย']);
  redirect_with(back_path(), ['error'=>'ลบไม่สำเร็จ']);
} catch (PDOException $e) {
  if (($e->errorInfo[1] ?? null) === 1451) {
    redirect_with(back_path(), ['error'=>'ไม่สามารถลบ: ข้อมูลถูกอ้างอิงอยู่']);
  }
  redirect_with(back_path(), ['error'=>'เกิดข้อผิดพลาด: '.$e->getCode()]);
}
