<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: models.php?error=' . urlencode('วิธีการเรียกไม่ถูกต้อง')); exit;
}
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  header('Location: models.php?error=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
}

function back_path(): string {
  if (!empty($_POST['return'])) { $p = parse_url($_POST['return'], PHP_URL_PATH); if ($p) return $p; }
  if (!empty($_SERVER['HTTP_REFERER'])) { $p = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH); if ($p) return $p; }
  return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/models.php';
}
function redirect_with(string $path, array $params){
  $sep = strpos($path,'?')!==false ? '&' : '?';
  header('Location: '.$path.$sep.http_build_query($params)); exit;
}
function table_exists(PDO $pdo, string $t): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
  $q->execute([$t]); return (int)$q->fetchColumn() > 0;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect_with(back_path(), ['error'=>'พารามิเตอร์ไม่ถูกต้อง']);

$stm = $pdo->prepare('SELECT model_name FROM models WHERE model_id=? LIMIT 1');
$stm->execute([$id]); $row = $stm->fetch(PDO::FETCH_ASSOC);
if (!$row) redirect_with(back_path(), ['error'=>'ไม่พบรุ่นที่ต้องการลบ']);

if (table_exists($pdo,'machines')) {
  $cnt = $pdo->prepare('SELECT COUNT(*) FROM machines WHERE model_id=?');
  $cnt->execute([$id]); $n = (int)$cnt->fetchColumn();
  if ($n > 0) redirect_with(back_path(), ['error'=>'ไม่สามารถลบ "'.$row['model_name'].'" เพราะถูกใช้อยู่ในรถ '.$n.' คัน']);
}

try {
  $del = $pdo->prepare('DELETE FROM models WHERE model_id=? LIMIT 1');
  $del->execute([$id]);
  if ($del->rowCount() > 0) redirect_with(back_path(), ['ok'=>'ลบรุ่นเรียบร้อย']);
  redirect_with(back_path(), ['error'=>'ลบไม่สำเร็จ']);
} catch (PDOException $e) {
  if (($e->errorInfo[1] ?? null) === 1451) {
    redirect_with(back_path(), ['error'=>'ไม่สามารถลบ: ข้อมูลถูกอ้างอิงอยู่']);
  }
  redirect_with(back_path(), ['error'=>'เกิดข้อผิดพลาด: '.$e->getCode()]);
}
