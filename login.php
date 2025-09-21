<?php
require __DIR__ . '/config.php';

$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
  header('Location: index.php?error=' . urlencode('กรอกอีเมลและรหัสผ่าน')); exit;
}

$stmt = $pdo->prepare('SELECT user_id,f_name,l_name,email,password,urole FROM users WHERE email=? LIMIT 1');
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password'])) {
  header('Location: index.php?error=' . urlencode('อีเมลหรือรหัสผ่านไม่ถูกต้อง')); exit;
}

session_regenerate_id(true);
$_SESSION['user'] = [
  'id'    => $user['user_id'],
  'name'  => $user['f_name'].' '.$user['l_name'],
  'email' => $user['email'],
  'role'  => (int)$user['urole'],  
];

header('Location: dashboard.php'); exit;
