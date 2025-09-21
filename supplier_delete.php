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

/* ---------- helpers ---------- */
function back_path(): string {
  if (!empty($_POST['return'])) {
    $p = parse_url($_POST['return'], PHP_URL_PATH);
    if ($p) return $p;
  }
  if (!empty($_SERVER['HTTP_REFERER'])) {
    $p = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH);
    if ($p) return $p;
  }
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
function column_exists(PDO $pdo, string $table, string $col): bool {
  $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
  $q->execute([$table, $col]); return (int)$q->fetchColumn() > 0;
}
/** ดึงรายชื่อ (TABLE_NAME, COLUMN_NAME) ที่มี FK อ้างอิง suppliers.supplier_id จริง ๆ */
function get_real_fk_refs(PDO $pdo, string $refTable, string $refColumn): array {
  $sql = "
    SELECT TABLE_NAME, COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND REFERENCED_TABLE_NAME = ?
      AND REFERENCED_COLUMN_NAME = ?
  ";
  $stm = $pdo->prepare($sql);
  $stm->execute([$refTable, $refColumn]);
  return $stm->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
/** เดาชื่อคอลัมน์ที่น่าจะเป็น FK ของตารางที่กำหนด */
function guess_fk_col(PDO $pdo, string $table): ?string {
  foreach (['supplier_id','suppliers_id','sup_id','supplier','supplierId','vendor_id','vendor'] as $c) {
    if (column_exists($pdo, $table, $c)) return $c;
  }
  return null;
}

/* ---------- main ---------- */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) redirect_with(back_path(), ['error'=>'พารามิเตอร์ไม่ถูกต้อง']);

/* ตรวจข้อมูลผู้ขายก่อนลบ */
$chk = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE `supplier_id`=? LIMIT 1");
$chk->execute([$id]);
$row = $chk->fetch(PDO::FETCH_ASSOC);
if (!$row) redirect_with(back_path(), ['error'=>'ไม่พบผู้ขาย']);

/* เช็กลิงก์อ้างอิงก่อนลบ */
$used = [];

/* 1) ใช้ FK จริงใน DB ถ้ามี */
$fkPairs = get_real_fk_refs($pdo, 'suppliers', 'supplier_id');
if ($fkPairs) {
  foreach ($fkPairs as $fk) {
    $t = $fk['TABLE_NAME']; $c = $fk['COLUMN_NAME'];
    if (!table_exists($pdo,$t) || !column_exists($pdo,$t,$c)) continue;
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$c`=?");
    $cnt->execute([$id]); $n = (int)$cnt->fetchColumn();
    if ($n > 0) $used[] = "ถูกใช้ใน {$t}: {$n} รายการ";
  }
}

/* 2) ถ้าไม่มี FK จริง (หรือยังว่าง) ให้ fallback เช็คตารางที่คาดว่าอ้างอิง */
if (!$fkPairs) {
  foreach (['machine_expenses','acquisitions'] as $t) {
    if (!table_exists($pdo,$t)) continue;
    if ($c = guess_fk_col($pdo,$t)) {
      $cnt = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$c`=?");
      $cnt->execute([$id]); $n = (int)$cnt->fetchColumn();
      if ($n > 0) $used[] = "ถูกใช้ใน {$t}: {$n} รายการ";
    }
  }
}

if ($used) {
  redirect_with(back_path(), ['error'=> 'ไม่สามารถลบ "'.$row['supplier_name'].'" เพราะ '.implode(' / ', $used)]);
}

/* ลบจริง */
try {
  $del = $pdo->prepare("DELETE FROM suppliers WHERE `supplier_id`=? LIMIT 1");
  $del->execute([$id]);
  if ($del->rowCount() > 0) {
    redirect_with(back_path(), ['ok'=>'ลบผู้ขายเรียบร้อย']);
  } else {
    redirect_with(back_path(), ['error'=>'ลบไม่สำเร็จ']);
  }
} catch (PDOException $e) {
  // 1451: Cannot delete or update a parent row: a foreign key constraint fails
  if (($e->errorInfo[1] ?? null) === 1451) {
    redirect_with(back_path(), ['error'=>'ไม่สามารถลบ: ข้อมูลถูกอ้างอิงอยู่']);
  }
  redirect_with(back_path(), ['error'=>'เกิดข้อผิดพลาด: '.$e->getMessage()]);
}
