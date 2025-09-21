<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
  exit;
}
$user = $_SESSION['user'];

/* ---------- ชื่อสิทธิ์ผู้ใช้ ---------- */
$roleVal  = (int)($user['role'] ?? 1);
$roleName = [1=>'ผู้ใช้งาน', 2=>'ผู้ดูแลระบบ', 3=>'ผู้เยี่ยมชม'][$roleVal] ?? 'ผู้ใช้งาน';

/* ---------- ตัวเลขสรุป ---------- */
$row_1 = $pdo->query("SELECT SUM(purchase_price) AS total_purchase_price FROM machines WHERE status IN ('2','3','5')")->fetch(PDO::FETCH_ASSOC);
$total_value_stock = (float)($row_1['total_purchase_price'] ?? 0);

$row_2 = $pdo->query("SELECT COUNT(*) AS total FROM machines WHERE status='2'")->fetch(PDO::FETCH_ASSOC);
$total_count_machines_ready = (int)($row_2['total'] ?? 0);

$row_3 = $pdo->query("SELECT COUNT(*) AS total FROM machines WHERE status='5'")->fetch(PDO::FETCH_ASSOC);
$total_count_machines_fix = (int)($row_3['total'] ?? 0);

$row_4 = $pdo->query("SELECT COUNT(*) AS total FROM machines WHERE status='4'")->fetch(PDO::FETCH_ASSOC);
$total_count_sale = (int)($row_4['total'] ?? 0);

$row_5 = $pdo->query("SELECT SUM(total_amount) AS total_sale_price FROM sales")->fetch(PDO::FETCH_ASSOC);
$total_value_machine_sale = (float)($row_5['total_sale_price'] ?? 0);

/* รายรับ-รายจ่าย เดือนนี้ */
$sql_expense_month = "
  SELECT COALESCE(SUM(amount),0) AS total_expense
  FROM cashflow
  WHERE type_cashflow = 2
    AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    AND created_at <  DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
";
$row_6 = $pdo->query($sql_expense_month)->fetch(PDO::FETCH_ASSOC);
$total_expense_month = (float)($row_6['total_expense'] ?? 0);

$sql_income_month = "
  SELECT COALESCE(SUM(amount),0) AS total_income
  FROM cashflow
  WHERE type_cashflow = 1
    AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
    AND created_at <  DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
";
$row_7 = $pdo->query($sql_income_month)->fetch(PDO::FETCH_ASSOC);
$total_income_month = (float)($row_7['total_income'] ?? 0);

/* กำไรเดือนนี้ (เก็บทั้งตัวเลขจริงและฟอร์แมต) */
$profit_month     = $total_income_month - $total_expense_month;
$profit_month_fmt = number_format($profit_month, 2);

/* ---------- สต๊อกล่าสุด 5 รายการ ---------- */
$sql_stock = "
  SELECT a.*, b.model_name, c.brand_name
  FROM machines a
  JOIN models b ON a.model_id = b.model_id 
  JOIN brands c ON b.brand_id = c.brand_id
  WHERE a.status IN ('2','3','5')
  ORDER BY a.created_at DESC
  LIMIT 5
";
$rows_stock = $pdo->query($sql_stock)->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>แดชบอร์ด — ProInspect Machinery</title>
  <!-- ใช้ style.css เวอร์ชันล่าสุดที่มี .grid/.grid-3/.num-xl/.hide-sm/.td-right -->
  <link rel="stylesheet" href="/assets/style.css?v=40">
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>

<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  

  <main class="content">
    <!-- Page Head -->
    <div class="page-head">
      <h2 class="page-title">แดชบอร์ด</h2>
      <div class="page-sub">
        ยินดีต้อนรับ <?= htmlspecialchars($user['name'] ?? '') ?> (สิทธิ์: <?= htmlspecialchars($roleName) ?>)
      </div>
    </div>

    <!-- สรุปสถานะโดยรวม -->
    <section class="card" style="margin-top:12px;">
      <div class="card-head">
        <h3 class="h5">สรุปสถานะโดยรวม</h3>
        <a class="btn btn-brand sm" href="machines.php">ดูทะเบียนรถ</a>
      </div>

      <!-- KPI ชุดที่ 1 -->
      <div class="grid grid-3">
        <div class="card" style="margin:0;">
          <div class="muted">มูลค่า STOCK สินค้า</div>
          <div class="num-xl"><?= number_format($total_value_stock, 2) ?></div>
          <div class="muted" style="font-size:.9rem;">อัปเดตล่าสุดวันนี้</div>
        </div>

        <div class="card" style="margin:0;">
          <div class="muted">ค่าใช้จ่ายเดือนนี้</div>
          <div class="num-xl" style="color:#A30000;"><?= number_format($total_expense_month, 2) ?></div>
          <div class="muted" style="font-size:.9rem;">อัปเดตล่าสุดวันนี้</div>
        </div>

        <div class="card" style="margin:0;">
          <div class="muted">กำไรเดือนนี้</div>
          <?php if ($profit_month < 0): ?>
            <div class="num-xl" style="color:#A30000;"><?= $profit_month_fmt ?></div>
          <?php else: ?>
            <div class="num-xl" style="color:#177500;"><?= $profit_month_fmt ?></div>
          <?php endif; ?>
          <div class="muted" style="font-size:.9rem;">ยอดขายเดือนนี้ <?= number_format($total_income_month, 2) ?></div>
        </div>
      </div>

      <br>

      <!-- KPI ชุดที่ 2 -->
      <div class="grid grid-3">
        <div class="card" style="margin:0;">
          <div class="muted">คันพร้อมขาย</div>
          <div class="num-xl"><?= number_format($total_count_machines_ready) ?></div>
          <div class="muted" style="font-size:.9rem;">อัปเดตล่าสุดวันนี้</div>
        </div>

        <div class="card" style="margin:0;">
          <div class="muted">กำลังซ่อมบำรุง</div>
          <div class="num-xl"><?= number_format($total_count_machines_fix) ?></div>
          <div class="muted" style="font-size:.9rem;">มีงานค้าง 2 รายการ</div>
        </div>

        <div class="card" style="margin:0;">
          <div class="muted">ขายเดือนนี้</div>
          <div class="num-xl"><?= number_format($total_count_sale) ?></div>
          <div class="muted" style="font-size:.9rem;">ยอดสุทธิ ~ <?= number_format($total_value_machine_sale, 2) ?></div>
        </div>
      </div>
    </section>

    <!-- ตารางสต๊อกล่าสุด -->
    <section class="card" style="margin-top:20px;">
      <div class="card-head">
        <h3 class="h5">สต๊อกล่าสุด</h3>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>ทะเบียน</th>
              <th>ยี่ห้อ / รุ่น</th>
              <th class="hide-sm">ปี</th>
              <th class="hide-sm">สถานะ</th>
              <th class="td-right">ราคาตั้ง</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows_stock as $s): 
            $status = (int)($s['status'] ?? 0);
          ?>
            <tr>
              <td><?= htmlspecialchars($s['code'] ?? '') ?></td>
              <td><?= htmlspecialchars(($s['brand_name'] ?? '') . ' ' . ($s['model_name'] ?? '')) ?></td>
              <td class="hide-sm"><?= htmlspecialchars((string)($s['model_year'] ?? '')) ?></td>
              <td class="hide-sm">
                <?php if ($status === 2): ?>
                  <span class="badge success">พร้อมขาย</span>
                <?php elseif ($status === 3): ?>
                  <span class="badge warn">กำลังซ่อมบำรุง</span>
                <?php elseif ($status === 5): ?>
                  <span class="badge gray">จองแล้ว</span>
                <?php endif; ?>
              </td>
              <td class="td-right"><?= number_format((float)($s['purchase_price'] ?? 0), 2) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>
</div>

<script src="assets/script.js"></script>
<?php
$ok  = $_GET['ok']    ?? '';
$err = $_GET['error'] ?? '';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<?php if ($err): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  Swal.fire({
    icon: 'error',
    title: 'ไม่สำเร็จ',
    text: <?= json_encode($err, JSON_UNESCAPED_UNICODE) ?>,
    confirmButtonText: 'OK',
    confirmButtonColor: '#fec201',
    allowOutsideClick: false,
    allowEscapeKey: false
  }).then(function () {
    if (window.history.replaceState) {
      const url = new URL(window.location.href);
      url.searchParams.delete('error');
      url.searchParams.delete('from');
      const q = url.searchParams.toString();
      window.history.replaceState({}, document.title, url.pathname + (q ? '?' + q : '') + url.hash);
    }
  });
});
</script>
<?php endif; ?>
</body>
</html>
