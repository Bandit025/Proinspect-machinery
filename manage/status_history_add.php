<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user']) || (int)($_SESSION['user']['role'] ?? 1)!==2) {
  header('Location: index.php?error=' . urlencode('จำกัดสิทธิ์เฉพาะผู้ดูแลระบบ')); exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$machines = $pdo->query("SELECT m.machine_id, m.code, b.brand_name, mo.model_name
                         FROM machines m
                         JOIN brands b ON b.brand_id=m.brand_id
                         JOIN models mo ON mo.model_id=m.model_id
                         ORDER BY m.machine_id DESC")->fetchAll(PDO::FETCH_ASSOC);

$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) $errors[]='CSRF token ไม่ถูกต้อง';

  $machine_id = (int)($_POST['machine_id'] ?? 0);
  $status     = (int)($_POST['status'] ?? 1);
  $note       = trim($_POST['note'] ?? '');
  $dt         = trim($_POST['changed_at'] ?? '');
  $changed_at = $dt ? date('Y-m-d H:i:s', strtotime($dt)) : date('Y-m-d H:i:s');
  $user_changed = current_fullname();

  if ($machine_id<=0) $errors[]='เลือกรถ';
  if ($status<1 || $status>5) $errors[]='เลือกสถานะให้ถูกต้อง';

  if (!$errors) {
    $ins = $pdo->prepare("INSERT INTO machine_status_history (machine_id,status,changed_at,user_changed,note)
                          VALUES (?,?,?,?,?)");
    $ins->execute([$machine_id,$status,$changed_at,$user_changed,$note]);
    header('Location: status_history.php?ok=' . urlencode('เพิ่มประวัติเรียบร้อย')); exit;
  }
}
$pref_mid = (int)($_GET['machine_id'] ?? 0);
?>
<!doctype html><html lang="th"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>เพิ่มประวัติสถานะรถ — ProInspect Machinery</title>
<link rel="stylesheet" href="assets/style.css?v=15">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head><body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <main class="content">
    <div class="page-head">
      <h2 class="page-title">เพิ่มประวัติสถานะ</h2>
      <div class="page-sub">บันทึกการเปลี่ยนสถานะรถ</div>
    </div>

    <?php if ($errors): ?><div class="alert alert-danger"><ul style="margin:0 0 0 18px;"><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="card">
      <form method="post" id="addForm" novalidate>
        <input type="hidden" name="csrf" value="<?=$csrf?>">

        <label>รถ</label>
        <select class="select" name="machine_id" required>
          <option value="">— เลือกรถ —</option>
          <?php foreach($machines as $m): ?>
            <option value="<?=$m['machine_id']?>" <?= (($pref_mid && $pref_mid==$m['machine_id']) || (($_POST['machine_id'] ?? '')==$m['machine_id']))?'selected':''; ?>>
              <?=htmlspecialchars($m['code'].' — '.$m['brand_name'].' '.$m['model_name'])?>
            </option>
          <?php endforeach; ?>
        </select>

        <div class="row" style="margin-top:10px;">
          <div>
            <label>สถานะ</label>
            <select class="select" name="status" required>
              <?php for($i=1;$i<=5;$i++): ?>
                <option value="<?=$i?>" <?= (int)($_POST['status'] ?? 1)===$i?'selected':''; ?>><?=status_label_th($i)?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div>
            <label>วันเวลาเปลี่ยน</label>
            <?php $def = isset($_POST['changed_at']) ? $_POST['changed_at'] : date('Y-m-d\TH:i'); ?>
            <input class="input" type="datetime-local" name="changed_at" value="<?=htmlspecialchars($def)?>">
          </div>
        </div>

        <label style="margin-top:10px;">ผู้เปลี่ยน</label>
        <input class="input" type="text" value="<?=htmlspecialchars(current_fullname())?>" readonly>

        <label style="margin-top:10px;">หมายเหตุ</label>
        <input class="input" type="text" name="note" value="<?=htmlspecialchars($_POST['note'] ?? '')?>">

        <div style="margin-top:14px;display:flex;gap:8px;">
          <button class="btn btn-brand" type="submit">บันทึก</button>
          <a class="btn btn-outline" href="status_history.php">ยกเลิก</a>
        </div>
      </form>
    </section>
  </main>
</div>

<script>
document.getElementById('addForm').addEventListener('submit', (e)=>{
  e.preventDefault();
  const mid = (document.querySelector('[name="machine_id"]').value||'');
  if(!mid){ Swal.fire({icon:'warning', title:'เลือกรถ', confirmButtonColor:'#fec201'}); return; }
  Swal.fire({
    icon:'question', title:'ยืนยันการบันทึก?', showCancelButton:true,
    confirmButtonText:'บันทึก', cancelButtonText:'ยกเลิก', reverseButtons:true, confirmButtonColor:'#fec201'
  }).then(res=>{ if(res.isConfirmed) e.target.submit(); });
});
</script>
</body></html>
