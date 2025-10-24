<?php
declare(strict_types=1);
session_start();


$db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON;');


function e($str) { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf_token'];
}
function csrf_tag() { return '<input type="hidden" name="csrf_token" value="'.e(csrf_token()).'">'; }

$error = '';
$success = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('CSRF doğrulaması başarısız!');
    }

    $role = $_POST['role'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$full_name || !$email || !$password) {
        $error = 'Lütfen tüm alanları doldurun.';
    } elseif (strlen($password) < 6) {
        $error = 'Şifre en az 6 karakter olmalı.';
    } else {
        
        $check = $db->prepare('SELECT id FROM users WHERE email = :email');
        $check->execute([':email' => $email]);

        if ($check->fetch()) {
            $error = 'Bu e-posta adresiyle zaten bir hesap var.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $status = ($role === 'company') ? 'pending' : 'active';
            $id = bin2hex(random_bytes(16)); // UUID üretimi

            
            $stmt = $db->prepare('
                INSERT INTO users (id, full_name, email, password, role, status)
                VALUES (:id, :full_name, :email, :password, :role, :status)
            ');
            $stmt->execute([
                ':id' => $id,
                ':full_name' => $full_name,
                ':email' => $email,
                ':password' => $hashed,
                ':role' => $role,
                ':status' => $status
            ]);

            $success = ($role === 'company')
                ? '🏢 Firma kaydınız alındı. Admin onayından sonra giriş yapabilirsiniz.'
                : '👤 Kayıt başarılı! Şimdi giriş yapabilirsiniz.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Kayıt Ol</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <h3 class="text-center mb-4">📝 Kayıt Ol</h3>

    <?php if ($error): ?>
        <div class="alert alert-danger text-center"><?= e($error) ?></div>
    <?php elseif ($success): ?>
        <div class="alert alert-success text-center"><?= e($success) ?></div>
    <?php endif; ?>

    <div class="row">
        
        <div class="col-md-6">
            <div class="card p-4 shadow-sm">
                <h5 class="text-center mb-3">👤 Kullanıcı Kaydı</h5>
                <form method="post">
                    <?= csrf_tag() ?>
                    <input type="hidden" name="role" value="user">
                    <div class="mb-3">
                        <label class="form-label">Ad Soyad</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-posta</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Şifre</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Kayıt Ol</button>
                </form>
            </div>
        </div>

        
        <div class="col-md-6">
            <div class="card p-4 shadow-sm">
                <h5 class="text-center mb-3">🏢 Firma Kaydı</h5>
                <form method="post">
                    <?= csrf_tag() ?>
                    <input type="hidden" name="role" value="company">
                    <div class="mb-3">
                        <label class="form-label">Firma Adı</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">E-posta</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Şifre</label>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-outline-primary w-100">Kayıt Ol</button>
                </form>
            </div>
        </div>
    </div>

    <div class="text-center mt-4">
        <a href="login.php" class="text-secondary">← Giriş sayfasına dön</a>
    </div>
</div>
</body>
</html>
