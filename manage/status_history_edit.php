<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1)!==2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id<=0) { header('Location: status_history.php?error=' . urlencode('ไม่พบรายการ')); exit; }

$stm = $pdo->prepare("SELECT h.*, m.code, b.brand_name, mo.model_name
                      FROM machine_status_history h
                      JOIN machines m ON m.machine_id=h.machine_id
                      JOIN brands b ON b.brand_id=m.brand_id
                      JOIN models mo ON mo.model_id=m.model_id
                      WHERE h.history_id=? LIMIT 1");
$stm->execute([$id]); $row = $stm->fetch(PDO::FETCH_ASSOC);
if(!$row){ header('Location: status_history.php?error=' . urlencode('ไม่พบรายการ')); exit; }

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[]='CSRF token ไม่ถูกต้อง';
  $status = (int)($_POST['status'] ?? 1);
  $note   = trim($_POST['note'] ?? '');
  $dt     = trim($_POST['changed_at'] ?? '');
  $changed_at = $dt ? date('Y-m-d H:i:s', strtotime($dt)) : $row['changed_at'];

  if ($status<1 || $status>5) $errors[]='เลือกสถานะให้ถูกต้อง';

  if (!$errors) {
    $upd = $pdo->prepare("UPDATE machine_status_history SET status=?, changed_at=?, note=? WHERE history_id=?");
    $upd->execute([$status,$changed_at,$note,$id]);
    header('Location: status_history.php?ok=' . urlencode('แก้ไขประวัติเรียบร้อย')); exit;
  }
}
?>
<!doctype html><html lang="th"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>แก้ไขประวัติสถานะ — ProInspect Machinery</title>
<link rel="stylesheet" href="assets/style.css?v=15">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head><body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <main class="content">
    <div class="page-head">
      <h2 class="page-title">แก้ไขประวัติ #<?=$row['history_id']?></h2>
      <div class="page-sub"><?=htmlspecialchars($row['code'].' — '.$row['brand_name'].' '.$row['model_name'])?></div>
    </div>

    <?php if ($errors): ?><div class="alert alert-danger"><ul style="margin:0 0 0 18px;"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card">
      <form method="post" id="editForm" novalidate>
        <input type="hidden" name="csrf" value="<?=$csrf?>">
        <input type="hidden" name="id"   value="<?=$row['history_id']?>">

        <label>รถ</label>
        <input class="input" type="text" value="<?=htmlspecialchars($row['code'].' — '.$row['brand_name'].' '.$row['model_name'])?>" readonly>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>สถานะ</label>
            <select class="select" name="status">
              <?php for($i=1;$i<=5;$i++): ?>
                <option value="<?=$i?>" <?= ((int)($_POST['status'] ?? $row['status'])===$i)?'selected':''; ?>><?=status_label_th($i)?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div>
            <label>วันเวลาเปลี่ยน</label>
            <?php
              $dt = $_POST['changed_at'] ?? str_replace(' ', 'T', substr($row['changed_at'],0,16));
            ?>
            <input class="input" type="datetime-local" name="changed_at" value="<?=htmlspecialchars($dt)?>">
          </div>
        </div>

        <label style="margin-top:10px;">ผู้เปลี่ยน (เมื่อสร้าง)</label>
        <input class="input" type="text" value="<?=htmlspecialchars($row['user_changed'])?>" readonly>

        <label style="margin-top:10px;">หมายเหตุ</label>
        <input class="input" type="text" name="note" value="<?=htmlspecialchars($_POST['note'] ?? $row['note'])?>">

        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึก</button>
          <a class="btn btn-outline" href="status_history.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script>
document.getElementById('editForm').addEventListener('submit',(e)=>{
  e.preventDefault();
  Swal.fire({
    icon:'question', title:'ยืนยันการบันทึกการแก้ไข?',
    showCancelButton:true, confirmButtonText:'บันทึก', cancelButtonText:'ยกเลิก',
    reverseButtons:true, confirmButtonColor:'#fec201'
  }).then(res=>{ if(res.isConfirmed) e.target.submit(); });
});
</script>
</body></html>
