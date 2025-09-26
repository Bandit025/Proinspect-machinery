<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1)!==2) { header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: receipts.php?error=' . urlencode('วิธีการเรียกไม่ถูกต้อง')); exit;
}
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  header('Location: receipts.php?error=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
}

function back_path(): string {
  if (!empty($_POST['return'])) { $p=parse_url($_POST['return'],PHP_URL_PATH); if($p) return $p; }
  if (!empty($_SERVER['HTTP_REFERER'])) { $p=parse_url($_SERVER['HTTP_REFERER'],PHP_URL_PATH); if($p) return $p; }
  return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/receipts.php';
}
function redirect_with($path,$params){ $sep=strpos($path,'?')!==false?'&':'?'; header('Location: '.$path.$sep.http_build_query($params)); exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id<=0) redirect_with(back_path(), ['error'=>'พารามิเตอร์ไม่ถูกต้อง']);

$chk = $pdo->prepare("SELECT ref_no FROM receipts_in WHERE receipt_id=? LIMIT 1");
$chk->execute([$id]); $rec = $chk->fetch(PDO::FETCH_ASSOC);
if (!$rec) redirect_with(back_path(), ['error'=>'ไม่พบรายการ']);

$del = $pdo->prepare("DELETE FROM receipts_in WHERE receipt_id=? LIMIT 1");
$del->execute([$id]);

if ($del->rowCount()>0) {
  if (!empty($rec['ref_no']) && file_exists(__DIR__ . '/' . $rec['ref_no'])) @unlink(__DIR__ . '/' . $rec['ref_no']);
  redirect_with(back_path(), ['ok'=>'ลบใบรับเงินเรียบร้อย']);
}
redirect_with(back_path(), ['error'=>'ลบไม่สำเร็จ']);
