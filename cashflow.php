<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
  exit;
}
$user = $_SESSION['user'];

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function thb($n){ return '฿' . number_format((float)$n, 2); }
$roleName = ((int)($user['role'] ?? 1) === 2) ? 'ผู้ดูแลระบบ' : 'ผู้ใช้งาน';

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ---------- Filter: เลือกเดือนเดียว (GET m = YYYY-MM) ---------- */
$validateYm = function ($s) {
  if ($s === '') return '';
  $dt = DateTime::createFromFormat('Y-m', $s);
  return ($dt && $dt->format('Y-m') === $s) ? $s : '';
};
$m = $validateYm(trim($_GET['m'] ?? ''));
if ($m === '') $m = date('Y-m');          // ค่าเริ่มต้น = เดือนปัจจุบัน

$d1  = $m . '-01';                        // วันแรกของเดือนที่เลือก
$d2  = date('Y-m-t', strtotime($d1));     // วันสุดท้ายของเดือนที่เลือก
$d2p = date('Y-m-d', strtotime($d2 . ' +1 day')); // ขอบบนแบบ exclusive

/* Label เดือนที่เลือก (ไทยสั้น) */
$thaiMonths = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
$monthLabel = $thaiMonths[(int)date('n', strtotime($d1)) - 1] . ' ' . date('Y', strtotime($d1));

/* ---------- AJAX: ลบรายการ cashflow ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
      throw new RuntimeException('CSRF ไม่ถูกต้อง');
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
      $id = (int)($_POST['cashflow_id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('bad id');
      $del = $pdo->prepare('DELETE FROM cashflow WHERE cashflow_id = ? LIMIT 1');
      $del->execute([$id]);
      echo json_encode(['ok' => $del->rowCount() > 0]);
    } else {
      echo json_encode(['ok' => false, 'error' => 'bad action']);
    }
  } catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

/* ---------- KPI: เดือนที่เลือก (อิง created_at) ---------- */
$stmIn = $pdo->prepare("
  SELECT COALESCE(SUM(amount),0)
  FROM cashflow
  WHERE type_cashflow = 1
    AND created_at >= :d1 AND created_at < :d2
");
$stmEx = $pdo->prepare("
  SELECT COALESCE(SUM(amount),0)
  FROM cashflow
  WHERE type_cashflow = 2
    AND created_at >= :d1 AND created_at < :d2
");
$stmIn->execute([':d1' => $d1, ':d2' => $d2p]);
$stmEx->execute([':d1' => $d1, ':d2' => $d2p]);

$income_month  = (float)$stmIn->fetchColumn();
$expense_month = (float)$stmEx->fetchColumn();
$profit_month  = $income_month - $expense_month;

/* กำไรจากการขาย (PL) ของเดือนที่เลือก - อ้างอิงวันที่ขาย sold_at */
$stmtPl = $pdo->prepare("
  SELECT COALESCE(SUM(pl_amount), 0)
  FROM sales
  WHERE sold_at >= :d1 AND sold_at < :d2
");
/* ถ้า pl_amount เป็น TEXT/VARCHAR ให้ใช้ CAST:
   SELECT COALESCE(SUM(CAST(pl_amount AS DECIMAL(15,2))), 0) ...
*/
$stmtPl->execute([':d1' => $d1, ':d2' => $d2p]);
$profit_sales_month = (float) $stmtPl->fetchColumn();


/* ---------- โหลดรายการ cashflow (อิง created_at ตามช่วงที่เลือก) ---------- */
$where  = [];
$params = [];
$where[] = 'a.created_at >= :f_d1';  $params[':f_d1']  = $d1;
$where[] = 'a.created_at < :f_d2p';  $params[':f_d2p'] = $d2p;

$whereSql = 'WHERE ' . implode(' AND ', $where);

$sql = "SELECT a.*,
               b.type_name  AS type2_name,
               c.code       AS machine_code,
               d.model_name,
               e.brand_name
        FROM cashflow a
        LEFT JOIN type2_cashflow b ON a.type2_cashflow = b.type_id
        LEFT JOIN machines        c ON a.machine_id     = c.machine_id
        LEFT JOIN models          d ON c.model_id       = d.model_id
        LEFT JOIN brands          e ON d.brand_id       = e.brand_id
        {$whereSql}
        ORDER BY a.created_at DESC, a.cashflow_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cashflows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>กระแสเงินสด — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=46">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <style>
    /* ====== เพิ่มเติมเฉพาะหน้านี้ ====== */
    .kpi-grid{ display:grid; gap:14px; grid-template-columns:repeat(3,minmax(0,1fr)); }
    @media (max-width:1024px){ .kpi-grid{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media (max-width:560px){ .kpi-grid{ grid-template-columns:1fr; } }
    .num-xl{ font-size:clamp(1.2rem,3.6vw,1.8rem); font-weight:800; line-height:1.1; margin:4px 0; }
    .money{ text-align:right; white-space:nowrap; }
    .badge.income{ background:rgba(46,204,113,.14); border:1px solid rgba(46,204,113,.35); color:#0f7a3a; padding:3px 8px; border-radius:999px; font-size:.86rem; }
    .badge.expense{ background:rgba(217,48,37,.12); border:1px solid rgba(217,48,37,.35); color:#9c1f16; padding:3px 8px; border-radius:999px; font-size:.86rem; }
    .toolbar{ display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .toolbar .right{ display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
    @media (max-width:720px){ .toolbar{ flex-direction:column; align-items:stretch; } .toolbar .right{ justify-content:flex-end; } .table-wrap{ overflow:auto; } }
    .table thead th,.table td{ vertical-align:top; }
    .td-actions{ white-space:nowrap; text-align:right; }
    .inline-filter{ display:flex; align-items:center; gap:10px; flex-wrap:nowrap; }
    .inline-filter label{ white-space:nowrap; margin:0 4px 0 12px; }
    .inline-filter .input.sm{ display:inline-block; width:160px; max-width:100%; }
    .inline-filter .btn,.inline-filter .btn-light{ white-space:nowrap; }
    @media (max-width:720px){ .inline-filter{ flex-wrap:wrap; } .inline-filter .input.sm{ width:100%; } }
  </style>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>

  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">กระแสเงินสด (Cashflow)</h2>
        <div class="page-sub">บันทึก/ตรวจสอบรายรับ–รายจ่าย</div>
      </div>

      <!-- สรุปภาพรวม -->
      <section class="card" style="margin-top:12px;">
        <div class="card-head">
          <h3 class="h5">สรุปเดือนที่เลือก: <?= h($monthLabel) ?></h3>
        </div>
        <div class="kpi-grid">
          <div class="card" style="margin:0;">
            <div class="muted">รายรับ</div>
            <div class="num-xl"><?= thb($income_month) ?></div>
            <div class="muted" style="font-size:.9rem;"><?= h($monthLabel) ?></div>
          </div>
          <div class="card" style="margin:0;">
            <div class="muted">รายจ่าย</div>
            <div class="num-xl" style="color:#A30000;"><?= thb($expense_month) ?></div>
            <div class="muted" style="font-size:.9rem;"><?= h($monthLabel) ?></div>
          </div>
          <div class="card" style="margin:0;">
  <div class="muted">กำไรจากการขาย (PL)</div>
  <div class="num-xl" style="color:<?= $profit_sales_month >= 0 ? '#177500' : '#A30000' ?>;">
    <?= thb($profit_sales_month) ?>
  </div>
  <div class="muted" style="font-size:.9rem;">
    <?= h($monthLabel) ?> • สุทธิจากกระแสเงินสด: 
    <span style="color:<?= $profit_month >= 0 ? '#177500' : '#A30000' ?>;">
      <?= thb($profit_month) ?>
    </span>
  </div>
</div>
        </div>
      </section>

      <!-- รายการทั้งหมด -->
      <section class="card" style="margin-top:20px;">
        <div class="card-head toolbar">
          <h3 class="h5" style="margin:0;">รายการกระแสเงินสดทั้งหมด</h3>
          <div class="right">
            <form method="get" action="cashflow.php" class="inline-filter">
              <label class="muted" style="font-size:.9rem;">เลือกเดือน</label>
              <input type="month" id="m" name="m" value="<?= h($m) ?>" class="input sm">
              <button type="submit" class="btn sm">ค้นหา</button>
              <?php $exportQS = http_build_query(['d1' => $d1, 'd2' => $d2]); ?>
              <a class="btn sm btn-success" target="_blank" href="export_cashflow.php?<?= $exportQS ?>">Export XLSX</a>
              <a class="btn sm btn-light" href="cashflow.php">เดือนปัจจุบัน</a>
              <a class="btn btn-brand sm" href="cashflow_add.php">เพิ่มรายการ</a>
            </form>
          </div>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:110px;">วันที่</th>
                <th style="width:100px;">ประเภท</th>
                <th style="width:200px;">ประเภทย่อย</th>
                <th>เลขเอกสาร</th>
                <th>รถ</th>
                <th class="money" style="width:160px;">จำนวนเงิน</th>
                <th>หมายเหตุ</th>
                <th class="td-actions" style="width:160px;">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cashflows as $r):
                $docNoDisp   = ($r['doc_no'] ?? '') !== '' ? $r['doc_no'] : '-';
                $machineCode = ($r['machine_code'] ?? '') !== '' ? $r['machine_code'] : '-';
                $brandName   = ($r['brand_name']   ?? '') !== '' ? $r['brand_name']   : '';
                $modelName   = ($r['model_name']   ?? '') !== '' ? $r['model_name']   : '';
                $type2Name   = ($r['type2_name']   ?? '') !== '' ? $r['type2_name']   : '-';
                $t           = (int)($r['type_cashflow'] ?? 0);

                // วันที่แสดงผล: doc_date ถ้ามี ไม่งั้น fallback เป็น created_at
                $dateStr = '-';
                if (!empty($r['doc_date']))       $dateStr = date('d/m/Y', strtotime($r['doc_date']));
                elseif (!empty($r['created_at'])) $dateStr = date('d/m/Y', strtotime($r['created_at']));

                $machineDisp = $machineCode;
                $bm = trim($brandName . ' ' . $modelName);
                if ($bm !== '') $machineDisp .= ' ' . $bm;
              ?>
                <tr>
                  <td><?= h($dateStr) ?></td>
                  <td>
                    <?php if ($t === 1): ?>
                      <span class="badge income">รายได้</span>
                    <?php else: ?>
                      <span class="badge expense">รายจ่าย</span>
                    <?php endif; ?>
                  </td>
                  <td><?= h($type2Name) ?></td>
                  <td><?= h($docNoDisp) ?></td>
                  <td><?= h($machineDisp) ?></td>
                  <td class="money"><?= ($t === 1 ? '+' : '-') . thb($r['amount']) ?></td>
                  <td><?= h($r['remark'] ?? '') ?></td>
                  <td class="td-actions">
                    <?php if (empty($r['machine_id']) && empty($r['sale_id']) && empty($r['acquisition_id']) && empty($r['expense_id']) && empty($r['doc_no'])): ?>
                      <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                        <a class="btn btn-xs" href="cashflow_edit.php?id=<?= (int)$r['cashflow_id'] ?>">แก้ไข</a>
                        <form method="post" class="delForm" style="display:inline;">
                          <input type="hidden" name="csrf" value="<?= $csrf ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="cashflow_id" value="<?= (int)$r['cashflow_id'] ?>">
                          <button type="button" class="btn btn-xs btn-danger delBtn">ลบ</button>
                        </form>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$cashflows): ?>
                <tr>
                  <td colspan="8" class="muted">ไม่พบข้อมูล</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
  </div>

  <!-- สำคัญ: ให้ sidebar/hamburger ทำงาน -->
  <script src="assets/script.js?v=5"></script>

  <script>
    // ลบด้วย SweetAlert + AJAX
    document.querySelectorAll('.delBtn').forEach(btn => {
      btn.addEventListener('click', () => {
        const form = btn.closest('form');
        const fd = new FormData(form);
        fd.append('ajax', '1');

        Swal.fire({
          icon: 'warning',
          title: 'ยืนยันการลบ?',
          text: 'ลบแล้วไม่สามารถกู้คืนได้',
          showCancelButton: true,
          confirmButtonText: 'ลบ',
          cancelButtonText: 'ยกเลิก',
          reverseButtons: true,
          confirmButtonColor: '#e11d48'
        }).then(res => {
          if (!res.isConfirmed) return;

          fetch(form.getAttribute('action') || window.location.pathname, {
              method: 'POST',
              headers: { 'X-Requested-With': 'XMLHttpRequest' },
              body: new URLSearchParams([...fd.entries()])
            })
            .then(r => r.json())
            .then(j => {
              if (j && j.ok) {
                Swal.fire({ icon:'success', title:'ลบแล้ว', timer:900, showConfirmButton:false })
                  .then(() => location.href = 'cashflow.php?ok=' + encodeURIComponent('ลบรายการเรียบร้อย'));
              } else {
                Swal.fire({ icon:'error', title:'ลบไม่สำเร็จ', text:(j && j.error) ? j.error : 'เกิดข้อผิดพลาด', confirmButtonColor:'#e11d48' });
              }
            })
            .catch(() => Swal.fire({ icon:'error', title:'ลบไม่สำเร็จ', text:'เครือข่ายผิดพลาด', confirmButtonColor:'#e11d48' }));
        });
      });
    });
  </script>
</body>
</html>
