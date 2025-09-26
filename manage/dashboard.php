<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['user'])) {
  header('Location: index.php?error=' . urlencode('กรุณาเข้าสู่ระบบก่อน')); exit;
}
$user = $_SESSION['user'];

// แปลง role เป็นชื่อสิทธิ์ (1=ผู้ใช้งาน, 2=ผู้ดูแลระบบ, 3=ผู้เยี่ยมชม)
$roleVal  = (int)($user['role'] ?? 1);
$roleName = [
  1 => 'ผู้ใช้งาน',
  2 => 'ผู้ดูแลระบบ',
  3 => 'ผู้เยี่ยมชม',
][$roleVal] ?? 'ผู้ใช้งาน';
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>แดชบอร์ด — ProInspect Machinery</title>
  <link rel="stylesheet" href="assets/style.css?v=4">
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>

<div class="layout">
  <?php include 'sidebar.php'; ?>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>


  <!-- หมายเหตุ: เด็ก (children) ระดับแรกของ .content จะถูกจัดกึ่งกลางอัตโนมัติจาก CSS ใหม่ -->
  <main class="content">
    <!-- Page Head -->
    <div class="page-head">
      <h2 class="page-title">แดชบอร์ด</h2>
      <div class="page-sub">
        ยินดีต้อนรับ <?= htmlspecialchars($user['name']) ?> (สิทธิ์: <?= $roleName ?>)
      </div>
    </div>

    <!-- สถิติสรุป (ตัวเลขตัวอย่าง) -->
    <section class="card" style="margin-top:12px;">
      <div class="card-head">
        <h3 class="h5">สรุปสถานะโดยรวม</h3>
        <a class="btn btn-brand sm" href="machines.php">ดูทะเบียนรถ</a>
      </div>

      <!-- ใช้ grid แบบเรียบง่ายโดยไม่พึ่ง class อื่น เพื่อให้ทำงานกับ CSS ปัจจุบัน -->
      <div style="display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:14px;">
        <div class="card" style="margin:0;">
          <div class="muted">คันพร้อมขาย</div>
          <div style="font-size:1.8rem; font-weight:800; margin:4px 0;">8</div>
          <div class="muted" style="font-size:.9rem;">อัปเดตล่าสุดวันนี้</div>
        </div>
        <div class="card" style="margin:0;">
          <div class="muted">กำลังซ่อมบำรุง</div>
          <div style="font-size:1.8rem; font-weight:800; margin:4px 0;">3</div>
          <div class="muted" style="font-size:.9rem;">มีงานค้าง 2 รายการ</div>
        </div>
        <div class="card" style="margin:0;">
          <div class="muted">ขายเดือนนี้</div>
          <div style="font-size:1.8rem; font-weight:800; margin:4px 0;">2</div>
          <div class="muted" style="font-size:.9rem;">ยอดสุทธิ ~ ฿5.2M</div>
        </div>
      </div>
    </section>

    <!-- ตารางตัวอย่าง -->
    <section class="card" style="margin-top:20px;">
      <div class="card-head">
        <h3 class="h5">สต๊อกล่าสุด</h3>
        <a class="btn btn-brand sm" href="#">เพิ่มคันใหม่</a>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>รหัส</th>
              <th>ยี่ห้อ / รุ่น</th>
              <th>ปี</th>
              <th>สถานะ</th>
              <th class="tr">ราคาตั้ง</th>
              <th class="tr">ต้นทุน</th>
              <th class="tr"></th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>BKH-0008</td>
              <td>Komatsu PC200-8</td>
              <td>2016</td>
              <td><span class="badge success">available</span></td>
              <td class="tr">฿2,850,000</td>
              <td class="tr">฿2,430,000</td>
              <td class="tr"><a class="link" href="#">ดูรายละเอียด</a></td>
            </tr>
            <tr>
              <td>BKH-0007</td>
              <td>Caterpillar 320D</td>
              <td>2014</td>
              <td><span class="badge warn">maintenance</span></td>
              <td class="tr">฿2,650,000</td>
              <td class="tr">฿2,210,000</td>
              <td class="tr"><a class="link" href="#">ดูรายละเอียด</a></td>
            </tr>
            <tr>
              <td>BKH-0006</td>
              <td>Hitachi ZX200-5G</td>
              <td>2017</td>
              <td><span class="badge gray">reserved</span></td>
              <td class="tr">฿3,100,000</td>
              <td class="tr">฿2,780,000</td>
              <td class="tr"><a class="link" href="#">ดูรายละเอียด</a></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

  </main>
</div>

<script src="assets/script.js"></script>
</body>
</html>
