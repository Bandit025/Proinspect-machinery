<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function map_type_from_type2($type2){ return in_array((int)$type2,[1,3],true) ? 1 : 2; } // 1=รายได้, 2=รายจ่าย

// ประเภทย่อยที่เปิดให้เลือกตอนนี้ (อื่น ๆ)
$TYPE2 = [
  3 => 'รายได้อื่น ๆ',
  4 => 'รายจ่ายอื่น ๆ',
];

// ถ้าจะใช้ dropdown รถ ให้ดึงไว้ (ตอนนี้ไม่ได้ใช้ก็ไม่เป็นไร)
try {
  $machines = $pdo->query("SELECT machine_id, code FROM machines ORDER BY machine_id DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $machines = []; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  $type2_cashflow = (int)($_POST['type2_cashflow'] ?? 0);
  $type_cashflow  = map_type_from_type2($type2_cashflow);

  // ช่องที่ไม่ได้ใช้ให้เป็น null
  $machine_id = null;
  $sale_id    = null;
  $acq_id     = null;

  // เลขเอกสาร: ใช้เป็น string จะยืดหยุ่นกว่า (กัน 001/อักษร)
  $doc_no_raw = trim($_POST['doc_no'] ?? '');
  $doc_no     = ($doc_no_raw !== '' ? $doc_no_raw : null);

  $doc_date   = trim($_POST['doc_date'] ?? '');
  $amount_raw = trim($_POST['amount'] ?? '');
  $amount     = ($amount_raw !== '' && is_numeric($amount_raw)) ? (float)$amount_raw : null;
  $remark     = trim($_POST['remark'] ?? '');

  // ตรวจความถูกต้อง
  if (!in_array($type2_cashflow,[1,2,3,4],true)) $errors[] = 'เลือกประเภทย่อยไม่ถูกต้อง';
  if ($doc_date==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$doc_date)) $errors[]='กรอกวันที่ (YYYY-MM-DD)';
  if ($amount === null || $amount <= 0) $errors[]='จำนวนเงินต้องมากกว่า 0';

  if (!$errors) {
    $sql = "INSERT INTO cashflow
              (type_cashflow, type2_cashflow, machine_id, sale_id, acquisition_id, doc_no, doc_date, amount, remark)
            VALUES (?,?,?,?,?,?,?,?,?)";
    $stm = $pdo->prepare($sql);
    $ok = $stm->execute([
      $type_cashflow, $type2_cashflow,
      $machine_id, $sale_id, $acq_id,
      $doc_no, $doc_date, $amount, ($remark !== '' ? $remark : null)
    ]);
    if ($ok) {
      header('Location: cashflow.php?ok=' . urlencode('เพิ่มรายการเรียบร้อย'));
      exit;
    } else {
      $errors[] = 'บันทึกไม่สำเร็จ';
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>เพิ่มรายการกระแสเงินสด</title>
  <link rel="stylesheet" href="assets/style.css?v=31">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .money{ text-align:right }
    .row-grid{
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:10px
    }
    @media(max-width:900px){
      .row-grid{ grid-template-columns:1fr }
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
        <h2 class="page-title">เพิ่มรายการกระแสเงินสด</h2>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul style="margin:0 0 0 18px;">
            <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <section class="card">
        <form method="post" id="cfAddForm" novalidate>
          <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <div class="row-grid">
            <div>
              <label>วันที่เอกสาร</label>
              <input class="input" type="date" name="doc_date" required value="<?= h($_POST['doc_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div>
              <label>ประเภทย่อย</label>
              <select class="select" name="type2_cashflow" id="type2_cashflow" required>
                <option value="">— เลือก —</option>
                <?php foreach ($TYPE2 as $k=>$v): ?>
                  <option value="<?= $k ?>" <?= (string)($_POST['type2_cashflow'] ?? '')===(string)$k ? 'selected':''; ?>>
                    <?= h($v) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label>เลขเอกสาร (ถ้ามี)</label>
              <input class="input" type="text" name="doc_no" value="<?= h($_POST['doc_no'] ?? '') ?>">
            </div>
          </div>

          <div class="row-grid" style="margin-top:10px;">
            <div>
              <label>จำนวนเงิน</label>
              <input class="input money" type="number" name="amount" step="0.01" min="0.01" required value="<?= h($_POST['amount'] ?? '') ?>">
            </div>
            <div style="grid-column: span 2;">
              <label>หมายเหตุ</label>
              <input class="input" type="text" name="remark" value="<?= h($_POST['remark'] ?? '') ?>">
            </div>
          </div>

          <div style="margin-top:12px;display:flex;gap:8px;">
            <button class="btn btn-brand" type="submit">บันทึกรายการ</button>
            <a class="btn btn-outline" href="cashflow.php">ยกเลิก</a>
          </div>
        </form>
      </section>
    </main>
  </div>

  <!-- สำคัญ: ให้ hamburger/side bar ทำงานทุกหน้า -->
  <script src="assets/script.js?v=6"></script>
  <script>
    // เผื่ออนาคตถ้าเปิดใช้ช่อง machine_id อีกครั้ง — ป้องกัน error เมื่อช่องถูกคอมเมนต์ไว้
    (function(){
      const selType2 = document.getElementById('type2_cashflow');
      const selMachine = document.getElementById('machine_id'); // อาจไม่มี
      function applyRules(){
        if(!selMachine) return; // ไม่มี field ก็ไม่ต้องบังคับ
        const t2 = parseInt(selType2.value || '0', 10);
        const needMachine = (t2===1 || t2===2);
        selMachine.required = needMachine;
        if(!needMachine){ selMachine.value=''; }
      }
      if (selType2) {
        selType2.addEventListener('change', applyRules);
        applyRules();
      }
    })();
  </script>
</body>
</html>
