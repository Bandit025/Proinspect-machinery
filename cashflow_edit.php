<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function map_type_from_type2($type2){ return in_array((int)$type2,[1,3],true) ? 1 : 2; }

$TYPE2 = [
  1 => 'ขายรถออก (รายได้)',
  2 => 'ซื้อรถเข้า (รายจ่าย)',
  3 => 'รายได้อื่น ๆ',
  4 => 'รายจ่ายอื่น ๆ',
];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: cashflow_list.php?error=' . urlencode('ไม่พบรายการที่ต้องการแก้ไข')); exit; }

/* โหลดแถวเดิม */
$s = $pdo->prepare("SELECT * FROM cashflow WHERE cashflow_id=? LIMIT 1");
$s->execute([$id]);
$row = $s->fetch(PDO::FETCH_ASSOC);
if (!$row) { header('Location: cashflow_list.php?error=' . urlencode('ไม่พบรายการที่ต้องการแก้ไข')); exit; }

/* โหลดรายการรถ (ไว้เลือกอ้างอิง) */
try {
  $machines = $pdo->query("SELECT machine_id, code FROM machines ORDER BY machine_id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $machines = []; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[] = 'CSRF token ไม่ถูกต้อง';

  $type2_cashflow = (int)($_POST['type2_cashflow'] ?? 0);
  $type_cashflow  = map_type_from_type2($type2_cashflow);

  $machine_id_raw = trim($_POST['machine_id'] ?? '');
  $machine_id     = ($machine_id_raw === '' ? null : (int)$machine_id_raw);

  $sale_id_raw        = trim($_POST['sale_id'] ?? '');
  $acquisition_id_raw = trim($_POST['acquisition_id'] ?? '');
  $sale_id            = ($sale_id_raw !== '' ? (int)$sale_id_raw : null);
  $acquisition_id     = ($acquisition_id_raw !== '' ? (int)$acquisition_id_raw : null);

  // ✅ doc_no เป็น STRING (ตารางเป็น VARCHAR(32))
  $doc_no_raw = trim($_POST['doc_no'] ?? '');
  $doc_no     = ($doc_no_raw !== '' ? mb_substr($doc_no_raw, 0, 32) : null);

  $doc_date   = trim($_POST['doc_date'] ?? '');
  $amount_raw = trim($_POST['amount'] ?? '');
  $amount     = ($amount_raw !== '' && is_numeric($amount_raw)) ? (float)$amount_raw : null;
  $remark     = trim($_POST['remark'] ?? '');

  // validate
  if (!in_array($type2_cashflow,[1,2,3,4],true)) $errors[] = 'เลือกประเภทย่อยไม่ถูกต้อง';
  if ($doc_date==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$doc_date)) $errors[]='กรอกวันที่ (YYYY-MM-DD)';
  if ($amount === null || $amount <= 0) $errors[]='จำนวนเงินต้องมากกว่า 0';
  if ($doc_no !== null && !preg_match('/^[A-Za-z0-9_\-\/]{1,32}$/', $doc_no)) $errors[] = 'เลขเอกสารใช้ได้เฉพาะ a-z0-9, -, _, / (ยาวสุด 32)';

  if (in_array($type2_cashflow,[1,2],true)) {
    if ($machine_id === null || $machine_id <= 0) $errors[]='กรุณาเลือกรถสำหรับประเภทย่อยนี้';
  } else {
    $machine_id = null;
  }

  // (ออปชัน) ตรวจว่ารถที่เลือกมีอยู่จริง
  if (!$errors && $machine_id) {
    $chk = $pdo->prepare("SELECT 1 FROM machines WHERE machine_id=?");
    $chk->execute([$machine_id]);
    if (!$chk->fetchColumn()) $errors[] = 'ไม่พบรถที่เลือก';
  }

  if (!$errors) {
    $sql = "UPDATE cashflow
            SET type_cashflow=?, type2_cashflow=?, machine_id=?,
                sale_id=?, acquisition_id=?, doc_no=?, doc_date=?,
                amount=?, remark=?, updated_at=NOW()
            WHERE cashflow_id=? LIMIT 1";
    $stm = $pdo->prepare($sql);
    $ok = $stm->execute([
      $type_cashflow, $type2_cashflow, $machine_id,
      $sale_id, $acquisition_id, $doc_no, $doc_date,
      $amount, ($remark !== '' ? $remark : null),
      $id
    ]);

    if ($ok) {
      header('Location: cashflow.php?ok=' . urlencode('อัปเดตรายการเรียบร้อย'));
      exit;
    } else {
      $errors[] = 'อัปเดตไม่สำเร็จ';
    }
  }

  // refresh ฟอร์ม
  $row = array_merge($row, [
    'type2_cashflow' => $type2_cashflow,
    'type_cashflow'  => $type_cashflow,
    'machine_id'     => $machine_id,
    'sale_id'        => $sale_id,
    'acquisition_id' => $acquisition_id,
    'doc_no'         => $doc_no,
    'doc_date'       => $doc_date,
    'amount'         => $amount,
    'remark'         => $remark,
  ]);
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>แก้ไขรายการกระแสเงินสด</title>
  <link rel="stylesheet" href="assets/style.css?v=31">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>.money{text-align:right}.row-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}@media(max-width:900px){.row-grid{grid-template-columns:1fr}}</style>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
    <main class="content">
      <div class="page-head">
        <h2 class="page-title">แก้ไขรายการกระแสเงินสด</h2>
        <div class="page-sub"><a class="link" href="cashflow.php">กลับไปหน้ารายการ</a></div>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-danger"><ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <section class="card">
        <form method="post" id="cfEditForm" novalidate>
          <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <div class="row-grid">
            <div>
              <label>วันที่เอกสาร</label>
              <input class="input" type="date" name="doc_date" required value="<?= h($row['doc_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div>
              <!-- ✅ เปลี่ยนเป็น text เพื่อรองรับ TB2025... -->
              <label>เลขเอกสาร</label>
              <input class="input" type="text" name="doc_no" maxlength="32" placeholder="เช่น TB20250912001" value="<?= h($row['doc_no'] ?? '') ?>">
            </div>
            <div>
              <label>ประเภทย่อย</label>
              <select class="select" name="type2_cashflow" id="type2_cashflow" required>
                <option value="">— เลือก —</option>
                <?php foreach ($TYPE2 as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= (string)($row['type2_cashflow'] ?? '')===(string)$k ? 'selected':''; ?>><?= h($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="row-grid" style="margin-top:10px;">
            <div>
              <label>รถ (เฉพาะ “ขายรถออก/ซื้อรถเข้า”)</label>
              <select class="select" name="machine_id" id="machine_id">
                <option value="">— ไม่ระบุ —</option>
                <?php foreach ($machines as $m): ?>
                  <option value="<?= (int)$m['machine_id'] ?>" <?= (string)($row['machine_id'] ?? '')===(string)$m['machine_id'] ? 'selected':''; ?>>
                    <?= h($m['machine_id'].' — '.($m['code'] ?? '')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="muted" style="font-size:.85rem;">* ต้องเลือกเมื่อประเภทย่อย = ขายรถออก หรือ ซื้อรถเข้า</div>
            </div>
            <div>
              <label>ลิงก์ใบขาย (sale_id)</label>
              <input class="input" type="number" name="sale_id" min="0" step="1" value="<?= h($row['sale_id'] ?? '') ?>">
            </div>
            <div>
              <label>ลิงก์เอกสารซื้อเข้า (acquisition_id)</label>
              <input class="input" type="number" name="acquisition_id" min="0" step="1" value="<?= h($row['acquisition_id'] ?? '') ?>">
            </div>
          </div>

          <div class="row-grid" style="margin-top:10px;">
            <div>
              <label>จำนวนเงิน</label>
              <input class="input money" type="number" name="amount" step="0.01" min="0.01" required value="<?= h($row['amount'] ?? '') ?>">
            </div>
            <div style="grid-column: span 2;">
              <label>หมายเหตุ</label>
              <input class="input" type="text" name="remark" value="<?= h($row['remark'] ?? '') ?>">
            </div>
          </div>

          <div style="margin-top:12px;display:flex;gap:8px;">
            <button class="btn btn-brand" type="submit">บันทึกการแก้ไข</button>
            <a class="btn btn-outline" href="cashflow_list.php">ยกเลิก</a>
          </div>
        </form>
      </section>
    </main>
  </div>

  <script>
    (function(){
      const selType2 = document.getElementById('type2_cashflow');
      const selMachine = document.getElementById('machine_id');
      function applyRules(){
        const t2 = parseInt(selType2.value || '0', 10);
        const needMachine = (t2===1 || t2===2);
        selMachine.required = needMachine;
        if(!needMachine){ selMachine.value=''; }
      }
      selType2.addEventListener('change', applyRules);
      applyRules();
    })();
  </script>
</body>
</html>
