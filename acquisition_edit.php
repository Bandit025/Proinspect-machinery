<?php
// acquisition_edit.php (แก้ไขใหม่ทั้งไฟล์ พร้อมแก้ dropdown ประเภทราคา, ดึงรายชื่อรถให้รวมคันที่ขายไปแล้วถ้าเป็นคันเดิม, และ sync cashflow แบบไม่บังคับตายตัว)
require __DIR__ . '/config.php';

// if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1) !== 2) {
//   header('Location: acquisitions.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ'));
//   exit;
// }
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

$errors = [];
$ok  = $_GET['ok']    ?? '';
$err = $_GET['error'] ?? '';

$acq_id = (int)($_GET['id'] ?? 0);
if ($acq_id <= 0) {
  header('Location: acquisitions.php?error=' . urlencode('ไม่พบรหัสเอกสารที่จะแก้ไข'));
  exit;
}

// โหลดข้อมูลรายการที่จะแก้ไข
$sqlLoad = "SELECT acquisition_id, machine_id, supplier_id, doc_no, acquired_at, type_buy,
                   base_price, vat_rate_pct, vat_amount, total_amount, remark
            FROM acquisitions WHERE acquisition_id=? LIMIT 1";
$stm = $pdo->prepare($sqlLoad);
$stm->execute([$acq_id]);
$acq = $stm->fetch(PDO::FETCH_ASSOC);

if (!$acq) {
  header('Location: acquisitions.php?error=' . urlencode('ไม่พบเอกสารที่ต้องการแก้ไข'));
  exit;
}

// ค่าประเภทราคา/เลย์เอาต์ฟอร์ม
$TYPE_OPTIONS = ['1' => 'รวมภาษี', '2' => 'ไม่รวมภาษี', '3' => 'นอกระบบ'];
$currentType  = (string)($_POST['type_value'] ?? ($acq['type_buy'] ?? ''));
$old_doc_no   = $acq['doc_no'];
$vat_rate_default = (float)($acq['vat_rate_pct'] ?? 7);

// โหลดรายการรถ (ให้แสดงคันเดิมแม้ขายแล้ว) + LEFT JOIN แบรนด์กัน info ว่าง
try {
  $sqlMachines = "SELECT m.machine_id, m.code, COALESCE(b.brand_name,'ไม่ระบุ') AS brand_name, m.status
                  FROM machines m
                  LEFT JOIN brands b ON b.brand_id = m.brand_id
                  WHERE (m.status <> 4 OR m.machine_id = :cur)
                  ORDER BY COALESCE(m.created_at,'1000-01-01') DESC, m.machine_id DESC";
  $stmM = $pdo->prepare($sqlMachines);
  $stmM->execute([':cur' => (int)($acq['machine_id'] ?? 0)]);
  $machines = $stmM->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $machines = [];
}

// โหลดซัพพลายเออร์
$suppliers = $pdo->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);

/* =============== เมื่อ POST (อัปเดต) =============== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
    $errors[] = 'CSRF token ไม่ถูกต้อง';
  }

  // รับค่า (cast ให้ปลอดภัย)
  $machine_id    = (int)($_POST['machine_id'] ?? 0);
  $supplier_id   = (int)($_POST['supplier_id'] ?? 0);
  $acquired_at   = trim($_POST['acquired_at'] ?? '');
  $type_value    = (string)($_POST['type_value'] ?? '');
  $price_input   = ($_POST['base_price'] !== '') ? (float)$_POST['base_price'] : 0.00;
  $vat_rate_pct  = 7; // fix 7%
  $remark        = trim($_POST['remark'] ?? '');

  // validate
  if ($machine_id <= 0)  $errors[] = 'เลือกรถ';
  if ($supplier_id <= 0) $errors[] = 'เลือกผู้ขาย';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $acquired_at)) $errors[] = 'กรอกวันที่ให้ถูกต้อง (YYYY-MM-DD)';
  if ($price_input < 0) $errors[] = 'ราคารถห้ามติดลบ';
  if ($vat_rate_pct < 0 || $vat_rate_pct > 100) $errors[] = 'อัตรา VAT ต้องอยู่ระหว่าง 0–100';
  if (!in_array($type_value, ['1','2','3'], true)) $errors[] = 'เลือกประเภทราคา';

  // check fk
  if (!$errors) {
    $chk = $pdo->prepare("SELECT 1 FROM machines WHERE machine_id=? LIMIT 1");
    $chk->execute([$machine_id]);
    if (!$chk->fetch()) $errors[] = 'ไม่พบรถที่เลือก';
    $chk = $pdo->prepare("SELECT 1 FROM suppliers WHERE supplier_id=? LIMIT 1");
    $chk->execute([$supplier_id]);
    if (!$chk->fetch()) $errors[] = 'ไม่พบผู้ขายที่เลือก';
  }

  // คำนวณราคา/ภาษี
  $vat_amount   = 0.00;
  $total_amount = 0.00;
  $base_price   = 0.00;

  if (!$errors) {
    if ($type_value === '1') {
      // รวมภาษี: price_input = รวม
      $gross        = $price_input;
      $base_price   = round($gross / (1 + ($vat_rate_pct/100)), 2);
      $vat_amount   = round($gross - $base_price, 2);
      $total_amount = $gross;
    } elseif ($type_value === '2') {
      // ไม่รวมภาษี: price_input = ฐานก่อน VAT
      $base_price   = $price_input;
      $vat_amount   = round($base_price * ($vat_rate_pct/100), 2);
      $total_amount = round($base_price + $vat_amount, 2);
    } else { // '3' ไม่คิดภาษี
      $base_price   = $price_input;
      $vat_amount   = 0.00;
      $total_amount = $base_price;
    }
  }

  // สร้างเลขเอกสาร ตามกฎ: ถ้า type=3 -> ว่าง, ถ้าไม่ใช่ -> ถ้ายังว่างอยู่ค่อยออกเลขตามวันนี้ + running-id (acq_id)
  $doc_no_to_save = $old_doc_no;
  if (!$errors) {
    if ($type_value === '3') {
      $doc_no_to_save = null;
    } else {
      if (empty($old_doc_no)) {
        $datePart = date('Ymd');
        if ($acq_id < 10)      $doc_no_to_save = "TB{$datePart}00{$acq_id}";
        elseif ($acq_id < 100) $doc_no_to_save = "TB{$datePart}0{$acq_id}";
        else                   $doc_no_to_save = "TB{$datePart}{$acq_id}";
      }
    }
  }

  // UPDATE + (option) sync cashflow ในทรานแซกชัน (ถ้า cashflow ไม่มี/ไม่ต้องการก็แค่ try/catch)
  if (!$errors) {
    try {
      $pdo->beginTransaction();

      $upd = $pdo->prepare("UPDATE acquisitions
        SET machine_id=:machine_id,
            supplier_id=:supplier_id,
            acquired_at=:acquired_at,
            type_buy=:type_buy,
            base_price=:base_price,
            vat_rate_pct=:vat_rate_pct,
            vat_amount=:vat_amount,
            total_amount=:total_amount,
            remark=:remark
        WHERE acquisition_id=:id
        LIMIT 1");
      $okUpd = $upd->execute([
        ':machine_id'   => $machine_id,
        ':supplier_id'  => $supplier_id,
        ':acquired_at'  => $acquired_at,
        ':type_buy'     => $type_value,
        ':base_price'   => $base_price,
        ':vat_rate_pct' => $vat_rate_pct,
        ':vat_amount'   => $vat_amount,
        ':total_amount' => $total_amount,
        ':remark'       => ($remark !== '' ? $remark : null),
        ':id'           => $acq_id,
      ]);

      if (!$okUpd) {
        throw new Exception('update acquisitions failed');
      }

      // ====== SYNC CASHFLOW (ไม่บังคับ ถ้าตารางไม่มีจะไม่ล้มงาน) ======
      try {
        // นโยบายเดียวกับหน้า add: type_cashflow = 2 (รายจ่าย), type2_cashflow = 1 (ตามที่เคยสั่งไว้)
        $selCf = $pdo->prepare("SELECT cashflow_id FROM cashflow WHERE acquisition_id=? LIMIT 1");
        $selCf->execute([$acq_id]);
        $cfId = $selCf->fetchColumn();

        if ($type_value === '3') {
          // นอกระบบ -> ถ้ามี cashflow อยู่ ให้ลบทิ้ง (ไม่คิดภาษี/ไม่ลงบัญชี)
          if ($cfId) {
            $delCf = $pdo->prepare("DELETE FROM cashflow WHERE cashflow_id=? LIMIT 1");
            $delCf->execute([$cfId]);
          }
        } else {
          if ($cfId) {
            // อัปเดตแถวเดิม
            $updCf = $pdo->prepare("UPDATE cashflow
              SET type_cashflow=2,
                  type2_cashflow=1,
                  machine_id=?,
                  sale_id=NULL,
                  doc_no=NULL,
                  doc_date=?,
                  amount=?,
                  remark=?
              WHERE cashflow_id=? LIMIT 1");
            $updCf->execute([
              $machine_id,
              $acquired_at,
              $total_amount,
              ($remark !== '' ? $remark : 'Auto: แก้ไขซื้อรถเข้า'),
              $cfId
            ]);
          } else {
            // แทรกใหม่
            $insCf = $pdo->prepare("INSERT INTO cashflow
              (type_cashflow, type2_cashflow, machine_id, sale_id, acquisition_id, doc_no, doc_date, amount, remark)
              VALUES (2, 1, ?, NULL, ?, NULL, ?, ?, ?)");
            $insCf->execute([
              $machine_id,
              $acq_id,
              $acquired_at,
              $total_amount,
              ($remark !== '' ? $remark : 'Auto: แก้ไขซื้อรถเข้า')
            ]);
          }
        }
      } catch (Throwable $e) {
        // ถ้า cashflow ไม่พร้อม/ไม่มีตาราง จะมาที่นี่ (ไม่ทำให้ธุรกรรมหลักล้ม)
        // error_log('cashflow sync skipped: '.$e->getMessage());
      }
      // ====== END SYNC CASHFLOW ======

      $pdo->commit();
      header('Location: acquisitions.php?ok=' . urlencode('แก้ไขเอกสารซื้อเรียบร้อย'));
      exit;
    } catch (Throwable $e) {
     if ($pdo->inTransaction()) $pdo->rollBack();
     $errors[] = 'บันทึกแก้ไขไม่สำเร็จ: ' .
        (($e instanceof PDOException && isset($e->errorInfo[2]))
            ? $e->errorInfo[2]    // ข้อความจากไดรเวอร์ (อ่านง่ายกว่า)
            : $e->getMessage());  // ข้อความทั่วไปจาก Exception
    }
  }
  
  $acq = array_merge($acq, [
    'machine_id'   => $machine_id,
    'supplier_id'  => $supplier_id,
    'acquired_at'  => $acquired_at,
    'type_buy'     => $type_value,
    'base_price'   => $base_price,
    'vat_rate_pct' => $vat_rate_pct,
    'vat_amount'   => $vat_amount,
    'total_amount' => $total_amount,
    'remark'       => $remark,
  ]);
  $currentType = (string)$type_value;
}

// helper
function status_label($x) {
  return [1=>'รับเข้า',2=>'พร้อมขาย',3=>'จอง',4=>'ขายแล้ว',5=>'ซ่อมบำรุง'][$x] ?? 'รับเข้า';
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>แก้ไขเอกสารซื้อรถเข้า — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=30">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <main class="content">
      <div class="page-head">
        <h2 class="page-title">แก้ไขเอกสารซื้อรถเข้า</h2>
        <div class="page-sub">ปรับข้อมูลการซื้อ ระบบคำนวณ VAT/ยอดรวมอัตโนมัติ</div>
      </div>

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul style="margin:0 0 0 18px;"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <section class="card">
        <form method="post" id="editForm" novalidate>
          <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <div class="row" style="align-items:flex-end; gap:16px;">
            <div style="flex:1;">
              <label>เลขเอกสาร</label>
              <input class="input" type="text" value="<?= htmlspecialchars($acq['doc_no'] ?? '') ?>" readonly>
            </div>
            <div>
              <label>วันที่ซื้อ</label>
              <input class="input" type="date" name="acquired_at" required
                     value="<?= htmlspecialchars($acq['acquired_at'] ?? date('Y-m-d')) ?>">
            </div>
          </div>

          <label style="margin-top:10px;">รถ (รหัส/ยี่ห้อ/สถานะ)</label>
          <select class="select" name="machine_id" id="machine_id" required>
            <option value="">— เลือก —</option>
            <?php foreach ($machines as $m): ?>
              <option value="<?= $m['machine_id'] ?>"
                <?= ((string)($acq['machine_id'] ?? '') === (string)$m['machine_id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($m['code'] . ' — ' . $m['brand_name'] . ' (' . status_label((int)$m['status']) . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>

          <label style="margin-top:10px;">ผู้ขาย</label>
          <select class="select" name="supplier_id" required>
            <option value="">— เลือก —</option>
            <?php foreach ($suppliers as $s): ?>
              <option value="<?= $s['supplier_id'] ?>"
                <?= ((string)($acq['supplier_id'] ?? '') === (string)$s['supplier_id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['supplier_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <div class="row" style="margin-top:10px;">
            <div>
              <label>ประเภทราคา</label>
              <select class="select" style="width:150px;" name="type_value" id="type_value" required>
                <option value="">— เลือก —</option>
                <?php foreach ($TYPE_OPTIONS as $val => $label): ?>
                  <option value="<?= $val ?>" <?= $currentType === (string)$val ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label id="price_label">ราคา</label>
              <!-- ช่องแสดงผล (มีคอมม่า) -->
              <input class="input money-mask" type="text" inputmode="decimal"
                     id="base_price_view" placeholder="0.00"
                     value="<?= htmlspecialchars(number_format((float)(
                       $currentType === '1'
                         ? ($acq['total_amount'] ?? 0)  // โหมดรวมภาษี แสดงรวม
                         : ($acq['base_price']   ?? 0)  // โหมดอื่น แสดง base
                     ), 2)) ?>">
              <!-- ค่าจริง (ไม่มีคอมม่า) -->
              <input type="hidden" name="base_price" id="base_price"
                     value="<?= htmlspecialchars((string)(
                       $currentType === '1'
                         ? ($acq['total_amount'] ?? 0)
                         : ($acq['base_price']   ?? 0)
                     )) ?>">
              <div id="price_hint" class="muted" style="font-size:.85rem;"></div>
            </div>
          </div>

          <input type="hidden" name="vat_rate_pct" id="vat_rate_pct" value="<?= htmlspecialchars((string)($vat_rate_default ?: 7)) ?>">

          <div class="row" style="margin-top:10px;">
            <div>
              <label>ยอด VAT 7% (คำนวณอัตโนมัติ)</label>
              <input class="input money-mask" type="text" id="vat_amount_view" readonly
                     value="<?= htmlspecialchars(number_format((float)($acq['vat_amount'] ?? 0), 2)) ?>">
              <input type="hidden" name="vat_amount" id="vat_amount" value="<?= htmlspecialchars((string)($acq['vat_amount'] ?? '0.00')) ?>">
            </div>
            <div>
              <label>ยอดรวม (คำนวณอัตโนมัติ)</label>
              <input class="input money-mask" type="text" id="total_amount_view" readonly
                     value="<?= htmlspecialchars(number_format((float)($acq['total_amount'] ?? 0), 2)) ?>">
              <input type="hidden" name="total_amount" id="total_amount" value="<?= htmlspecialchars((string)($acq['total_amount'] ?? '0.00')) ?>">
            </div>
          </div>

          <label style="margin-top:10px;">หมายเหตุ</label>
          <input class="input" type="text" name="remark" value="<?= htmlspecialchars($acq['remark'] ?? '') ?>">

          <div style="margin-top:14px;display:flex;gap:8px;">
            <button class="btn btn-brand" type="submit">บันทึกการแก้ไข</button>
            <a class="btn btn-outline" href="acquisitions.php">ยกเลิก</a>
          </div>
        </form>
      </section>
    </main>
  </div>
<script src="/assets/script.js?v=4"></script>

<!-- SweetAlert2: switch alert (ok / error / errors[]) -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  const okMsg   = <?= json_encode($ok,    JSON_UNESCAPED_UNICODE) ?>;
  const errMsg  = <?= json_encode($err,   JSON_UNESCAPED_UNICODE) ?>;
  const errList = <?= json_encode($errors,JSON_UNESCAPED_UNICODE) ?>;

  if (okMsg) {
    Swal.fire({
      icon: 'success',
      title: 'สำเร็จ',
      text: okMsg,
      timer: 1800,
      showConfirmButton: false
    }).then(() => {
      if (history.replaceState) {
        const url = new URL(location.href);
        url.searchParams.delete('ok');
        history.replaceState({}, document.title, url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash);
      }
    });
  }

  if (errMsg) {
    Swal.fire({
      icon: 'error',
      title: 'ไม่สำเร็จ',
      text: errMsg,
      confirmButtonColor: '#fec201'
    }).then(() => {
      if (history.replaceState) {
        const url = new URL(location.href);
        url.searchParams.delete('error');
        history.replaceState({}, document.title, url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash);
      }
    });
  }

  if (Array.isArray(errList) && errList.length) {
    const html = '<ul style="text-align:left;margin:0 0 0 18px;">' +
                 errList.map(e => `<li>${e}</li>`).join('') + '</ul>';
    Swal.fire({
      icon: 'error',
      title: 'ตรวจพบข้อผิดพลาด',
      html: html,
      confirmButtonColor: '#fec201'
    });
  }
});
</script>

<script>
  let lastMachinePrice = null; // purchase_price ล่าสุดของรถ

  // ---------- ฟังก์ชันช่วยฟอร์แมตเงิน ----------
  const fmt = new Intl.NumberFormat('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  function toNumber(s){ if(s==null) return 0; const n=parseFloat(String(s).replace(/,/g,'')); return isNaN(n)?0:n; }
  function setMoney(viewId, hiddenId, num){
    const v=document.getElementById(viewId), h=document.getElementById(hiddenId);
    const n=(typeof num==='number')?num:toNumber(v.value);
    v.value = fmt.format(n);
    if(h) h.value = n.toFixed(2);
  }
  // ------------------------------------------------

  function setPriceFieldState(){
    const mode=(document.getElementById('type_value').value||'');
    const vPrice=document.getElementById('base_price_view');
    const label=document.getElementById('price_label');
    const hint=document.getElementById('price_hint');

    if(mode==='1'){ // รวมภาษี
      label.textContent='ราคา (รวมภาษี)';
      if(lastMachinePrice!==null){
        vPrice.readOnly=true;
        setMoney('base_price_view','base_price',+lastMachinePrice);
        hint.textContent='ดึงจากราคาซื้อของรถ (purchase_price) และใช้เป็นยอดรวม';
      }else{
        vPrice.readOnly=false;
        hint.textContent='ไม่พบราคาซื้อของรถ อนุญาตให้กรอกเอง (รวม VAT)';
      }
    }else if(mode==='3'){ // นอกระบบ
      label.textContent='ราคา (ไม่คิดภาษี)';
      if(lastMachinePrice!==null){
        vPrice.readOnly=true;
        setMoney('base_price_view','base_price',+lastMachinePrice);
        hint.textContent='ดึงจากราคาซื้อของรถ (purchase_price) และถือเป็นยอดรวม (ไม่คิด VAT)';
      }else{
        vPrice.readOnly=false;
        hint.textContent='ไม่พบราคาซื้อของรถ อนุญาตให้กรอกเอง (ไม่คิด VAT)';
      }
    }else if(mode==='2'){ // ไม่รวมภาษี
      label.textContent='ราคา (ไม่รวมภาษี)';
      vPrice.readOnly=false;
      hint.textContent='กรอกราคาก่อน VAT ระบบจะคำนวณ VAT/รวมให้';
    }else{
      label.textContent='ราคา';
      vPrice.readOnly=false;
      hint.textContent='';
    }
  }

  function recalc(){
    const mode=(document.getElementById('type_value').value||'');
    const rate=toNumber(document.getElementById('vat_rate_pct').value)||0;
    const val=toNumber(document.getElementById('base_price_view').value)||0;

    let vat=0, total=0;
    if(mode==='1'){ // รวมภาษี
      const baseExcl = val/(1+(rate/100));
      vat=+(val-baseExcl).toFixed(2);
      total=+val.toFixed(2);
    }else if(mode==='2'){ // ไม่รวมภาษี
      vat=+(val*(rate/100)).toFixed(2);
      total=+(val+vat).toFixed(2);
    }else if(mode==='3'){ // ไม่คิดภาษี
      vat=0; total=+val.toFixed(2);
    }else{
      vat=0; total=0;
    }

    setMoney('base_price_view','base_price',val);
    setMoney('vat_amount_view','vat_amount',vat);
    setMoney('total_amount_view','total_amount',total);
  }

  async function fetchMachinePrice(mid){
    lastMachinePrice=null;
    if(!mid) return;
    try{
      const res = await fetch(`acquisition_edit.php?ajax=machine_price&mid=${encodeURIComponent(mid)}`,{cache:'no-store'});
      const j = await res.json();
      if(j && j.ok) lastMachinePrice = (j.price!==null)? +j.price : null;
    }catch(e){ lastMachinePrice=null; }
  }

  document.addEventListener('DOMContentLoaded', async ()=>{
    const selMachine=document.getElementById('machine_id');
    const selType=document.getElementById('type_value');
    const vPrice=document.getElementById('base_price_view');

    // มาส์กระหว่างพิมพ์
    vPrice.addEventListener('input', ()=>{
      let raw=String(vPrice.value).replace(/[^0-9.]/g,'');
      const parts=raw.split('.');
      if(parts.length>2) raw=parts[0]+'.'+parts.slice(1).join('');
      vPrice.value=raw;
      recalc();
    });
    vPrice.addEventListener('blur', recalc);

    selMachine.addEventListener('change', async ()=>{
      await fetchMachinePrice(selMachine.value);
      setPriceFieldState();
      if(selType.value==='1' || selType.value==='3'){
        if(lastMachinePrice!==null) setMoney('base_price_view','base_price',+lastMachinePrice);
      }
      recalc();
    });

    selType.addEventListener('change', ()=>{
      setPriceFieldState();
      if((selType.value==='1'||selType.value==='3') && lastMachinePrice!==null){
        setMoney('base_price_view','base_price',+lastMachinePrice);
      }
      recalc();
    });

    // เริ่มต้น: โหลดราคาเครื่องที่เลือกอยู่ เพื่อ “ล็อกช่อง” ให้ถูกโหมด
    if(selMachine.value){ await fetchMachinePrice(selMachine.value); }
    setPriceFieldState();
    recalc();

    // ยืนยันก่อนบันทึก
    document.getElementById('editForm').addEventListener('submit', (e)=>{
      e.preventDefault();
      const mid=document.querySelector('[name="machine_id"]').value;
      const sid=document.querySelector('[name="supplier_id"]').value;
      const typ=document.querySelector('[name="type_value"]').value;
      if(!mid){ Swal.fire({icon:'warning',title:'เลือกรถ',confirmButtonColor:'#fec201'}); return; }
      if(!sid){ Swal.fire({icon:'warning',title:'เลือกผู้ขาย',confirmButtonColor:'#fec201'}); return; }
      if(!typ){ Swal.fire({icon:'warning',title:'เลือกประเภทราคา',confirmButtonColor:'#fec201'}); return; }

      recalc();
      Swal.fire({
        icon:'question',
        title:'ยืนยันบันทึกการแก้ไข?',
        showCancelButton:true,
        confirmButtonText:'บันทึก',
        cancelButtonText:'ยกเลิก',
        reverseButtons:true,
        confirmButtonColor:'#fec201'
      }).then(res=>{ if(res.isConfirmed) e.target.submit(); });
    });
  });
</script>
</body>
</html>
