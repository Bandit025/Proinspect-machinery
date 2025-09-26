<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
  exit;
}
$isAdmin = ((int)($_SESSION['user']['role'] ?? 1) === 2);

$ok  = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function thb($n){ return $n !== null ? '฿' . number_format((float)$n, 2) : '-'; }

$catMap = [
  'repair'        => 'ซ่อม',
  'maintenance'   => 'บำรุงรักษา',
  'parts'         => 'อะไหล่',
  'transport'     => 'ขนส่ง',
  'registration'  => 'จดทะเบียน',
  'inspection'    => 'ตรวจสภาพ',
  'brokerage'     => 'ค่านายหน้า',
  'selling'       => 'ค่าใช้จ่ายการขาย',
  'other'         => 'อื่น ๆ'
];
function exp_status_label($x){
  return [1=>'รอดำเนินการ',2=>'ออก P/O',3=>'ชำระแล้ว',4=>'เสร็จสิ้น',9=>'ยกเลิก'][$x] ?? 'รอดำเนินการ';
}

$q   = trim($_GET['q'] ?? '');
$d1  = trim($_GET['d1'] ?? '');
$d2  = trim($_GET['d2'] ?? '');
$cat = trim($_GET['category'] ?? '');
$st  = (int)($_GET['status'] ?? 0);
$cap = $_GET['cap'] ?? ''; // '1','0','' (ทั้งหมด)

/* ===== Query หลัก: ดึง 1 แถว/คัน + เตรียมผลรวมต่อคัน ===== */
$params = [];
$sql = "
SELECT *
FROM (
  SELECT
  e.*,                       -- มี machine_id อยู่แล้ว
  f.code,
  h.brand_name,
  g.model_name,
  f.serial_no,
  f.hour_meter,
  f.code AS plate_no,
  ROW_NUMBER() OVER (
    PARTITION BY e.machine_id
    ORDER BY e.description ASC, e.expense_id ASC
  ) AS rn
  FROM machine_expenses e
  JOIN machines f ON e.machine_id = f.machine_id
  JOIN models   g ON f.model_id   = g.model_id
  JOIN brands   h ON g.brand_id   = h.brand_id
) t
WHERE t.rn = 1
";
if ($q !== '') {
  $sql .= " AND (t.code LIKE :q OR t.brand_name LIKE :q OR t.model_name LIKE :q
           OR t.plate_no LIKE :q OR t.supplier_name LIKE :q OR t.description LIKE :q OR t.remark LIKE :q)";
  $params[':q'] = "%{$q}%";
}
if ($d1 !== '') { $sql .= " AND t.occurred_at >= :d1"; $params[':d1'] = $d1; }
if ($d2 !== '') { $sql .= " AND t.occurred_at <= :d2"; $params[':d2'] = $d2; }
if ($cat !== '') { $sql .= " AND t.category = :cat";   $params[':cat'] = $cat; }
if ($st  > 0)    { $sql .= " AND t.status   = :st";    $params[':st']  = $st; }
if ($cap !== '' && in_array($cap, ['0','1'], true)) {
  $sql .= " AND t.capitalizable = :cap"; $params[':cap'] = $cap;
}
$sql .= " ORDER BY t.occurred_at DESC, t.expense_id DESC";

$stm = $pdo->prepare($sql);
$stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);

/* ผลรวมค่าใช้จ่ายต่อคัน */
$sumMap = [];
$ids = array_values(array_unique(array_column($rows, 'machine_id')));
if ($ids) {
  $ph = implode(',', array_fill(0, count($ids), '?'));
  $sqlSum = "SELECT machine_id, COALESCE(SUM(total_cost),0) AS total_cost_sum
             FROM machine_expenses WHERE machine_id IN ($ph) GROUP BY machine_id";
  $stSum = $pdo->prepare($sqlSum);
  $stSum->execute($ids);
  foreach ($stSum->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $sumMap[(int)$s['machine_id']] = (float)$s['total_cost_sum'];
  }
}
$cats = array_keys($catMap);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>ค่าใช้จ่ายรายคัน — ProInspect Machinery</title>
  <!-- ใช้ absolute path กันพาธเพี้ยน -->
  <link rel="stylesheet" href="assets/style.css?v=40">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">ค่าใช้จ่ายรายคัน</h2>
        <div class="page-sub">แสดง/ค้นหา/จัดการค่าใช้จ่ายก่อนขาย–เพื่อขาย–หลังซื้อ</div>
      </div>

      <?php if ($ok): ?><div class="alert alert-success"><?= htmlspecialchars($ok) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <section class="card">
        <div class="card-head">
          <h3 class="h5">รายการ</h3>

          <!-- ✅ Toolbar + search-form: มือถือจะเรียงบน→ล่าง อัตโนมัติจาก CSS -->
          <div class="toolbar">
            <form method="get" action="expenses.php" class="search-form">
              <input class="input sm" type="text" name="q"
                     placeholder="รหัสรถ/ยี่ห้อ/รุ่น/เลขทะเบียน/ผู้ขาย/คำอธิบาย"
                     value="<?= htmlspecialchars($q) ?>">

              <select class="select sm" name="category">
                <option value="">ทุกหมวด</option>
                <?php foreach ($cats as $c): ?>
                  <option value="<?= htmlspecialchars($c) ?>" <?= $cat===$c?'selected':'' ?>>
                    <?= htmlspecialchars($catMap[$c]) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <select class="select sm" name="status">
                <option value="0">ทุกสถานะ</option>
                <?php foreach ([1,2,3,4,9] as $opt): ?>
                  <option value="<?= $opt ?>" <?= $st===$opt?'selected':'' ?>>
                    <?= exp_status_label($opt) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <select class="select sm" name="cap">
                <option value="">ทุน/ไม่ทุน</option>
                <option value="1" <?= $cap==='1'?'selected':'' ?>>ตัดเป็นต้นทุน</option>
                <option value="0" <?= $cap==='0'?'selected':'' ?>>ไม่ตัดเป็นต้นทุน</option>
              </select>

              <input class="input sm" type="date" name="d1" value="<?= htmlspecialchars($d1) ?>">
              <input class="input sm" type="date" name="d2" value="<?= htmlspecialchars($d2) ?>">

              <button class="btn btn-outline sm" type="submit">ค้นหา</button>
            </form>

            <a class="btn btn-brand sm add-btn" href="expense_add.php">เพิ่มค่าใช้จ่าย</a>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>รถ</th>
                <th>เลขตัวถัง</th>
                <th>ชั่วโมงทำงาน</th>
                <th class="tr">รวมค่าใช้จ่าย</th>
                <th>ข้อมูลค่าใช้จ่าย</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td>
                    <div><strong><?= htmlspecialchars($r['code']) ?></strong></div>
                    <div class="muted"><?= htmlspecialchars($r['brand_name'].' '.$r['model_name']) ?></div>
                    <?php if (!empty($r['plate_no'])): ?>
                      <div class="muted">ป้ายทะเบียน: <?= htmlspecialchars($r['plate_no']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><div class="muted"><?= htmlspecialchars($r['serial_no']) ?></div></td>
                  <td><div class="muted"><?= htmlspecialchars($r['hour_meter']) ?></div></td>
                  <td><strong><?= thb($sumMap[(int)$r['machine_id']] ?? 0) ?></strong></td>
                  <td><a href="expense_view.php?id=<?= (int)$r['machine_id'] ?>">ดูค่าใช้จ่าย</a></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows): ?>
                <tr><td colspan="5" class="muted">ไม่พบข้อมูล</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <br>

      <!-- ค่าใช้จ่ายที่ไม่ผูกกับคัน -->
      <section class="card">
        <div class="card-head">
          <h3 class="h5">ค่าใช้จ่ายทั่วไป (ไม่ระบุคัน)</h3>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>ประเภทค่าใช้จ่าย</th>
                <th>ค่าใช้จ่าย</th>
                <th>หมายเหตุ</th>
                <th>จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $sql2 = "SELECT * FROM machine_expenses WHERE machine_id IS NULL
                       ORDER BY occurred_at DESC, expense_id DESC";
              $rows2 = $pdo->query($sql2)->fetchAll(PDO::FETCH_ASSOC);
              foreach ($rows2 as $s):
                $category = (int)$s['category'];
              ?>
                <tr>
                  <td>
                    <div class="muted">
                      <?= [
                        1=>'ซ่อม',2=>'บำรุงรักษา',3=>'อะไหล่',4=>'ขนส่ง',5=>'จดทะเบียน',
                        6=>'ตรวจสภาพ',7=>'ค่านายหน้า',8=>'ค่าใช้จ่ายการขาย'
                      ][$category] ?? 'อื่น ๆ' ?>
                    </div>
                  </td>
                  <td><div class="muted"><?= thb($s['total_cost']) ?></div></td>
                  <td><div class="muted"><?= htmlspecialchars($s['description']) ?></div></td>
                  <td>
                    <form action="expense_delete.php" method="post" class="js-del"
                          data-info="<?= htmlspecialchars($s['description'] ?? 'รายการนี้') ?>"
                          style="display:inline;">
                      <input type="hidden" name="csrf" value="<?= $csrf ?>">
                      <input type="hidden" name="id" value="<?= (int)$s['expense_id'] ?>">
                      <input type="hidden" name="ajax" value="1">
                      <button type="submit" class="btn btn-danger sm">ลบ</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows2): ?>
                <tr><td colspan="4" class="muted">ไม่พบข้อมูล</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <!-- สคริปต์ควบคุม sidebar/hamburger (ต้องมีเพื่อให้ปุ่มเมนูทำงานทุกหน้า) -->
  <script src="/assets/script.js?v=4"></script>

  <script>
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.js-del').forEach(f => {
      f.addEventListener('submit', (e) => {
        e.preventDefault();
        const info = f.dataset.info || 'รายการนี้';
        Swal.fire({
          icon: 'warning',
          title: 'ยืนยันการลบ?',
          text: info,
          showCancelButton: true,
          confirmButtonText: 'ลบ',
          cancelButtonText: 'ยกเลิก',
          reverseButtons: true,
          confirmButtonColor: '#fec201'
        }).then(res => { if (res.isConfirmed) f.submit(); });
      });
    });
    <?php if ($ok): ?>Swal.fire({icon:'success',title:'สำเร็จ',text:'<?= htmlspecialchars($ok, ENT_QUOTES) ?>',confirmButtonColor:'#fec201'});<?php endif; ?>
    <?php if ($err): ?>Swal.fire({icon:'error',title:'ไม่สำเร็จ',text:'<?= htmlspecialchars($err, ENT_QUOTES) ?>',confirmButtonColor:'#fec201'});<?php endif; ?>
  });
  </script>
</body>
</html>
