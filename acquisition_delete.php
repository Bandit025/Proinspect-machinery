<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: acquisitions.php?error=' . urlencode('วิธีการเรียกไม่ถูกต้อง')); exit;
}
if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
  header('Location: acquisitions.php?error=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
}

function back_path(): string {
  if (!empty($_POST['return'])) { $p=parse_url($_POST['return'],PHP_URL_PATH); if($p) return $p; }
  if (!empty($_SERVER['HTTP_REFERER'])) { $p=parse_url($_SERVER['HTTP_REFERER'],PHP_URL_PATH); if($p) return $p; }
  return rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/acquisitions.php';
}
function redirect_with($path,$params){ $sep=strpos($path,'?')!==false?'&':'?'; header('Location: '.$path.$sep.http_build_query($params)); exit; }

$id = (int)($_POST['id'] ?? 0);
if ($id<=0) redirect_with(back_path(), ['error'=>'พารามิเตอร์ไม่ถูกต้อง']);


$sql_cashflow = "DELETE FROM cashflow WHERE acquisition_id=?";
$stm = $pdo->prepare($sql_cashflow);
$stm->execute([$id]);

$del = $pdo->prepare("DELETE FROM acquisitions WHERE acquisition_id=? LIMIT 1");
$del->execute([$id]);

if ($del->rowCount()>0) redirect_with(back_path(), ['ok'=>'ลบเอกสารเรียบร้อย']);
redirect_with(back_path(), ['error'=>'ลบไม่สำเร็จ']);
