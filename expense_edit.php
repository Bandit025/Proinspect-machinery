<?php
require __DIR__ . '/config.php';

// ตรวจสอบการล็อกอิน (ถ้ามีระบบสิทธิ์ใช้งานให้เพิ่มเงื่อนไขที่นี่)
// if (empty($_SESSION['user'])) { header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน')); exit; }

// CSRF
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// helper กัน null สำหรับแสดงผล HTML
function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// หมวดหมู่ (ตัวเลขให้ตรงกับที่หน้าเพิ่มใช้)
$catOptions = [
  '1'  => 'ซ่อม',
  '2'  => 'บำรุงรักษา',
  '3'  => 'อะไหล่',
  '4'  => 'ขนส่ง',
  '5'  => 'จดทะเบียน',
  '6'  => 'ตรวจสภาพ',
  '7'  => 'ค่านายหน้า',
  '8'  => 'ค่าใช้จ่ายการขาย',
  '9'  => 'ค่าแรง',
  '10' => 'อื่น ๆ',
];

// โหลดรายการรถ (ใช้เหมือนหน้าเพิ่ม)
$machines = $pdo->query("
  SELECT
    m.machine_id,
    m.code,
    b.brand_name,
    mo.model_name,
    ms.status_name
  FROM machines m
  JOIN models mo     ON mo.model_id = m.model_id
  JOIN brands b      ON b.brand_id = mo.brand_id
  JOIN machine_status ms ON ms.status_id = m.status
  WHERE m.status != 4
  ORDER BY m.machine_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// รับ id จาก GET/POST
$expense_id = (int)($_GET['id'] ?? $_POST['expense_id'] ?? 0);
if ($expense_id <= 0) {
  header('Location: expenses.php?error=' . urlencode('ไม่พบรายการที่ต้องการแก้ไข')); exit;
}

// ดึงข้อมูลรายการเดิม
$stm = $pdo->prepare("
  SELECT e.*, m.code, b.brand_name, mo.model_name, ms.status_name
  FROM machine_expenses e
  JOIN machines m  ON m.machine_id = e.machine_id
  JOIN models mo   ON mo.model_id = m.model_id
  JOIN brands b    ON b.brand_id = mo.brand_id
  JOIN machine_status ms ON ms.status_id = m.status
  WHERE e.expense_id = ?
  LIMIT 1
");
$stm->execute([$expense_id]);
$exp = $stm->fetch(PDO::FETCH_ASSOC);

if (!$exp) {
  header('Location: expenses.php?error=' . urlencode('ไม่พบรายการที่ต้องการแก้ไข')); exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ตรวจ CSRF
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  // รับค่า
  $machine_id  = (int)($_POST['machine_id'] ?? 0);
  $category    = trim($_POST['category'] ?? '');
  $occurred_at = trim($_POST['occurred_at'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $qty         = ($_POST['qty'] !== '' ? (float)$_POST['qty'] : 1.00);
  $unit_cost   = ($_POST['unit_cost'] !== '' ? (float)$_POST['unit_cost'] : 0.00);

  // ตรวจค่าขั้นพื้นฐาน
  if ($machine_id <= 0)   $errors[] = 'เลือกรถ';
  if ($occurred_at === '')$errors[] = 'เลือกวันที่เกิดรายการ';
  if ($qty <= 0)          $errors[] = 'จำนวนต้องมากกว่า 0';
  if ($unit_cost < 0)     $unit_cost = 0.00;
  if ($category === '' || !isset($catOptions[$category])) $errors[] = 'เลือกประเภทให้ถูกต้อง';

  // คำนวณยอดรวม
  $total_cost = $qty * $unit_cost;

  echo '<pre>';
  echo 'POST data:' . PHP_EOL;
  echo 'expense_id: ' . $expense_id . PHP_EOL;
  echo 'Machine ID: ' . $machine_id . PHP_EOL;
  echo 'Category: ' . $category . PHP_EOL;
  echo 'Description: ' . $description . PHP_EOL;
  echo 'Occurred At: ' . $occurred_at . PHP_EOL;
  echo 'Quantity: ' . $qty . PHP_EOL;
  echo 'Unit Cost: ' . $unit_cost . PHP_EOL;
  echo 'Total Cost: ' . $total_cost . PHP_EOL;
  echo '</pre>';


  // $sql_cashflow = "SELECT * FROM cashflow WHERE expense_id = $expense_id LIMIT 1";
  $sql_cashflow = "UPDATE cashflow SET 
                                      amount = $total_cost,   
                                      updated_at = '$occurred_at' 
                                      WHERE expense_id = $expense_id 
                                      LIMIT 1";
  // echo $sql_cashflow;
  $cf = $pdo->query($sql_cashflow)->fetch(PDO::FETCH_ASSOC);
  // exit;
  if (!$errors) {
    // อัปเดตข้อมูล
    $upd = $pdo->prepare("
      UPDATE machine_expenses
      SET machine_id = ?,
          category   = ?,
          description= ?,
          occurred_at= ?,
          qty        = ?,
          unit_cost  = ?,
          total_cost = ?,
          expenses   = ?
      WHERE expense_id = ?
      LIMIT 1
    ");
    $ok = $upd->execute([
      $machine_id,
      $category,
      $description,
      $occurred_at,
      $qty,
      $unit_cost,
      $total_cost,
      $total_cost,
      $expense_id
    ]);



    if ($ok) {
      // หมายเหตุ: หน้านี้ไม่ได้แก้ไข cashflow เพราะในสคีมาเดิมไม่มีการผูก expense_id กับ cashflow
      // ถ้าต้องปรับ cashflow ให้ทำความเชื่อมโยงก่อน (เช่น เพิ่มคอลัมน์ expense_id ใน cashflow แล้วค่อย UPDATE)
      header('Location: expenses.php?ok=' . urlencode('แก้ไขค่าใช้จ่ายเรียบร้อย')); exit;
    } else {
      $errors[] = 'ไม่สามารถแก้ไขข้อมูลได้';
    }
  }

  // อัปเดตตัวแปร $exp เพื่อสะท้อนค่าที่ผู้ใช้โพสต์ หากมี error
  $exp = array_merge($exp, [
    'machine_id'  => $machine_id,
    'category'    => $category,
    'occurred_at' => $occurred_at,
    'description' => $description,
    'qty'         => $qty,
    'unit_cost'   => $unit_cost,
    'total_cost'  => $total_cost ?? ($exp['total_cost'] ?? 0),
  ]);
}

// ค่าที่จะโชว์ในฟอร์ม
$val_machine_id  = (int)($exp['machine_id'] ?? 0);
$val_category    = (string)($exp['category'] ?? '');
$val_occurred_at = ($exp['occurred_at'] ?? date('Y-m-d'));
$val_description = ($exp['description'] ?? '');
$val_qty         = (string)($exp['qty'] ?? '1.00');
$val_unit_cost   = (string)($exp['unit_cost'] ?? '0.00');
$val_total       = (float)($exp['total_cost'] ?? ( (float)$val_qty * (float)$val_unit_cost ));
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>แก้ไขค่าใช้จ่าย — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=19">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    function calcPreview() {
      const qty = parseFloat(document.querySelector('[name="qty"]').value || '0');
      const unit = parseFloat(document.querySelector('[name="unit_cost"]').value || '0');
      const total = qty * unit;
      document.getElementById('sum').textContent = isNaN(total) ? '-' : total.toFixed(2);
    }
  </script>
  <style>
    .row { display: grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: 10px; }
  </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">แก้ไขค่าใช้จ่าย</h2>
      <div class="page-sub">ปรับปรุงข้อมูลรายการค่าใช้จ่ายเดิม</div>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul style="margin:0 0 0 18px;">
          <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <section class="card">
      <form method="post" id="editForm" novalidate>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="expense_id" value="<?= (int)$expense_id ?>">

        <label>รถ</label>
        <select class="select" name="machine_id" required>
          <option value="">— เลือก —</option>
          <?php foreach ($machines as $m): ?>
            <option value="<?= (int)$m['machine_id'] ?>" <?= ($val_machine_id == $m['machine_id']) ? 'selected' : '' ?>>
              <?= h($m['code'].' — '.$m['brand_name'].' '.$m['model_name'].' '.$m['status_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>ประเภท</label>
            <select class="select" name="category" required>
              <option value="">เลือก</option>
              <?php foreach ($catOptions as $k=>$v): ?>
                <option value="<?= h($k) ?>" <?= ((string)$val_category === (string)$k ? 'selected' : '') ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>วันที่เกิดรายการ</label>
            <input class="input" type="date" name="occurred_at" required value="<?= h(substr($val_occurred_at,0,10)) ?>">
          </div>
        </div>

        <label style="margin-top:10px;">คำอธิบาย</label>
        <input class="input" type="text" name="description" value="<?= h($val_description) ?>">

        <div class="row" style="margin-top:10px;">
          <div>
            <label>จำนวน (Qty)</label>
            <input class="input" type="number" step="0.01" name="qty" value="<?= h($val_qty) ?>" oninput="calcPreview()">
          </div>
          <div>
            <label>ราคา/หน่วย</label>
            <input class="input" type="number" step="0.01" name="unit_cost" value="<?= h($val_unit_cost) ?>" oninput="calcPreview()">
          </div>
        </div>

        <div style="margin-top:8px;" class="muted">ยอดรวม (คำนวณ): ฿<span id="sum"><?= number_format($val_total, 2) ?></span></div>

        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึกการแก้ไข</button>
          <a class="btn btn-outline" href="expenses.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>
<script src="/assets/script.js?v=4"></script>
<script>
document.addEventListener('DOMContentLoaded', calcPreview);
document.getElementById('editForm').addEventListener('submit', (e) => {
  e.preventDefault();
  const mid = (document.querySelector('[name="machine_id"]').value || '');
  if (!mid) {
    Swal.fire({ icon: 'warning', title: 'เลือกรถ', confirmButtonColor: '#fec201' });
    return;
  }
  Swal.fire({
    icon: 'question',
    title: 'ยืนยันการบันทึกการแก้ไข?',
    showCancelButton: true,
    confirmButtonText: 'บันทึก',
    cancelButtonText: 'ยกเลิก',
    reverseButtons: true,
    confirmButtonColor: '#fec201'
  }).then(res => { if (res.isConfirmed) e.target.submit(); });
});
</script>
</body>
</html>
