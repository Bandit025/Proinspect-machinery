<?php
// acquisition_edit.php (แก้ไขใหม่ทั้งไฟล์ พร้อมแก้ dropdown ประเภทราคา, ดึงรายชื่อรถให้รวมคันที่ขายไปแล้วถ้าเป็นคันเดิม, และ sync cashflow แบบไม่บังคับตายตัว)
require __DIR__ . '/config.php';


if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

/* ======================================================= */


$acq_id = $_GET['id'];
if ($acq_id <= 0) {
  header('Location: acquisitions.php?error=' . urlencode('ไม่พบรหัสเอกสารที่จะแก้ไข'));
  exit;
}

// โหลดข้อมูลรายการที่จะแก้ไข
$sqlLoad = "SELECT * FROM acquisitions a JOIN suppliers s ON a.supplier_id = s.supplier_id 
                                        JOIN machines m ON a.machine_id = m.machine_id
                                        JOIN models b ON m.model_id = b.model_id
                                        JOIN brands c ON b.brand_id = c.brand_id
                                            WHERE acquisition_id=? LIMIT 1";
$stm = $pdo->prepare($sqlLoad);
$stm->execute([$acq_id]);
$acq = $stm->fetch(PDO::FETCH_ASSOC);

if (!$acq) {
  header('Location: acquisitions.php?error=' . urlencode('ไม่พบเอกสารที่ต้องการแก้ไข'));
  exit;
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
        <h2 class="page-title">เอกสารเอกสารซื้อรถเข้า</h2>    
      </div>

    

      <section class="card">
          <div class="row" style="align-items:flex-end; gap:16px;">
            <div style="flex:1;">
              <label>เลขเอกสาร </label>
                <h4><?php echo $acq['doc_no']; ?></h4>
            </div>
            <div>
              <label>วันที่ซื้อเข้า</label>
              <h4><?php echo date('d-m-Y', strtotime($acq['acquired_at'])); ?></h4>
            </div>
          </div>
          <hr>
          <div class="row" style="align-items:flex-end; gap:16px;">
            <div style="flex:1;">
              <label>ข้อมูลผู้ขาย</label>
                <h4>ชื่อ : <?php echo $acq['supplier_name']; ?></h4>
                <h4>เลขผู้เสียภาษี : <?php echo $acq['tax_id']; ?></h4>
                <h4>ที่อยู่ : <?php echo $acq['address']; ?></h4>
                <h4>เบอร์โทร : <?php echo $acq['phone']; ?></h4>
                <h4>อีเมล : <?php echo $acq['email']; ?></h4>
            </div>   
          </div>
          <hr>
          <label>ข้อมูลรถ</label>
            <h4>เลขทะเบียน : <?php echo $acq['code']; ?></h4>
            <h4>ยี่ห้อ : <?php echo $acq['brand_name']; ?></h4>
            <h4>รุ่น : <?php echo $acq['model_name']; ?></h4>
          <label>ผู้ขาย</label>
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
      </section>
    </main>
  </div>
    <script src="assets/script.js?v=3"></script>
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
