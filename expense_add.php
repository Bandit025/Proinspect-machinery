<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$catMap = [
  'maintenance' => 'บำรุงรักษา',
  'parts' => 'อะไหล่',
  'transport' => 'ขนส่ง',
  'registration' => 'จดทะเบียน',
  'inspection' => 'ตรวจสภาพ',
  'brokerage' => 'ค่านายหน้า',
  'selling' => 'ค่าใช้จ่ายการขาย',
  'other' => 'อื่น ๆ'
];
$machines = $pdo->query("SELECT
  m.machine_id,
  m.code,
  b.brand_name,
  mo.model_name,
  ms.status_name
FROM machines m
JOIN models mo     ON mo.model_id = m.model_id
JOIN brands b      ON b.brand_id = mo.brand_id
JOIN machine_status ms ON ms.status_id = m.status
WHERE m.status IN (2,3,5)
ORDER BY m.machine_id DESC;
")->fetchAll(PDO::FETCH_ASSOC);
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

function exp_status_label($x)
{
  return [1 => 'รอดำเนินการ', 2 => 'ออก P/O', 3 => 'ชำระแล้ว', 4 => 'เสร็จสิ้น', 9 => 'ยกเลิก'][$x] ?? 'รอดำเนินการ';
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  // ----- รับค่า -----
  $machine_id_raw = trim($_POST['machine_id'] ?? '');
  $machine_id     = ($machine_id_raw === '' ? null : (int)$machine_id_raw); // ว่าง = NULL

  $category    = trim($_POST['category'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $occurred_at = trim($_POST['occurred_at'] ?? '');
  $qty         = ($_POST['qty'] !== '' ? (float)$_POST['qty'] : 1.00);

  // รองรับมีคอมม่าในตัวเลข
  $unit_cost_raw = $_POST['unit_cost'] ?? '';
  $unit_cost     = ($unit_cost_raw !== '' ? (float)str_replace(',', '', $unit_cost_raw) : 0.00);

  $total_cost = round($qty * $unit_cost, 2);

  // วันที่ผิดรูปแบบ → ตั้งเป็นวันนี้
  if ($occurred_at === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $occurred_at)) {
    $occurred_at = date('Y-m-d');
  }

  // ถ้ามี machine_id แต่ไม่พบใน machines → บันทึกเป็น NULL กัน error FK
  if ($machine_id !== null) {
    $chk = $pdo->prepare("SELECT 1 FROM machines WHERE machine_id=? LIMIT 1");
    $chk->execute([$machine_id]);
    if (!$chk->fetch()) $machine_id = null;
  }

  if (empty($errors)) {
    // ถ้าตารางคุณไม่มีคอลัมน์ 'expenses' ให้ใช้ SQL ชุดล่าง (คอมเมนต์ไว้) แทน
    $sql = "INSERT INTO machine_expenses
              (machine_id, category, description, occurred_at, qty, unit_cost, total_cost, expenses)
            VALUES
              (:mid, :cat, :des, :dt, :qty, :unit, :total, :exp)";

    // $sql = "INSERT INTO machine_expenses
    //           (machine_id, category, description, occurred_at, qty, unit_cost, total_cost)
    //         VALUES
    //           (:mid, :cat, :des, :dt, :qty, :unit, :total)";

    $stmt = $pdo->prepare($sql);


    // bind ค่า
    ($machine_id === null)
      ? $stmt->bindValue(':mid', null, PDO::PARAM_NULL)
      : $stmt->bindValue(':mid', $machine_id, PDO::PARAM_INT);

    // ช่องข้อความ ว่าง = NULL
    $stmt->bindValue(':cat', ($category !== '' ? $category : null), PDO::PARAM_STR);
    $stmt->bindValue(':des', ($description !== '' ? $description : null), PDO::PARAM_STR);
    $stmt->bindValue(':dt',   $occurred_at, PDO::PARAM_STR);

    // ตัวเลข bind แบบธรรมดา
    $stmt->bindValue(':qty',   $qty);
    $stmt->bindValue(':unit',  $unit_cost);
    $stmt->bindValue(':total', $total_cost);

    // ถ้าใช้คอลัมน์ expenses
    if (strpos($sql, ':exp') !== false) {
      $stmt->bindValue(':exp', $total_cost);
    }

    $stmt->execute();

    $acquire_id = $pdo->lastInsertId();


    if ($machine_id == null) {

      $type2_cashflow = 4;
    } else {
      $type2_cashflow = 5;
    }

    $type_cashflow = 2;
    $doc_date = date('Y-m-d');
    $amount  = $total_cost;
    $remark = $description;


    $sql_cashflow = "INSERT INTO cashflow (doc_date, type_cashflow, type2_cashflow, expense_id, amount, remark) VALUES (:doc_date, :type, :type2, :expense_id, :amount, :remark)";
    $stmt2 = $pdo->prepare($sql_cashflow);
    $stmt2->bindValue(':doc_date', $occurred_at, PDO::PARAM_STR);
    $stmt2->bindValue(':type', $type_cashflow, PDO::PARAM_INT);
    $stmt2->bindValue(':type2', $type2_cashflow, PDO::PARAM_INT);
    $stmt2->bindValue(':expense_id', $acquire_id, PDO::PARAM_INT);
    $stmt2->bindValue(':amount', $amount);
    $stmt2->bindValue(':remark', $remark, PDO::PARAM_STR);
    $stmt2->execute();

    header('Location: expenses.php?ok=' . urlencode('บันทึกค่าใช้จ่ายเรียบร้อย'));
    exit;
  }
}

?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>เพิ่มค่าใช้จ่าย — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=19">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    function calcPreview() {
      const qty = parseFloat(document.querySelector('[name="qty"]').value || '0');
      const unit = parseFloat(document.querySelector('[name="unit_cost"]').value || '0');
      const comm = parseFloat(document.querySelector('[name="commission_amt"]').value || '0');
      const total = (qty * unit) + comm;
      document.getElementById('sum').textContent = isNaN(total) ? '-' : total.toFixed(2);
    }
  </script>
</head>

<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">เพิ่มค่าใช้จ่าย</h2>
        <div class="page-sub">ผูกกับรถและผู้ขาย/ผู้รับจ้าง</div>
      </div>

      <?php if ($errors): ?><div class="alert alert-danger">
          <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div><?php endif; ?>

      <section class="card">
        <form method="post" id="addForm" novalidate>
          <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <label>รถ</label>
          <select class="select" name="machine_id" required>
            <option value="">— เลือก —</option>
            <?php foreach ($machines as $m): ?>
              <option value="<?= $m['machine_id'] ?>" <?= (($_POST['machine_id'] ?? '') == $m['machine_id']) ? 'selected' : ''; ?>>
                <?= htmlspecialchars($m['code'] . ' — ' . $m['brand_name'] . ' ' . $m['model_name'] . ' ' . $m['status_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <div class="row" style="margin-top:10px;">

            <div>
              <label>ประเภท</label>
              <?php $SQL = "SELECT * FROM expense_type";
              $stmt = $pdo->query($SQL);
              $expense_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
              ?>
              <select class="select" name="category" required>
                <option value=" " selected>เลือก</option>
                <?php foreach ($expense_types as $type) { ?>
                  <option value="<?= $type['ex_id'] ?>"><?= $type['ex_name'] ?></option>
                <?php } ?>

              </select>
            </div>
          </div>
          <div class="row" style="margin-top:10px;">

            <div>
              <label>วันที่เกิดรายการ</label>
              <input class="input" type="date" name="occurred_at" required value="<?= htmlspecialchars($_POST['occurred_at'] ?? date('Y-m-d')) ?>">
            </div>
          </div>

          <label style="margin-top:10px;">คำอธิบาย</label>
          <input class="input" type="text" name="description" value="<?= htmlspecialchars($_POST['description'] ?? '') ?>">

          <div class="row" style="margin-top:10px;">
            <div><label>จำนวน (Qty)</label><input class="input" type="number" step="0.01" name="qty" value="<?= htmlspecialchars($_POST['qty'] ?? '1.00') ?>" oninput="calcPreview()"></div>
            <div><label>ราคา/หน่วย</label><input class="input" type="number" step="0.01" name="unit_cost" value="<?= htmlspecialchars($_POST['unit_cost'] ?? '0.00') ?>" oninput="calcPreview()"></div>
          </div>
          <div style="margin-top:8px;" class="muted">ยอดรวม (คำนวณ): ฿<span id="sum">0.00</span></div>

          <div style="margin-top:14px;display:flex;gap:8px;">
            <button class="btn btn-brand" type="submit">บันทึก</button>
            <a class="btn btn-outline" href="expenses.php">ยกเลิก</a>
          </div>
        </form>
      </section>
    </main>
  </div>
    <script src="/assets/script.js?v=4"></script>
  <script>
    document.addEventListener('DOMContentLoaded', calcPreview);
    document.getElementById('addForm').addEventListener('submit', (e) => {
      e.preventDefault();
      const mid = (document.querySelector('[name="machine_id"]').value || '');

      Swal.fire({
          icon: 'question',
          title: 'ยืนยันการบันทึก?',
          showCancelButton: true,
          confirmButtonText: 'บันทึก',
          cancelButtonText: 'ยกเลิก',
          reverseButtons: true,
          confirmButtonColor: '#fec201'
        })
        .then(res => {
          if (res.isConfirmed) e.target.submit();
        });
    });
  </script>
</body>

</html>