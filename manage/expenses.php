<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) { header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน')); exit; }
$isAdmin = ((int)($_SESSION['user']['role'] ?? 1) === 2);

$ok  = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function thb($n){ return $n!==null ? '฿'.number_format((float)$n,2) : '-'; }
$catMap = [
  'repair'=>'ซ่อม', 'maintenance'=>'บำรุงรักษา', 'parts'=>'อะไหล่', 'transport'=>'ขนส่ง',
  'registration'=>'จดทะเบียน', 'inspection'=>'ตรวจสภาพ', 'brokerage'=>'ค่านายหน้า',
  'selling'=>'ค่าใช้จ่ายการขาย', 'other'=>'อื่น ๆ'
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

$params = [];
$sql = "SELECT e.*, m.code, b.brand_name, mo.model_name, s.supplier_name
        FROM machine_expenses e
        JOIN machines m  ON m.machine_id = e.machine_id
        JOIN brands b    ON b.brand_id   = m.brand_id
        JOIN models mo   ON mo.model_id  = m.model_id
        LEFT JOIN suppliers s ON s.supplier_id = e.supplier_id
        WHERE 1=1";
if ($q !== '') {
  $sql .= " AND (m.code LIKE :q OR b.brand_name LIKE :q OR mo.model_name LIKE :q OR s.supplier_name LIKE :q OR e.description LIKE :q OR e.remark LIKE :q)";
  $params[':q'] = "%{$q}%";
}
if ($d1 !== '') { $sql .= " AND e.occurred_at >= :d1"; $params[':d1'] = $d1; }
if ($d2 !== '') { $sql .= " AND e.occurred_at <= :d2"; $params[':d2'] = $d2; }
if ($cat !== '') { $sql .= " AND e.category = :cat"; $params[':cat'] = $cat; }
if ($st  > 0)    { $sql .= " AND e.status   = :st";  $params[':st']  = $st; }
if ($cap !== '' && in_array($cap, ['0','1'], true)) { $sql .= " AND e.capitalizable = :cap"; $params[':cap'] = $cap; }
$sql .= " ORDER BY e.occurred_at DESC, e.expense_id DESC";

$stm = $pdo->prepare($sql); $stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);

// load categories list for filter
$cats = array_keys($catMap);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ค่าใช้จ่ายรายคัน — ProInspect Machinery</title>
<link rel="stylesheet" href="assets/style.css?v=19">
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

    <?php if ($ok): ?><div class="alert alert-success"><?=htmlspecialchars($ok)?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif; ?>

    <section class="card">
      <div class="card-head">
        <h3 class="h5">รายการ</h3>
        <div style="display:flex;gap:8px;align-items:center;">
          <form method="get" action="expenses.php" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <input class="input" type="text" name="q" placeholder="รหัสรถ/ยี่ห้อ/รุ่น/ผู้ขาย/คำอธิบาย" value="<?=htmlspecialchars($q)?>">
            <input class="input" type="date" name="d1" value="<?=htmlspecialchars($d1)?>">
            <span>–</span>
            <input class="input" type="date" name="d2" value="<?=htmlspecialchars($d2)?>">
            <select class="select" name="category">
              <option value="">ทุกประเภท</option>
              <?php foreach($cats as $c): ?>
                <option value="<?=$c?>" <?=$cat===$c?'selected':'';?>><?=$catMap[$c]?></option>
              <?php endforeach; ?>
            </select>
            <select class="select" name="status">
              <option value="0">ทุกสถานะ</option>
              <?php foreach([1,2,3,4,9] as $opt): ?>
                <option value="<?=$opt?>" <?=$st===$opt?'selected':'';?>><?=exp_status_label($opt)?></option>
              <?php endforeach; ?>
            </select>
            <select class="select" name="cap">
              <option value="">ทั้งหมด (ทุน+งวด)</option>
              <option value="1" <?=$cap==='1'?'selected':'';?>>เข้าทุน</option>
              <option value="0" <?=$cap==='0'?'selected':'';?>>ค่าใช้จ่ายงวด</option>
            </select>
            <button class="btn btn-outline sm" type="submit">ค้นหา</button>
          </form>
          <?php if ($isAdmin): ?>
            <a class="btn btn-brand sm" href="expense_add.php">เพิ่มค่าใช้จ่าย</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:110px;">วันที่เกิด</th>
              <th>รถ</th>
              <th>ผู้ขาย/ผู้รับจ้าง</th>
              <th style="width:110px;">ประเภท</th>
              <th style="width:100px;">ทุน?</th>
              <th class="tr" style="width:110px;">Qty</th>
              <th class="tr" style="width:130px;">ราคา/หน่วย</th>
              <th class="tr" style="width:130px;">ค่านายหน้า</th>
              <th class="tr" style="width:140px;">รวม</th>
              <th style="width:110px;">สถานะ</th>
              <th class="tr" style="width:170px;">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): 
              $total = round((float)$r['qty'] * (float)$r['unit_cost'] + (float)$r['commission_amt'], 2);
              $id = (int)$r['expense_id'];
            ?>
              <tr>
                <td><?=htmlspecialchars($r['occurred_at'])?></td>
                <td>
                  <div><strong><?=htmlspecialchars($r['code'])?></strong></div>
                  <div class="muted"><?=htmlspecialchars($r['brand_name'].' '.$r['model_name'])?></div>
                  <?php if (!empty($r['description'])): ?>
                    <div class="muted">— <?=htmlspecialchars($r['description'])?></div>
                  <?php endif; ?>
                </td>
                <td><?=htmlspecialchars($r['supplier_name'] ?? '-')?></td>
                <td><?=htmlspecialchars($catMap[$r['category']] ?? $r['category'])?></td>
                <td><?= $r['capitalizable'] ? 'เข้าทุน' : 'งวด' ?></td>
                <td class="tr"><?=number_format((float)$r['qty'],2)?></td>
                <td class="tr"><?=thb($r['unit_cost'])?></td>
                <td class="tr"><?=thb($r['commission_amt'])?></td>
                <td class="tr"><strong><?=thb($total)?></strong></td>
                <td><?=exp_status_label((int)$r['status'])?></td>
                <td class="tr">
                  <a class="link" href="expense_edit.php?id=<?=$id?>">แก้ไข</a>
                  <?php if ($isAdmin): ?>
                    ·
                    <form action="expense_delete.php" method="post" class="js-del" data-info="<?=htmlspecialchars($r['code'].' / '.$catMap[$r['category']] ?? $r['category'])?>" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?=$csrf?>">
                      <input type="hidden" name="id"   value="<?=$id?>">
                      <input type="hidden" name="return" value="<?=htmlspecialchars($_SERVER['REQUEST_URI'])?>">
                      <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="11" class="muted">ไม่พบข้อมูล</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', ()=>{
  document.querySelectorAll('.js-del').forEach(f=>{
    f.addEventListener('submit', (e)=>{
      e.preventDefault();
      const info = f.dataset.info || 'รายการนี้';
      Swal.fire({
        icon:'warning', title:'ยืนยันการลบ?', text:info,
        showCancelButton:true, confirmButtonText:'ลบ', cancelButtonText:'ยกเลิก',
        reverseButtons:true, confirmButtonColor:'#fec201'
      }).then(res=>{ if(res.isConfirmed) f.submit(); });
    });
  });
  <?php if ($ok): ?>Swal.fire({icon:'success',title:'สำเร็จ',text:'<?=htmlspecialchars($ok,ENT_QUOTES)?>',confirmButtonColor:'#fec201'});<?php endif; ?>
  <?php if ($err): ?>Swal.fire({icon:'error',title:'ไม่สำเร็จ',text:'<?=htmlspecialchars($err,ENT_QUOTES)?>',confirmButtonColor:'#fec201'});<?php endif; ?>
});
</script>
</body>
</html>
