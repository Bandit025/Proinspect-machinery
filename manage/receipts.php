<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) { header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน')); exit; }
$isAdmin = ((int)($_SESSION['user']['role'] ?? 1) === 2);

$ok  = $_GET['ok'] ?? '';
$err = $_GET['error'] ?? '';

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

function thb($n){ return $n!==null ? '฿'.number_format((float)$n,2) : '-'; }

$q  = trim($_GET['q'] ?? '');
$d1 = trim($_GET['d1'] ?? '');
$d2 = trim($_GET['d2'] ?? '');

$params = [];
$sql = "SELECT r.*, s.doc_no, s.sold_at, s.sale_id,
               m.code, b.brand_name, mo.model_name,
               c.customer_name
        FROM receipts_in r
        JOIN sales     s  ON s.sale_id = r.sale_id
        JOIN machines  m  ON m.machine_id = s.machine_id
        JOIN brands    b  ON b.brand_id = m.brand_id
        JOIN models    mo ON mo.model_id = m.model_id
        JOIN customers c  ON c.customer_id = s.customer_id
        WHERE 1=1";
if ($q !== '') {
  $sql .= " AND (s.doc_no LIKE :q OR m.code LIKE :q OR b.brand_name LIKE :q OR mo.model_name LIKE :q OR c.customer_name LIKE :q OR r.notes LIKE :q)";
  $params[':q'] = "%{$q}%";
}
if ($d1 !== '') { $sql .= " AND r.received_at >= :d1"; $params[':d1'] = $d1.' 00:00:00'; }
if ($d2 !== '') { $sql .= " AND r.received_at <= :d2"; $params[':d2'] = $d2.' 23:59:59'; }
$sql .= " ORDER BY r.received_at DESC, r.receipt_id DESC";

$stm = $pdo->prepare($sql); $stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ใบรับเงินลูกค้า — ProInspect Machinery</title>
<link rel="stylesheet" href="assets/style.css?v=18">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>.thumb{width:68px;height:68px;object-fit:cover;border-radius:8px;border:1px solid #eee;background:#f7f7f7;}</style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <main class="content">
    <div class="page-head">
      <h2 class="page-title">ใบรับเงิน (Receipts In)</h2>
      <div class="page-sub">แสดง/ค้นหา/จัดการการรับชำระจากลูกค้า</div>
    </div>

    <?php if ($ok): ?><div class="alert alert-success"><?=htmlspecialchars($ok)?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert alert-danger"><?=htmlspecialchars($err)?></div><?php endif; ?>

    <section class="card">
      <div class="card-head">
        <h3 class="h5">รายการ</h3>
        <div style="display:flex;gap:8px;align-items:center;">
          <form method="get" action="receipts.php" style="display:flex;gap:8px;align-items:center;">
            <input class="input" type="text" name="q" placeholder="เลขเอกสารขาย/รหัสรถ/ยี่ห้อ/รุ่น/ชื่อลูกค้า/หมายเหตุ" value="<?=htmlspecialchars($q)?>">
            <input class="input" type="date" name="d1" value="<?=htmlspecialchars($d1)?>">
            <span>–</span>
            <input class="input" type="date" name="d2" value="<?=htmlspecialchars($d2)?>">
            <button class="btn btn-outline sm" type="submit">ค้นหา</button>
          </form>
          <?php if ($isAdmin): ?>
            <a class="btn btn-brand sm" href="receipt_add.php">เพิ่มใบรับเงิน</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:120px;">วันที่รับเงิน</th>
              <th>การขาย</th>
              <th>ลูกค้า</th>
              <th class="tr" style="width:140px;">จำนวนเงิน</th>
              <th style="width:110px;">วิธีรับ</th>
              <th style="width:80px;">สลิป</th>
              <th>หมายเหตุ</th>
              <th class="tr" style="width:160px;">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($rows as $r): $id=(int)$r['receipt_id']; ?>
              <tr>
                <td><?=htmlspecialchars($r['received_at'])?></td>
                <td>
                  <div><strong><?=htmlspecialchars($r['doc_no'] ?: '-')?> / <?=htmlspecialchars($r['code'])?></strong></div>
                  <div class="muted"><?=htmlspecialchars($r['brand_name'].' '.$r['model_name'])?></div>
                </td>
                <td><?=htmlspecialchars($r['customer_name'])?></td>
                <td class="tr"><?=thb($r['amount'])?></td>
                <td><?=htmlspecialchars($r['method'])?></td>
                <td>
                  <?php if (!empty($r['ref_no']) && file_exists(__DIR__ . '/' . $r['ref_no'])): ?>
                    <a href="<?=htmlspecialchars($r['ref_no'])?>" target="_blank" title="ดูสลิป">
                      <img class="thumb" src="<?=htmlspecialchars($r['ref_no'])?>" alt="">
                    </a>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td><?=htmlspecialchars($r['notes'] ?? '-')?></td>
                <td class="tr">
                  <a class="link" href="receipt_edit.php?id=<?=$id?>">แก้ไข</a>
                  <?php if ($isAdmin): ?>
                    ·
                    <form action="receipt_delete.php" method="post" class="js-del" data-info="<?=htmlspecialchars(($r['doc_no']?:'-').' / '.$r['code'].' / '.number_format($r['amount'],2))?>" style="display:inline;">
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
      const info = f.dataset.info || 'รายการนี้';
      Swal.fire({icon:'warning',title:'ยืนยันการลบ?',text:info,showCancelButton:true,confirmButtonText:'ลบ',cancelButtonText:'ยกเลิก',reverseButtons:true,confirmButtonColor:'#fec201'})
        .then(res=>{ if(res.isConfirmed) f.submit(); });
    });
  });
  <?php if ($ok): ?>Swal.fire({icon:'success',title:'สำเร็จ',text:'<?=htmlspecialchars($ok,ENT_QUOTES)?>',confirmButtonColor:'#fec201'});<?php endif; ?>
  <?php if ($err): ?>Swal.fire({icon:'error',title:'ไม่สำเร็จ',text:'<?=htmlspecialchars($err,ENT_QUOTES)?>',confirmButtonColor:'#fec201'});<?php endif; ?>
});
</script>
</body>
</html>
