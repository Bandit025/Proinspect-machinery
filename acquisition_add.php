<?php
require __DIR__ . '/config.php';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ========= AJAX: ดึงราคา purchase_price ของรถ ========= */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'machine_price') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $mid = (int)($_GET['mid'] ?? 0);
    if ($mid <= 0) {
      echo json_encode(['ok' => false, 'error' => 'bad id']);
      exit;
    }
    $stm = $pdo->prepare("SELECT purchase_price FROM machines WHERE machine_id=? LIMIT 1");
    $stm->execute([$mid]);
    $price = $stm->fetchColumn();
    if ($price === false) {
      echo json_encode(['ok' => false, 'error' => 'not found']);
      exit;
    }
    echo json_encode(['ok' => true, 'price' => ($price !== null ? (float)$price : null)]);
  } catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'exception']);
  }
  exit;
}
/* ======================================================= */

$ok = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';
$errors = [];

/* โหลดรายการรถ (ตัดรถที่ขายแล้วออก) */
$sqlMachines = "
SELECT a.machine_id,a.code,c.brand_name,b.model_name,d.status_name
FROM machines a
JOIN models b ON a.model_id = b.model_id
JOIN brands c ON b.brand_id = c.brand_id
JOIN machine_status d ON a.status = d.status_id
WHERE a.status != 4
ORDER BY a.machine_id DESC
";
$machines = $pdo->query($sqlMachines)->fetchAll(PDO::FETCH_ASSOC);
/* โหลดซัพพลายเออร์ */
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ตรวจ CSRF
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  // รับค่า
  $machine_id    = (int)($_POST['machine_id'] ?? 0);
  $supplier_id   = (int)($_POST['supplier_id'] ?? 0);
  $acquired_at   = trim($_POST['acquired_at'] ?? '');
  $type_value    = $_POST['type_value'] ?? '';
  $price_input   = ($_POST['base_price'] !== '') ? (float)$_POST['base_price'] : 0.00;
  $vat_rate_pct  = 7;
  $remark        = trim($_POST['remark'] ?? '');

  // validate
  if ($machine_id <= 0)  $errors[] = 'เลือกรถ';
  if ($supplier_id <= 0) $errors[] = 'เลือกผู้ขาย';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $acquired_at)) $errors[] = 'กรอกวันที่ให้ถูกต้อง (YYYY-MM-DD)';
  if ($price_input < 0) $errors[] = 'ราคารถห้ามติดลบ';
  if ($vat_rate_pct < 0 || $vat_rate_pct > 100) $errors[] = 'อัตรา VAT ต้องอยู่ระหว่าง 0–100';
  if (!in_array($type_value, ['1', '2', '3'], true)) $errors[] = 'เลือกประเภทราคา';

  // มีรถ/ผู้ขายจริงไหม
  if (!$errors) {
    $chk = $pdo->prepare("SELECT 1 FROM machines WHERE machine_id=? LIMIT 1");
    $chk->execute([$machine_id]);
    if (!$chk->fetch()) $errors[] = 'ไม่พบรถที่เลือก';

    $chk = $pdo->prepare("SELECT 1 FROM suppliers WHERE supplier_id=? LIMIT 1");
    $chk->execute([$supplier_id]);
    if (!$chk->fetch()) $errors[] = 'ไม่พบผู้ขายที่เลือก';
  }

  // คำนวณตามประเภทราคา
  $vat_amount   = 0.00;
  $total_amount = 0.00;
  $base_price   = 0.00;

  if (!$errors) {
    if ($type_value === '1') {
      // รวมภาษี: ช่องราคา = ราคาพร้อม VAT
      $gross        = $price_input;
      $base_price   = $price_input;
      $base_price2   = round($gross / (1 + ($vat_rate_pct / 107)), 2);
      $vat_amount   = round($gross - $base_price2, 2);
      $total_amount = $price_input;
    } elseif ($type_value === '2') {
      // ไม่รวมภาษี
      $base_price   = $price_input;
      $vat_amount   = round($base_price * ($vat_rate_pct / 100), 2);
      $total_amount = round($base_price + $vat_amount, 2);
    } elseif ($type_value === '3') {
      // ไม่คิดภาษี
      $base_price   = $price_input;
      $vat_amount   = 0.00;
      $total_amount = $base_price;
    }
  }

  if (!$errors) {
    
      $pdo->beginTransaction();

      // === ออกเลขเอกสารล่วงหน้า (TB + YYYYMMDD ของวันที่ซื้อ + running 3 หลัก) ===
      // ตารางเก็บลำดับรายวัน (ครั้งแรกจะสร้างให้)
      $pdo->exec("
        CREATE TABLE IF NOT EXISTS doc_sequences (
          doc_date DATE PRIMARY KEY,
          last_no  INT NOT NULL
        ) ENGINE=InnoDB
      ");

      $docDate   = $acquired_at; // ใช้วันที่ซื้อเป็นฐานของเลขเอกสาร
      $docDateYmd= date('Ymd', strtotime($docDate));

      // ดันตัวนับขึ้นแบบ atomic (กันชนกันเวลาเขียนพร้อมกัน)
      $seqStmt = $pdo->prepare("
        INSERT INTO doc_sequences (doc_date, last_no)
        VALUES (:d, 1)
        ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)
      ");
      $seqStmt->execute([':d' => $docDate]);
      $seq = (int)$pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn();

      // ถ้าต้องการให้ type 3 ไม่มีเลขเอกสารจริงๆ แนะนำให้แก้สคีมาเป็น NULL ได้
      // แต่เพื่อเลี่ยง NOT NULL/UNIQUE เราจะ 'ออกเลขให้ทุกกรณี'
      $doc_no = 'TB' . $docDateYmd . str_pad($seq, 3, '0', STR_PAD_LEFT);

      // === INSERT acquisitions โดยใส่ doc_no ไปด้วยเลย ===
      $ins = $pdo->prepare("
        INSERT INTO acquisitions
          (doc_no, machine_id, supplier_id, acquired_at, type_buy, base_price,
           vat_rate_pct, vat_amount, total_amount, remark)
        VALUES
          (:doc_no, :machine_id, :supplier_id, :acquired_at, :type_buy, :base_price,
           :vat_rate_pct, :vat_amount, :total_amount, :remark)
      ");
      $ins->execute([
        ':doc_no'       => $doc_no,
        ':machine_id'   => $machine_id,
        ':supplier_id'  => $supplier_id,
        ':acquired_at'  => $acquired_at,
        ':type_buy'     => $type_value,
        ':base_price'   => $base_price,
        ':vat_rate_pct' => $vat_rate_pct,
        ':vat_amount'   => $vat_amount,
        ':total_amount' => $total_amount,
        ':remark'       => ($remark !== '' ? $remark : null),
      ]);

      $acquisition_id = (int)$pdo->lastInsertId();

      // === ผูก cashflow ===
      $type_clashflow = 2; // รายจ่าย
      $type2_cashflow = 1; // ซื้อรถเข้า

      $cf = $pdo->prepare("
        INSERT INTO cashflow
          (type_cashflow, type2_cashflow, machine_id, sale_id, acquisition_id,
           doc_no, doc_date, amount, remark)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $cf->execute([
        $type_clashflow,
        $type2_cashflow,
        $machine_id,
        null,
        $acquisition_id,
        $doc_no,
        $acquired_at,
        $total_amount,
        ($remark !== '' ? $remark : 'Auto: ซื้อรถเข้า')
      ]);

      $sql_status_machine = "UPDATE machines SET status=2 WHERE machine_id=?";
      $pdo->prepare($sql_status_machine)->execute([$machine_id]);
      

      header('Location: acquisitions.php?ok=' . urlencode('บันทึกการซื้อเรียบร้อย'));
      exit;
   
  }
}


// helper
function status_label($x)
{
  return [1 => 'รับเข้า', 2 => 'พร้อมขาย', 3 => 'จอง', 4 => 'ขายแล้ว', 5 => 'ซ่อมบำรุง'][$x] ?? 'รับเข้า';
}
?>
<!doctype html>
<html lang="th">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>เพิ่มเอกสารซื้อรถเข้า — ProInspect Machinery</title>
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
        <h2 class="page-title">เพิ่มเอกสารซื้อรถเข้า</h2>
        <div class="page-sub">กรอกรายละเอียดการซื้อ ระบบคำนวณ VAT/ยอดรวมอัตโนมัติ</div>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <section class="card">
        <form method="post" id="addForm" novalidate>
          <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <label>รถ (รหัส/ยี่ห้อ/สถานะ)</label>
          <select class="select" name="machine_id" id="machine_id" required>
            <option value="">— เลือก —</option>
            <?php foreach ($machines as $m): ?>
              <option value="<?= $m['machine_id'] ?>"><?= htmlspecialchars($m['code'] . ' — ' . $m['brand_name'] . ' - ' . $m['model_name'] . ' (' . $m['status_name'] . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label style="margin-top:10px;">ผู้ขาย</label>
          <select class="select" name="supplier_id" required>
            <option value="">— เลือก —</option>
            <?php foreach ($suppliers as $s): ?>
              <option value="<?= $s['supplier_id'] ?>" <?= (($_POST['supplier_id'] ?? '') == $s['supplier_id']) ? 'selected' : ''; ?>>
                <?= htmlspecialchars($s['supplier_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <div class="row" style="margin-top:10px;">
            <!--<div>-->
            <!--  <label>เลขเอกสาร</label>-->
            <!--  <input class="input" type="text" name="doc_no" value="<?= htmlspecialchars($_POST['doc_no'] ?? '') ?>">-->
            <!--</div>-->
            <div>
              <label>วันที่ซื้อ</label>
              <input class="input" type="date" name="acquired_at" required value="<?= htmlspecialchars($_POST['acquired_at'] ?? date('Y-m-d')) ?>">
            </div>
          </div>

          <div class="row" style="margin-top:10px;">
            <div>
              <label>ประเภทราคา</label>
              <select class="select" style="width:150px;" name="type_value" id="type_value" required>
                <option value="" selected>— เลือก —</option>
                <option value="1">รวมภาษี</option>
                <option value="2">ไม่รวมภาษี</option>
                <option value="3">นอกระบบ</option>
              </select>
            </div>

            <div>
              <label id="price_label">ราคา</label>
              <!-- ช่องแสดงผล (มีคอมม่า) -->
              <input class="input money-mask" type="text" inputmode="decimal"
                id="base_price_view" placeholder="0.00"
                value="<?= htmlspecialchars(isset($_POST['base_price']) ? number_format((float)$_POST['base_price'], 2) : '0.00') ?>">
              <!-- ค่าจริง (ไม่มีคอมม่า) -->
              <input type="hidden" name="base_price" id="base_price"
                value="<?= htmlspecialchars($_POST['base_price'] ?? '0.00') ?>">
              <div id="price_hint" class="muted" style="font-size:.85rem;"></div>
            </div>
          </div>

          <input type="hidden" name="vat_rate_pct" id="vat_rate_pct" value="<?= htmlspecialchars($_POST['vat_rate_pct'] ?? '7.00') ?>">

          <div class="row" style="margin-top:10px;">
            <div>
              <label>ยอด VAT 7% (คำนวณอัตโนมัติ)</label>
              <input class="input money-mask" type="text" inputmode="decimal" id="vat_amount_view" readonly
                value="<?= htmlspecialchars(number_format((float)($_POST['vat_amount'] ?? 0), 2)) ?>">
              <input type="hidden" name="vat_amount" id="vat_amount" value="<?= htmlspecialchars($_POST['vat_amount'] ?? '0.00') ?>">
            </div>
            <div>
              <label>ยอดรวม (คำนวณอัตโนมัติ)</label>
              <input class="input money-mask" type="text" inputmode="decimal" id="total_amount_view" readonly
                value="<?= htmlspecialchars(number_format((float)($_POST['total_amount'] ?? 0), 2)) ?>">
              <input type="hidden" name="total_amount" id="total_amount" value="<?= htmlspecialchars($_POST['total_amount'] ?? '0.00') ?>">
            </div>
          </div>

          <label style="margin-top:10px;">หมายเหตุ</label>
          <input class="input" type="text" name="remark" value="<?= htmlspecialchars($_POST['remark'] ?? '') ?>">

          <div style="margin-top:14px;display:flex;gap:8px;">
            <button class="btn btn-brand" type="submit">บันทึก</button>
            <a class="btn btn-outline" href="acquisitions.php">ยกเลิก</a>
          </div>
        </form>
      </section>
    </main>
  </div>
    <script src="assets/script.js"></script>
  <script>
    let lastMachinePrice = null; // purchase_price ล่าสุดของรถ

    // ---------- ฟังก์ชันช่วยฟอร์แมตเงิน ----------
    const fmt = new Intl.NumberFormat('th-TH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });

    function toNumber(s) {
      if (s == null) return 0;
      const n = parseFloat(String(s).replace(/,/g, ''));
      return isNaN(n) ? 0 : n;
    }

    function setMoney(viewId, hiddenId, num) {
      const v = document.getElementById(viewId);
      const h = document.getElementById(hiddenId);
      const n = (typeof num === 'number') ? num : toNumber(v.value);
      v.value = fmt.format(n);
      if (h) h.value = n.toFixed(2);
    }
    // ------------------------------------------------

    function setPriceFieldState() {
      const mode = (document.getElementById('type_value').value || '');
      const vPrice = document.getElementById('base_price_view');
      const label = document.getElementById('price_label');
      const hint = document.getElementById('price_hint');
      const rate = toNumber(document.getElementById('vat_rate_pct').value) || 0;

      if (mode === '1') { // รวมภาษี
        label.textContent = 'ราคา (รวมภาษี)';
        if (lastMachinePrice !== null) {
          vPrice.readOnly = true;
          setMoney('base_price_view', 'base_price', +lastMachinePrice);
          hint.textContent = 'ดึงจากราคาซื้อของรถ (purchase_price) และใช้เป็นยอดรวม';
        } else {
          vPrice.readOnly = false;
          hint.textContent = 'ไม่พบราคาซื้อของรถ อนุญาตให้กรอกเอง (รวม VAT)';
        }

      } else if (mode === '2') { // ไม่รวมภาษี
        label.textContent = 'ราคา (ไม่รวมภาษี)';
        if (lastMachinePrice !== null && lastMachinePrice > 0) {
          vPrice.readOnly = true; // ถ้าอยากให้แก้ไขได้ เปลี่ยนเป็น false ได้
          // ใช้ราคาจาก machine เป็น "ราคาก่อน VAT"
          setMoney('base_price_view', 'base_price', +lastMachinePrice);
          hint.textContent = 'ดึงจากราคาซื้อของรถ (ก่อน VAT) และคำนวณ VAT เพิ่มอัตโนมัติ';
        } else {
          vPrice.readOnly = false;
          hint.textContent = 'กรอกราคาก่อน VAT ระบบจะคำนวณ VAT/รวมให้';
        }

      } else if (mode === '3') { // นอกระบบ (ไม่คิดภาษี)
        label.textContent = 'ราคา (ไม่คิดภาษี)';
        if (lastMachinePrice !== null) {
          vPrice.readOnly = true;
          setMoney('base_price_view', 'base_price', +lastMachinePrice);
          hint.textContent = 'ดึงจากราคาซื้อของรถ (purchase_price) และถือเป็นยอดรวม (ไม่คิด VAT)';
        } else {
          vPrice.readOnly = false;
          hint.textContent = 'ไม่พบราคาซื้อของรถ อนุญาตให้กรอกเอง (ไม่คิด VAT)';
        }

      } else {
        label.textContent = 'ราคา';
        vPrice.readOnly = false;
        hint.textContent = '';
      }
    }




    function recalc() {
      const mode = (document.getElementById('type_value').value || '');
      const rate = toNumber(document.getElementById('vat_rate_pct').value) || 0;
      const val = toNumber(document.getElementById('base_price_view').value) || 0;

      let vat = 0,
        total = 0;

      if (mode === '1') {
        // รวมภาษี: val คือราคารวม
        const baseExcl = val / (1 + (rate / 107));
        vat = +(val - baseExcl).toFixed(2);
        total = +val.toFixed(2);
      } else if (mode === '2') {
        // ไม่รวมภาษี: val คือราคาก่อน VAT
        vat = +(val * (rate / 100)).toFixed(2);
        total = +(val + vat).toFixed(2);
      } else if (mode === '3') {
        // ไม่คิดภาษี: total = val, VAT = 0
        vat = 0;
        total = +val.toFixed(2);
      } else {
        // ยังไม่เลือก
        vat = 0;
        total = 0;
      }

      // อัปเดตช่องมองเห็น + hidden
      setMoney('base_price_view', 'base_price', val);
      setMoney('vat_amount_view', 'vat_amount', vat);
      setMoney('total_amount_view', 'total_amount', total);
    }


    async function fetchMachinePrice(mid) {
      lastMachinePrice = null;
      if (!mid) return;
      try {
        const res = await fetch(`acquisition_add.php?ajax=machine_price&mid=${encodeURIComponent(mid)}`, {
          cache: 'no-store'
        });
        const j = await res.json();
        if (j && j.ok) lastMachinePrice = (j.price !== null) ? +j.price : null;
      } catch (e) {
        lastMachinePrice = null;
      }
    }

    document.addEventListener('DOMContentLoaded', async () => {
      const selMachine = document.getElementById('machine_id');
      const selType = document.getElementById('type_value');
      const vPrice = document.getElementById('base_price_view');

      // ปรับมาส์กเงินขณะพิมพ์ (เฉพาะราคา ซึ่งแก้ไขได้ในบางโหมด)
      vPrice.addEventListener('input', () => {
        // ฟอร์แมตคร่าว ๆ: ลบตัวอักษรที่ไม่ใช่เลข/จุด แล้วอย่าใส่คอมม่าระหว่างพิมพ์มากไป
        let raw = String(vPrice.value).replace(/[^0-9.]/g, '');
        const parts = raw.split('.');
        if (parts.length > 2) raw = parts[0] + '.' + parts.slice(1).join('');
        vPrice.value = raw;
        recalc();
      });
      vPrice.addEventListener('blur', recalc);

      // เมื่อเลือก/เปลี่ยนรถ → ดึงราคา purchase_price
      selMachine.addEventListener('change', async () => {
        await fetchMachinePrice(selMachine.value);
        setPriceFieldState();

        const rate = toNumber(document.getElementById('vat_rate_pct').value) || 0;
        if (lastMachinePrice !== null) {
          if (selType.value === '1' || selType.value === '3') {
            setMoney('base_price_view', 'base_price', +lastMachinePrice);
          } else if (selType.value === '2') {
            // ไม่รวมภาษี: ใช้ราคาจาก machine เป็นราคาก่อน VAT
            setMoney('base_price_view', 'base_price', +lastMachinePrice);
          }
        }
        recalc();
      });

      // เมื่อเปลี่ยนประเภทราคา → ปรับการล็อกช่องและคำนวณ
      selType.addEventListener('change', () => {
        setPriceFieldState();

        const rate = toNumber(document.getElementById('vat_rate_pct').value) || 0;
        if (lastMachinePrice !== null) {
          if (selType.value === '1' || selType.value === '3') {
            setMoney('base_price_view', 'base_price', +lastMachinePrice);
          } else if (selType.value === '2') {
            // ไม่รวมภาษี: ใช้ราคาจาก machine เป็นราคาก่อน VAT
            setMoney('base_price_view', 'base_price', +lastMachinePrice);
          }
        }
        recalc();
      });


      // เริ่มต้น: ถ้ามี machine_id ที่ POST มาอยู่แล้ว ลองโหลดราคา
      if (selMachine.value) {
        await fetchMachinePrice(selMachine.value);
      }
      setPriceFieldState();
      recalc();

      // ยืนยันก่อนส่งฟอร์ม และบังคับ sync hidden ล่าสุด
      document.getElementById('addForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const mid = document.querySelector('[name="machine_id"]').value;
        const sid = document.querySelector('[name="supplier_id"]').value;
        const typ = document.querySelector('[name="type_value"]').value;
        if (!mid) {
          Swal.fire({
            icon: 'warning',
            title: 'เลือกรถ',
            confirmButtonColor: '#fec201'
          });
          return;
        }
        if (!sid) {
          Swal.fire({
            icon: 'warning',
            title: 'เลือกผู้ขาย',
            confirmButtonColor: '#fec201'
          });
          return;
        }
        if (!typ) {
          Swal.fire({
            icon: 'warning',
            title: 'เลือกประเภทราคา',
            confirmButtonColor: '#fec201'
          });
          return;
        }

        // sync hidden ให้เป็นเลขจริงก่อนส่ง
        recalc();

        Swal.fire({
          icon: 'question',
          title: 'ยืนยันบันทึกเอกสารซื้อ?',
          showCancelButton: true,
          confirmButtonText: 'บันทึก',
          cancelButtonText: 'ยกเลิก',
          reverseButtons: true,
          confirmButtonColor: '#fec201'
        }).then(res => {
          if (res.isConfirmed) e.target.submit();
        });
      });
    });
  </script>
</body>

</html>