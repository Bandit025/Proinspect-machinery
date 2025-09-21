<?php
/** sale_edit.php — แสดงฟอร์มแก้ไข (GET) + บันทึกอัปเดต (POST) */
require __DIR__ . '/config.php';

if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน'));
  exit;
}

/* Helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function nf($n){ return number_format((float)$n, 2, '.', ''); }

/* CSRF */
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ===================== POST: Save update ===================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    header('Location: sales.php?error=' . urlencode('CSRF token ไม่ถูกต้อง')); exit;
  }

  $sale_id        = (int)($_POST['sale_id'] ?? 0);
  $machine_id_new = (int)($_POST['machine_id'] ?? 0);
  $customer_id    = (int)($_POST['customer_id'] ?? 0);
  $sold_at        = $_POST['sold_at'] ?? date('Y-m-d');
  $type_value     = trim($_POST['type_value'] ?? '');
  $base_price     = (float)($_POST['base_price'] ?? 0);      // หลังหักส่วนลด (ตาม logic ฝั่ง JS)
  $discount_amt   = (float)($_POST['discount_amt'] ?? 0);
  $vat_rate_pct   = (float)($_POST['vat_rate_pct'] ?? 0);
  $total_amount   = (float)($_POST['total_amount'] ?? 0);    // รวม VAT (ถ้ามี) หลังส่วนลด
  $commission_amt = (float)($_POST['commission_amt'] ?? 0);
  $transport_cost = (float)($_POST['transport_cost'] ?? 0);  // ✅ ค่าขนส่ง
  $remark         = trim($_POST['remark'] ?? '');

  if ($sale_id <= 0) {
    header('Location: sales.php?error=' . urlencode('ไม่พบเลขที่เอกสารขาย')); exit;
  }

  try {
    $pdo->beginTransaction();

    // ข้อมูลเดิมของรายการขาย
    $row = $pdo->prepare("SELECT sale_id, machine_id FROM sales WHERE sale_id=? LIMIT 1");
    $row->execute([$sale_id]);
    $old = $row->fetch(PDO::FETCH_ASSOC);
    if (!$old) throw new Exception('ไม่พบรายการขายเดิม');
    $machine_id_old = (int)$old['machine_id'];

    // ===== คำนวณกำไร/ขาดทุน เพื่ออัปเดต pl_status / pl_amount =====
    // ราคาซื้อจาก machines (ถ้าใช้ acquisitions ให้ปรับ query ตามแหล่งข้อมูลจริง)
    $stm = $pdo->prepare("SELECT COALESCE(purchase_price,0) FROM machines WHERE machine_id=? LIMIT 1");
    $stm->execute([$machine_id_new]);
    $purchase_price = (float)$stm->fetchColumn();

    // รวมค่าใช้จ่ายของรถจาก machine_expenses (ใช้ total_cost)
    $stm = $pdo->prepare("SELECT COALESCE(SUM(total_cost),0) FROM machine_expenses WHERE machine_id=?");
    $stm->execute([$machine_id_new]);
    $expenses_total = (float)$stm->fetchColumn();

    $sell_total = $total_amount; // ยอดขายสุทธิที่หน้าแบบฟอร์มคำนวณมาแล้ว
    $profit     = $sell_total - ($purchase_price + $expenses_total + $commission_amt + $transport_cost);

    $pl_status = $profit > 0 ? 1 : ($profit < 0 ? -1 : 0);
    $pl_amount = abs($profit);

    // ออกเลขเอกสาร (เฉพาะ type 1/2)
    $docNo = '';
    if ($type_value === '1' || $type_value === '2') {
      $docNo = sprintf('TS%s%03d', date('Ymd', strtotime($sold_at ?: 'now')), $sale_id);
    }

    // อัปเดต sales (รวม transport_cost / pl_status / pl_amount)
    $sql = "UPDATE sales SET
              machine_id      = :mid,
              customer_id     = :cid,
              sold_at         = :sold_at,
              type_value      = :typ,
              sale_price      = :base_price,
              discount_amt    = :disc,
              vat_rate_pct    = :vat_pct,
              total_amount    = :total,
              commission_amt  = :comm,
              transport_cost  = :trans,
              remark          = :rem,
              doc_no          = :doc_no,
              pl_status       = :pl_status,
              pl_amount       = :pl_amount
            WHERE sale_id     = :sid";
    $stm = $pdo->prepare($sql);
    $stm->execute([
      ':mid'        => $machine_id_new,
      ':cid'        => $customer_id,
      ':sold_at'    => $sold_at,
      ':typ'        => $type_value,
      ':base_price' => $base_price,
      ':disc'       => $discount_amt,
      ':vat_pct'    => $vat_rate_pct,
      ':total'      => $total_amount,
      ':comm'       => $commission_amt,
      ':trans'      => $transport_cost,
      ':rem'        => $remark,
      ':doc_no'     => $docNo,
      ':pl_status'  => $pl_status,
      ':pl_amount'  => number_format($pl_amount, 2, '.', ''),
      ':sid'        => $sale_id,
    ]);

    // อัปเดตสถานะเครื่อง
    if ($machine_id_new !== $machine_id_old) {
      // คันเดิม: เปลี่ยนกลับเป็นพร้อมขาย (2) เฉพาะถ้าปัจจุบันเป็นขายแล้ว (3)
      $pdo->prepare("UPDATE machines SET status=2 WHERE machine_id=? AND status=3")->execute([$machine_id_old]);
      // คันใหม่: ตั้งเป็นขายแล้ว (3)
      $pdo->prepare("UPDATE machines SET status=3 WHERE machine_id=?")->execute([$machine_id_new]);
    } else {
      $pdo->prepare("UPDATE machines SET status=3 WHERE machine_id=?")->execute([$machine_id_new]);
    }

    // อัปเดต cashflow ที่ผูกกับ sale_id
    $upd = $pdo->prepare("UPDATE cashflow SET
          type_cashflow  = 1,
          type2_cashflow = 2,
          machine_id     = :mid,
          doc_no         = :doc_no,
          doc_date       = :doc_date,
          amount         = :amt,
          remark         = :rem
        WHERE sale_id     = :sale_id");
    $upd->execute([
      ':mid'      => $machine_id_new,
      ':doc_no'   => $docNo,
      ':doc_date' => $sold_at,
      ':amt'      => $total_amount,
      ':rem'      => ($remark !== '' ? $remark : 'ขายรถออก'),
      ':sale_id'  => $sale_id,
    ]);

    $pdo->commit();
    header('Location: sales.php?ok=' . urlencode('แก้ไขเอกสารขายเรียบร้อย')); exit;

  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: sales.php?error=' . urlencode('อัปเดตไม่สำเร็จ: '.$e->getMessage())); exit;
  }
}

/* ===================== GET: Show edit form ===================== */
$sale_id = (int)($_GET['sale_id'] ?? $_GET['id'] ?? 0);
if ($sale_id <= 0) {
  header('Location: sales.php?error=' . urlencode('ไม่พบเลขที่เอกสารขาย')); exit;
}

// ดึงรายการขาย
$stm = $pdo->prepare("
  SELECT s.*,
         m.code, m.machine_id,
         mo.model_name, b.brand_name,
         ms.status_name
  FROM sales s
  LEFT JOIN machines m ON m.machine_id = s.machine_id
  LEFT JOIN models mo   ON mo.model_id = m.model_id
  LEFT JOIN brands b    ON b.brand_id  = mo.brand_id
  LEFT JOIN machine_status ms ON ms.status_id = m.status
  WHERE s.sale_id = ?
  LIMIT 1
");
$stm->execute([$sale_id]);
$sale = $stm->fetch(PDO::FETCH_ASSOC);
if (!$sale) {
  header('Location: sales.php?error=' . urlencode('ไม่พบรายการขาย')); exit;
}

$current_machine_id = (int)$sale['machine_id'];

// คันปัจจุบัน
$cur = $pdo->prepare("
  SELECT m.machine_id, m.code, b.brand_name, mo.model_name, ms.status_name
  FROM machines m
  JOIN models mo ON mo.model_id = m.model_id
  LEFT JOIN brands b ON b.brand_id = mo.brand_id
  JOIN machine_status ms ON m.status = ms.status_id
  WHERE m.machine_id = ?
  LIMIT 1
");
$cur->execute([$current_machine_id]);
$currentMachine = $cur->fetch(PDO::FETCH_ASSOC);

// คันพร้อมขาย (status=2) ยกเว้นคันปัจจุบัน
$machines = $pdo->prepare("
  SELECT m.machine_id, m.code, b.brand_name, mo.model_name, ms.status_name
  FROM machines m
  JOIN models mo ON mo.model_id = m.model_id
  LEFT JOIN brands b ON b.brand_id = mo.brand_id
  JOIN machine_status ms ON m.status = ms.status_id
  WHERE m.status = 2 AND m.machine_id <> ?
  ORDER BY m.machine_id DESC
");
$machines->execute([$current_machine_id]);
$machineList = $machines->fetchAll(PDO::FETCH_ASSOC);
if ($currentMachine) array_unshift($machineList, $currentMachine);

// ลูกค้า
$customers = $pdo->query("SELECT customer_id, customer_name FROM customers ORDER BY customer_name")->fetchAll(PDO::FETCH_ASSOC);

// ค่าเริ่มต้นฟอร์ม
$type_value     = (string)($sale['type_value'] ?? '');
$sale_price     = (float)($sale['sale_price'] ?? 0);
$discount_amt   = (float)($sale['discount_amt'] ?? 0);
$commission_amt = (float)($sale['commission_amt'] ?? 0);
$transport_cost = (float)($sale['transport_cost'] ?? 0); // ✅
$vat_rate_pct   = (float)($sale['vat_rate_pct'] ?? 7);
$total_amount   = (float)($sale['total_amount'] ?? 0);
$sold_at        = $sale['sold_at'] ?? date('Y-m-d');

$price_input_guess = $sale_price + $discount_amt; // แสดงในช่องกรอก (ก่อนหักส่วนลด)

// VAT view เริ่มต้น
if ($type_value === '1') {
  $vat_amount_view = $sale_price * $vat_rate_pct / (100 + $vat_rate_pct);
} elseif ($type_value === '2') {
  $vat_amount_view = $sale_price * $vat_rate_pct / 100;
} else {
  $vat_amount_view = 0;
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>แก้ไขเอกสารขายรถ — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=30">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <!-- Override หน้านี้ให้ layout สองคอลัมน์ + summary sticky -->
  <style>
    .sale-page .card { width:100%!important; max-width:none!important; }
    .sale-grid { display:grid; grid-template-columns:minmax(0,1fr) 420px; gap:16px; align-items:start; }
    #summaryCard { position:sticky; top:12px; }
    @media (max-width:1200px){ .sale-grid{ grid-template-columns:1fr; } #summaryCard{ position:static; } }
    .sumtable{width:100%; border-collapse:collapse;}
    .sumtable td,.sumtable th{padding:6px 8px; border-bottom:1px dashed #e5e7eb;}
    .text-end{text-align:right;}
    .muted{color:#6b7280; font-size:.9rem;}
  </style>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content sale-page">
      <div class="page-head">
        <h2 class="page-title">แก้ไขเอกสารขายรถ</h2>
        <div class="page-sub">เลขที่เอกสาร: <?= h($sale['doc_no'] ?: '—') ?></div>
      </div>

      <div class="sale-grid">
        <!-- ซ้าย: ฟอร์ม -->
        <section class="card">
          <form method="post" id="editForm" novalidate>
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="sale_id" value="<?= (int)$sale['sale_id'] ?>">

            <label>รถ (รหัส/ยี่ห้อ/สถานะ)</label>
            <select class="select" name="machine_id" id="machine_id" required>
              <?php foreach ($machineList as $m): ?>
                <option value="<?= (int)$m['machine_id'] ?>" <?= ((int)$m['machine_id']===(int)$sale['machine_id']?'selected':'') ?>>
                  <?= h($m['code'].' — '.$m['brand_name'].' - '.$m['model_name'].' ('.$m['status_name'].')') ?>
                </option>
              <?php endforeach; ?>
            </select>

            <label style="margin-top:10px;">ลูกค้า</label>
            <select class="select" name="customer_id" required>
              <option value="">— เลือก —</option>
              <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['customer_id'] ?>" <?= ((int)$c['customer_id']===(int)$sale['customer_id']?'selected':'') ?>>
                  <?= h($c['customer_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>

            <div class="row" style="margin-top:10px;">
              <div>
                <label>วันที่ขาย</label>
                <input class="input" type="date" name="sold_at" required value="<?= h($sold_at) ?>">
              </div>
              <div>
                <label>ประเภทราคา</label>
                <select class="select" name="type_value" id="type_value" required>
                  <option value="1" <?= ($type_value==='1'?'selected':'') ?>>รวมภาษี</option>
                  <option value="2" <?= ($type_value==='2'?'selected':'') ?>>ไม่รวมภาษี</option>
                  <option value="3" <?= ($type_value==='3'?'selected':'') ?>>นอกระบบ</option>
                </select>
              </div>
            </div>

            <div>
              <label>ราคา (ก่อนหักส่วนลด)</label>
              <input class="input" type="text" inputmode="decimal" id="base_price_view" value="<?= number_format($price_input_guess,2) ?>">
              <input type="hidden" name="base_price" id="base_price" value="<?= nf($sale_price) ?>">
              <div class="muted">ระบบจะหักส่วนลดและคำนวณ VAT ให้อัตโนมัติ</div>
            </div>

            <div class="row" style="margin-top:10px;">
              <div>
                <label>ส่วนลด</label>
                <input class="input" type="text" inputmode="decimal" id="discount_amt_view" value="<?= number_format($discount_amt,2) ?>">
                <input type="hidden" name="discount_amt" id="discount_amt" value="<?= nf($discount_amt) ?>">
              </div>
              <div>
                <label>ค่านายหน้า</label>
                <input class="input" type="text" inputmode="decimal" id="commission_amt_view" value="<?= number_format($commission_amt,2) ?>">
                <input type="hidden" name="commission_amt" id="commission_amt" value="<?= nf($commission_amt) ?>">
              </div>
              <div>
                <label>ค่าขนส่ง</label> <!-- ✅ ใหม่ -->
                <input class="input" type="text" inputmode="decimal" id="transport_cost_view" value="<?= number_format($transport_cost,2) ?>">
                <input type="hidden" name="transport_cost" id="transport_cost" value="<?= nf($transport_cost) ?>">
              </div>
            </div>

            <input type="hidden" name="vat_rate_pct" id="vat_rate_pct" value="<?= nf($vat_rate_pct) ?>">

            <div class="row" style="margin-top:10px;">
              <div>
                <label>ยอด VAT (อัตโนมัติ)</label>
                <input class="input" type="text" id="vat_amount_view" readonly value="<?= number_format($vat_amount_view,2) ?>">
                <input type="hidden" name="vat_amount" id="vat_amount" value="<?= nf($vat_amount_view) ?>">
              </div>
              <div>
                <label>ยอดรวม (อัตโนมัติ)</label>
                <input class="input" type="text" id="total_amount_view" readonly value="<?= number_format($total_amount,2) ?>">
                <input type="hidden" name="total_amount" id="total_amount" value="<?= nf($total_amount) ?>">
              </div>
            </div>

            <label style="margin-top:10px;">หมายเหตุ</label>
            <input class="input" type="text" name="remark" value="<?= h($sale['remark'] ?? '') ?>">

            <div style="margin-top:14px;display:flex;gap:8px;">
              <button class="btn btn-brand" type="submit">บันทึกการแก้ไข</button>
              <a class="btn btn-outline" href="sales.php">ยกเลิก</a>
            </div>
          </form>
        </section>

        <!-- ขวา: Summary -->
        <section class="card" id="summaryCard">
          <div class="page-head" style="padding:0 0 8px;">
            <h3 class="page-title" style="margin:0;font-size:16px;">สรุปการขาย</h3>
          </div>
          <div id="summaryBody"></div>
        </section>
      </div>
    </main>
  </div>

  <script>
    (() => {
      const toNum = v => { v=(v||'').toString().replace(/,/g,'').trim(); const n=parseFloat(v); return isFinite(n)?n:0; };
      const fmt  = n => (new Intl.NumberFormat('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2})).format(Number(n||0));

      const typeSel   = document.getElementById('type_value');
      const priceView = document.getElementById('base_price_view');
      const priceHid  = document.getElementById('base_price');
      const vatRateEl = document.getElementById('vat_rate_pct');
      const vatView   = document.getElementById('vat_amount_view');
      const vatHid    = document.getElementById('vat_amount');
      const totView   = document.getElementById('total_amount_view');
      const totHid    = document.getElementById('total_amount');
      const discView  = document.getElementById('discount_amt_view');
      const discHid   = document.getElementById('discount_amt');
      const commView  = document.getElementById('commission_amt_view');
      const commHid   = document.getElementById('commission_amt');
      const transView = document.getElementById('transport_cost_view');   // ✅
      const transHid  = document.getElementById('transport_cost');        // ✅
      const sumBody   = document.getElementById('summaryBody');

      function recalc(){
        const t    = String(typeSel?.value || '');
        const inp  = toNum(priceView.value);
        const r    = toNum(vatRateEl.value || '7');
        const disc = Math.max(0, toNum(discView?.value));
        const comm = Math.max(0, toNum(commView?.value));
        const trans= Math.max(0, toNum(transView?.value)); // ✅

        discHid.value  = disc.toFixed(2);
        commHid.value  = comm.toFixed(2);
        transHid.value = trans.toFixed(2);                 // ✅

        let base=0, vat=0, tot=0;
        if (t==='1'){ // รวมภาษี
          const grossAfterDiscount = Math.max(inp - disc, 0);
          vat  = grossAfterDiscount * r / (100 + r);
          base = grossAfterDiscount; // base=total สำหรับ type 1
          tot  = grossAfterDiscount;
        } else if (t==='2'){ // ไม่รวม
          const baseAfterDiscount = Math.max(inp - disc, 0);
          vat  = baseAfterDiscount * r / 100;
          base = baseAfterDiscount;
          tot  = baseAfterDiscount + vat;
        } else { // 3 นอกระบบ
          base = Math.max(inp - disc, 0);
          vat  = 0;
          tot  = base;
        }

        priceHid.value = base.toFixed(2);
        vatHid.value   = vat.toFixed(2);
        totHid.value   = tot.toFixed(2);

        vatView.value  = fmt(vat);
        totView.value  = fmt(tot);

        updateSummary();
      }

      function updateSummary(){
        const sellTotal = toNum(totHid.value);
        const disc = toNum(discHid.value);
        const comm = toNum(commHid.value);
        const trans= toNum(transHid.value);
        const vat  = toNum(vatHid.value);
        const inputPrice = toNum(priceView.value);

        sumBody.innerHTML = `
          <table class="sumtable">
            <tbody>
              <tr><td>ราคาตั้งขาย (ก่อนส่วนลด)</td><td class="text-end">฿${fmt(inputPrice)}</td></tr>
              <tr><td>ส่วนลด</td><td class="text-end">- ฿${fmt(disc)}</td></tr>
              <tr><td>VAT</td><td class="text-end">฿${fmt(vat)}</td></tr>
              <tr><th>ราคาขายสุทธิ</th><th class="text-end">฿${fmt(sellTotal)}</th></tr>
              <tr><td>ค่านายหน้า</td><td class="text-end">- ฿${fmt(comm)}</td></tr>
              <tr><td>ค่าขนส่ง</td><td class="text-end">- ฿${fmt(trans)}</td></tr>
            </tbody>
          </table>`;
      }

      [priceView, discView, commView, transView].forEach(el=>{
        el.addEventListener('input', recalc);
        el.addEventListener('blur', ()=>{ el.value = fmt(toNum(el.value)); recalc(); });
      });
      typeSel.addEventListener('change', recalc);
      recalc();
    })();
  </script>
  <script src="assets/script.js?v=3"></script>
</body>
</html>
