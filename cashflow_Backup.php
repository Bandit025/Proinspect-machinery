<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function money($n)
{
  return number_format((float)$n, 2);
}

$TYPE2 = [
  1 => 'ซื้อรถเข้า (รายจ่าย)',
  2 => 'ขายรถออก (รายได้)',
  3 => 'รายได้อื่น ๆ',
  4 => 'รายจ่ายอื่น ๆ',
];
$TYPE = [1 => 'รายได้', 2 => 'รายจ่าย'];

$ok = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';
$errors = [];

/* ลบ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }
  $id = (int)($_POST['cashflow_id'] ?? 0);
  if ($id <= 0) $errors[] = 'ไม่พบรายการที่จะลบ';

  // ตรวจว่าเป็น AJAX หรือไม่
  $isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
    (($_POST['ajax'] ?? '') === '1')
  );

  if (!$errors) {
    $del = $pdo->prepare("DELETE FROM cashflow WHERE cashflow_id=? LIMIT 1");
    $del->execute([$id]);

    if ($isAjax) {
      header('Content-Type: application/json');
      echo json_encode(['ok' => true]);
      exit;
    } else {
      header('Location: cashflow.php?ok=' . urlencode('ลบรายการเรียบร้อย')); // กลับหน้า cashflow.php
      exit;
    }
  } else {
    if ($isAjax) {
      header('Content-Type: application/json');
      echo json_encode(['ok' => false, 'error' => implode("\n", $errors)]);
      exit;
    } else {
      header('Location: cashflow.php?error=' . urlencode(implode("\n", $errors)));
      exit;
    }
  }
}

/* โหลดรายการ */


$sql_income = "SELECT SUM(amount) AS total_income FROM cashflow WHERE type_cashflow = 1 ";
$sql_expense = "SELECT SUM(amount) AS total_expense FROM cashflow WHERE type_cashflow = 2 ";
$income = $pdo->query($sql_income)->fetch(PDO::FETCH_ASSOC);
$expense = $pdo->query($sql_expense)->fetch(PDO::FETCH_ASSOC);
$profit = $income['total_income'] - $expense['total_expense'];
$income_total = $income['total_income'] ?? 0;
$expense_total = $expense['total_expense'] ?? 0;
$profit_total = $profit ?? 0;
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>กระแสเงินสด — รายการทั้งหมด</title>
  <link rel="stylesheet" href="assets/style.css?v=31">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .money {
      text-align: right
    }

    .badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 999px;
      font-size: .8rem;
      border: 1px solid #e5e7eb;
      background: #f9fafb
    }

    .badge.income {
      border-color: #bbf7d0;
      background: #ecfdf5;
      color: #065f46
    }

    .badge.expense {
      border-color: #fecaca;
      background: #fef2f2;
      color: #7f1d1d
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
        <h2 class="page-title">กระแสเงินสด (Cashflow)</h2>
        <div class="page-sub">รายการทั้งหมด · เพิ่ม/แก้ไข/ลบ</div>
      </div>
      <section class="card" style="margin-top:12px;">


        <div style="display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:14px;">
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;">
            <div class="card" style="margin:0;">
              <div class="muted">รายรับ</div>
              <div style="font-size:1.8rem;font-weight:800;margin:4px 0;">
                <?= h(number_format((float)$income_total, 2)) ?>
              </div>
              <div class="muted" style="font-size:.9rem;">อัปเดตล่าสุดวันนี้</div>
            </div>

            <div class="card" style="margin:0;">
              <div class="muted">รายจ่าย</div>
              <div style="font-size:1.8rem;font-weight:800;margin:4px 0;">
                <?= h(number_format((float)$expense_total, 2)) ?>
              </div>
              <div class="muted" style="font-size:.9rem;"></div>
            </div>

            <div class="card" style="margin:0;">
              <div class="muted">กำไรเดือนนี้</div>
              <div style="font-size:1.8rem;font-weight:800;margin:4px 0;">
                <?= h(number_format((float)$profit_total, 2)) ?>
              </div>
              <div class="muted" style="font-size:.9rem;">ยอดสุทธิ ~ ฿5.2M</div>
            </div>
          </div>

        </div>
      </section>
      <?php if ($ok): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>
      <?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <section class="card">
        <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;">
          <h3 class="h5" style="margin:0;">รายการกระแสเงินสดทั้งหมด</h3>
          <a class="btn btn-brand sm" href="cashflow_add.php">เพิ่มรายการ</a>
        </div>

        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th style="width:110px;">วันที่</th>
                <th style="width:120px;">ประเภท</th>
                <th style="width:180px;">ประเภทย่อย</th>
                <th>เลขเอกสาร</th>
                <th>เลขทะเบียนรถ</th>
                <th style="width:140px;text-align:right;">จำนวนเงิน</th>
                <th>หมายเหตุ</th>
                <th style="width:160px;">จัดการ</th>
              </tr>
            </thead>

            <tbody>
              <?php
              // JOIN type2_cashflow เพื่อดึงชื่อประเภทย่อย
              $sql = "SELECT a.*, 
               b.type_name AS type2_name,
               c.code      AS machine_code,
               d.model_name,
               e.brand_name 
        FROM cashflow a
        LEFT JOIN type2_cashflow b ON a.type2_cashflow = b.type_id
        LEFT JOIN machines        c ON a.machine_id     = c.machine_id
        LEFT JOIN models          d ON c.model_id       = d.model_id
        LEFT JOIN brands          e ON d.brand_id       = e.brand_id
        ORDER BY a.created_at DESC, a.cashflow_id DESC";
              $stmt = $pdo->query($sql);
              $cashflows = $stmt->fetchAll(PDO::FETCH_ASSOC);

              foreach ($cashflows as $r):
                // เตรียมค่าที่จะแสดง (กัน NULL)
                $docNoDisp   = ($r['doc_no'] ?? '') !== '' ? $r['doc_no'] : '-';
                $machineCode = ($r['machine_code'] ?? '') !== '' ? $r['machine_code'] : '-';
                $brandName   = ($r['brand_name']   ?? '') !== '' ? $r['brand_name']   : '-';
                $modelName   = ($r['model_name']   ?? '') !== '' ? $r['model_name']   : '-';
                $type2Name   = ($r['type2_name']   ?? '') !== '' ? $r['type2_name']   : '-';
                $t           = (int)($r['type_cashflow'] ?? 0);

                // วันที่: ถ้ามี doc_date ใช้อันนั้นก่อน มิฉะนั้น fallback เป็น created_at
                $dateStr = '-';
                if (!empty($r['doc_date'])) {
                  $dateStr = date('d/m/Y', strtotime($r['doc_date']));
                } elseif (!empty($r['created_at'])) {
                  $dateStr = date('d/m/Y', strtotime($r['created_at']));
                }

                // แสดงรถ: CODE + ยี่ห้อ-รุ่น (กัน - - ให้ดูสั้นลง)
                $machineDisp = $machineCode;
                $bm = trim(($brandName !== '-' ? $brandName : '') . ' ' . ($modelName !== '-' ? $modelName : ''));
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
                  <td class="money"><?= ($t === 1 ? '+' : '-') . money($r['amount']) ?></td>
                  <td><?= h($r['remark'] ?? '') ?></td>
                  <td>
                    <?php if (empty($r['machine_id']) && empty($r['sale_id']) && empty($r['acquisition_id']) && empty($r['expense_id']) && empty($r['doc_no'])) { ?>
                      <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="btn btn-xs" href="cashflow_edit.php?id=<?= (int)$r['cashflow_id'] ?>">แก้ไข</a>
                        <form method="post" class="delForm" style="display:inline;">
                          <input type="hidden" name="csrf" value="<?= $csrf ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="cashflow_id" value="<?= (int)$r['cashflow_id'] ?>">
                          <button type="button" class="btn btn-xs btn-danger delBtn">ลบ</button>
                        </form>
                      </div>
                    <?php } else { ?>

                    <?php } ?>
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

  <script>
    document.querySelectorAll('.delBtn').forEach(btn => {
      btn.addEventListener('click', () => {
        const form = btn.closest('form');
        const fd = new FormData(form);
        fd.append('ajax', '1'); // บอกฝั่ง PHP ว่าเป็น AJAX

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
              headers: {
                'X-Requested-With': 'XMLHttpRequest'
              },
              body: new URLSearchParams([...fd.entries()]) // ส่งแบบ x-www-form-urlencoded ก็ได้
            })
            .then(r => r.json())
            .then(j => {
              if (j && j.ok) {
                Swal.fire({
                  icon: 'success',
                  title: 'ลบแล้ว',
                  timer: 900,
                  showConfirmButton: false
                }).then(() => {
                  // กลับไปหน้า cashflow.php พร้อมข้อความ ok
                  window.location = 'cashflow.php?ok=' + encodeURIComponent('ลบรายการเรียบร้อย');
                });
              } else {
                Swal.fire({
                  icon: 'error',
                  title: 'ลบไม่สำเร็จ',
                  text: (j && j.error) ? j.error : 'เกิดข้อผิดพลาด',
                  confirmButtonColor: '#e11d48'
                });
              }
            })
            .catch(() => {
              Swal.fire({
                icon: 'error',
                title: 'ลบไม่สำเร็จ',
                text: 'เครือข่ายผิดพลาด',
                confirmButtonColor: '#e11d48'
              });
            });
        });
      });
    });
  </script>

</body>

</html>