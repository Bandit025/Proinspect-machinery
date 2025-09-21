<?php
// expense_view.php — แสดงค่าใช้จ่ายทั้งหมดของ "รถหนึ่งคัน"
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
  exit;
}
$isAdmin = ((int)($_SESSION['user']['role'] ?? 1) === 2);

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function thb($n)
{
  return $n !== null ? '฿' . number_format((float)$n, 2) : '-';
}
function exp_status_label($x)
{
  return [1 => 'รอดำเนินการ', 2 => 'ออก P/O', 3 => 'ชำระแล้ว', 4 => 'เสร็จสิ้น', 9 => 'ยกเลิก'][$x] ?? 'รอดำเนินการ';
}
function cap_label($v)
{
  return ((string)$v === '1') ? 'เข้าทุน' : 'ค่าใช้จ่ายงวด';
}
function category_label($v)
{
  static $num = [1 => 'ซ่อม', 2 => 'บำรุงรักษา', 3 => 'อะไหล่', 4 => 'ขนส่ง', 5 => 'จดทะเบียน', 6 => 'ตรวจสภาพ', 7 => 'ค่านายหน้า', 8 => 'ค่าใช้จ่ายการขาย', 9 => 'ค่าแรง', 10 => 'อื่นๆ'];
  static $str = ['repair' => 'ซ่อม', 'maintenance' => 'บำรุงรักษา', 'parts' => 'อะไหล่', 'transport' => 'ขนส่ง', 'registration' => 'จดทะเบียน', 'inspection' => 'ตรวจสภาพ', 'brokerage' => 'ค่านายหน้า', 'selling' => 'ค่าใช้จ่ายการขาย', 'other' => 'อื่น ๆ'];
  if ($v === '' || $v === null) return '-';
  if (is_numeric($v) && isset($num[(int)$v])) return $num[(int)$v];
  return $str[$v] ?? (string)$v;
}

$machine_id = (int)($_GET['id'] ?? 0); // ใช้ id = machine_id
if ($machine_id <= 0) {
  header('Location: expenses.php?error=' . urlencode('ไม่พบรถที่ต้องการ'));
  exit;
}

// ข้อมูลรถ
$sqlM = "SELECT m.machine_id, m.code, b.brand_name, mo.model_name
         FROM machines m
         JOIN models mo ON mo.model_id = m.model_id
         JOIN brands b ON b.brand_id = mo.brand_id
         
         WHERE m.machine_id = ?
         LIMIT 1";
$stmM = $pdo->prepare($sqlM);
$stmM->execute([$machine_id]);
$mach = $stmM->fetch(PDO::FETCH_ASSOC);
if (!$mach) {
  header('Location: expenses.php?error=' . urlencode('ไม่พบรถที่ต้องการ'));
  exit;
}

// รายการค่าใช้จ่ายของรถคันนี้
$sqlL = "SELECT *
         FROM machine_expenses 
         WHERE machine_id = ?";
$stmL = $pdo->prepare($sqlL);
$stmL->execute([$machine_id]);
$rows = $stmL->fetchAll(PDO::FETCH_ASSOC);

// สรุปยอดรวมทั้งหมด
$sum_all = 0.0;
foreach ($rows as $r) {
  $row_total = isset($r['total_cost']) && $r['total_cost'] !== null
    ? (float)$r['total_cost']
    : ((float)$r['qty'] * (float)$r['unit_cost']) + (float)($r['commission_amt'] ?? 0);
  $sum_all += $row_total;
}
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>ค่าใช้จ่ายของรถ <?= htmlspecialchars($mach['code']) ?> — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=19">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .card-wide {
      max-width: min(1100px, 96vw);
      margin: 0 auto;
    }

    .kv {
      display: grid;
      grid-template-columns: 160px 1fr;
      gap: 8px 16px;
      margin-bottom: 12px;
    }

    .kv .k {
      color: var(--muted, #666);
    }

    .table .muted {
      color: var(--muted, #666);
      font-size: 12px;
    }

    .sum-row {
      font-weight: 700;
    }
  </style>
</head>

<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">ค่าใช้จ่ายของรถ <strong><?= htmlspecialchars($mach['code']) ?></strong></h2>
        <div class="page-sub"><?= htmlspecialchars($mach['brand_name'] . ' ' . $mach['model_name']) ?></div>
      </div>

      <section class="card card-wide">
        <div class="kv">
          <div class="k">รหัสรถ</div>
          <div><strong><?= htmlspecialchars($mach['code']) ?></strong></div>
          <div class="k">ยี่ห้อ/รุ่น</div>
          <div><?= htmlspecialchars($mach['brand_name'] . ' ' . $mach['model_name']) ?></div>
          <div class="k">จำนวนรายการ</div>
          <div><?= count($rows) ?></div>
          <div class="k">ยอดรวมทั้งหมด</div>
          <div><?= thb($sum_all) ?></div>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:120px;">วันที่เกิด</th>
                <th style="width:120px;">ประเภท</th>
                <th>คำอธิบาย</th>
                <th class="tr" style="width:90px;">Qty</th>
                <th class="tr" style="width:120px;">ราคา/หน่วย</th>
                <th class="tr" style="width:120px;">รวม</th>
                <th class="tr" style="width:160px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($rows): ?>
                <?php foreach ($rows as $r):
                  $id = (int)$r['expense_id'];
                  $row_total = isset($r['total_cost']) && $r['total_cost'] !== null
                    ? (float)$r['total_cost']
                    : ((float)$r['qty'] * (float)$r['unit_cost']) + (float)($r['commission_amt'] ?? 0);
                ?>
                  <tr>
                    <td><?= htmlspecialchars($r['occurred_at'] ?? '-') ?></td>
                    <td><?= htmlspecialchars(category_label($r['category'])) ?></td>
                    <td>
                      <?php if (!empty($r['description'])): ?>
                        <?= htmlspecialchars($r['description']) ?>
                      <?php else: ?>
                        <span class="muted">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="tr"><?= number_format((float)$r['qty'], 2) ?></td>
                    <td class="tr"><?= thb($r['unit_cost']) ?></td>
                    <td class="tr"><?= thb($row_total) ?></td>

                    <td class="tr">
                      <a class="link" href="expense_edit.php?id=<?= $id ?>">แก้ไข</a>

                      <form action="expense_delete.php" method="post" class="js-del" data-info="<?= htmlspecialchars('#' . $id . ' / ' . $mach['code'], ENT_QUOTES) ?>" style="display:inline;">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                        <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <tr class="sum-row">
                  <td colspan="6" class="tr">รวมทั้งหมด</td>
                  <td class="tr"><?= thb($sum_all) ?></td>
                  <td colspan="2"></td>
                </tr>
              <?php else: ?>
                <tr>
                  <td colspan="9" class="muted">ไม่มีรายการค่าใช้จ่ายสำหรับรถคันนี้</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
          <a class="btn btn-outline" href="expenses.php">ย้อนกลับ</a>
        </div>
      </section>
    </main>
  </div>
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
          }).then(res => {
            if (res.isConfirmed) f.submit();
          });
        });
      });
    });
  </script>
</body>

</html>