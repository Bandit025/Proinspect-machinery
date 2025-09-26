<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) { header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน')); exit; }
$isAdmin = ((int)($_SESSION['user']['role'] ?? 1) === 2);

$ok  = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$q   = trim($_GET['q'] ?? '');
$d1  = trim($_GET['d1'] ?? '');
$d2  = trim($_GET['d2'] ?? '');

$params = [];
$sql = "SELECT a.*, m.code, b.brand_name, mo.model_name, s.supplier_name
        FROM acquisitions a
        JOIN machines  m  ON m.machine_id  = a.machine_id
        JOIN brands    b  ON b.brand_id    = m.brand_id
        JOIN models    mo ON mo.model_id   = m.model_id
        JOIN suppliers s  ON s.supplier_id = a.supplier_id
        WHERE 1=1";
if ($q !== '') {
  $sql .= " AND (a.doc_no LIKE :q OR m.code LIKE :q OR b.brand_name LIKE :q OR mo.model_name LIKE :q OR s.supplier_name LIKE :q)";
  $params[':q'] = "%{$q}%";
}
if ($d1 !== '') { $sql .= " AND a.acquired_at >= :d1"; $params[':d1'] = $d1; }
if ($d2 !== '') { $sql .= " AND a.acquired_at <= :d2"; $params[':d2'] = $d2; }
$sql .= " ORDER BY a.acquired_at DESC, a.acquisition_id DESC";

$stm = $pdo->prepare($sql); $stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);

function thb($n){ return $n!==null ? '฿'.number_format((float)$n,2) : '-'; }
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ซื้อรถเข้า — ProInspect Machinery</title>
<link rel="stylesheet" href="assets/style.css?v=16">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  
  <?php include 'sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>



  <main class="content">
    <div class="page-head">
      <h2 class="page-title">รายการซื้อรถเข้า (Acquisitions)</h2>
      <div class="page-sub">แสดง/ค้นหา/จัดการเอกสารซื้อ</div>
    </div>

    <?php if ($ok): ?><div class="alert alert-success"><?=htmlspecialchars($ok)?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif; ?>

    <section class="card">
      <div class="card-head">
        <h3 class="h5">รายการ</h3>
        <div style="display:flex;gap:8px;align-items:center;">
          <form method="get" action="acquisitions.php" style="display:flex;gap:8px;align-items:center;">
            <input class="input" type="text" name="q" placeholder="เลขเอกสาร/รหัสรถ/ยี่ห้อ/รุ่น/ผู้ขาย" value="<?=htmlspecialchars($q)?>">
            <input class="input" type="date" name="d1" value="<?=htmlspecialchars($d1)?>">
            <span>–</span>
            <input class="input" type="date" name="d2" value="<?=htmlspecialchars($d2)?>">
            <button class="btn btn-outline sm" type="submit">ค้นหา</button>
          </form>
          <?php if ($isAdmin): ?>
            <a class="btn btn-brand sm" href="acquisition_add.php">เพิ่มเอกสาร</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:110px;">วันที่ซื้อ</th>
              <th>เลขเอกสาร</th>
              <th>รถ</th>
              <th>ผู้ขาย</th>
              <th class="tr" style="width:120px;">ราคาก่อน VAT</th>
              <th class="tr" style="width:110px;">VAT</th>
              <th class="tr" style="width:130px;">รวม</th>
              <th class="tr" style="width:160px;">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): $id=(int)$r['acquisition_id']; ?>
              <tr>
                <td><?=htmlspecialchars($r['acquired_at'])?></td>
                <td><?=htmlspecialchars($r['doc_no'] ?: '-')?></td>
                <td>
                  <div><strong><?=htmlspecialchars($r['code'])?></strong></div>
                  <div class="muted"><?=htmlspecialchars($r['brand_name'].' '.$r['model_name'])?></div>
                </td>
                <td><?=htmlspecialchars($r['supplier_name'])?></td>
                <td class="tr"><?=thb($r['base_price'])?></td>
                <td class="tr"><?=thb($r['vat_amount'])?> <span class="muted">(<?=number_format($r['vat_rate_pct'],2)?>%)</span></td>
                <td class="tr"><strong><?=thb($r['total_amount'])?></strong></td>
                <td class="tr">
                  <a class="link" href="acquisition_edit.php?id=<?=$id?>">แก้ไข</a>
                  <?php if ($isAdmin): ?>
                    ·
                    <form action="acquisition_delete.php" method="post" class="js-del" data-info="<?=htmlspecialchars(($r['doc_no']?:'-').' / '.$r['code'])?>" style="display:inline;">
                      <input type="hidden" name="csrf" value="<?=$csrf?>">
                      <input type="hidden" name="id"   value="<?=$id?>">
                      <input type="hidden" name="return" value="<?=htmlspecialchars($_SERVER['REQUEST_URI'])?>">
                      <button type="submit" class="link" style="border:none;background:none;color:#a40000;">ลบ</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="8" class="muted">ไม่พบข้อมูล</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('.js-del').forEach(f=>{
    f.addEventListener('submit',(e)=>{
      e.preventDefault();
      const info=f.dataset.info||'รายการนี้';
      Swal.fire({icon:'warning',title:'ยืนยันการลบ?',text:info,showCancelButton:true,confirmButtonText:'ลบ',cancelButtonText:'ยกเลิก',reverseButtons:true,confirmButtonColor:'#fec201'}).then(res=>{if(res.isConfirmed) f.submit();});
    });
  });
  <?php if ($ok): ?>Swal.fire({icon:'success',title:'สำเร็จ',text:'<?=htmlspecialchars($ok,ENT_QUOTES)?>',confirmButtonColor:'#fec201'});<?php endif; ?>
  <?php if ($err): ?>Swal.fire({icon:'error',title:'ไม่สำเร็จ',text:'<?=htmlspecialchars($err,ENT_QUOTES)?>',confirmButtonColor:'#fec201'});<?php endif; ?>
});
</script>
</body>
</html>
