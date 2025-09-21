<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$user = $_SESSION['user'] ?? null;
$roleName = ($user && ($user['role'] === 1 || $user['role'] === '1' || $user['role'] === 'admin')) ? 'admin' : 'user';
?>
<nav class="navbar">
  <!-- Hamburger (ปุ่มเดียว ใช้ .nav-icon ที่มีแอนิเมชัน) -->
  <button class="nav-icon" id="btnSidebar" aria-label="เปิด/ปิดเมนู" aria-expanded="false">
  <span class="hamburger"></span>
  <span class="hamburger"></span>
  <span class="hamburger"></span>
</button>

  <!-- แบรนด์ -->
  <div class="brand">
    <span class="brand-badge sm">PI</span>
    <span class="brand-title">ProInspect Machinery</span>
  </div>

  <!-- ส่วนขวา -->
  <div class="nav-right">
    <?php if ($user): ?>
      <a class="btn btn-outline sm" href="logout.php">ออกจากระบบ</a>
    <?php else: ?>
      <a class="btn btn-brand sm" href="index.php">เข้าสู่ระบบ</a>
    <?php endif; ?>
  </div>
</nav>
