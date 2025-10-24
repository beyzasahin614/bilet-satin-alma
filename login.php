<?php
declare(strict_types=1);
session_start();



$db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON;');


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email && $password) {
        $stmt = $db->prepare("SELECT id, full_name, email, role, company_id, password, status FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] !== 'active') {
                echo "<p style='color:red;text-align:center;'>❌ Hesabınız aktif değil. Lütfen admin onayını bekleyin.</p>";
                exit;
            }

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] !== 'active') {
                    echo "<p style='color:red;text-align:center;'>❌ Hesabınız aktif değil. Lütfen admin onayını bekleyin.</p>";
                    exit;
                }
            
                
                session_regenerate_id(true);
            
                
                $_SESSION['user'] = [
                    'id' => $user['id'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'company_id' => $user['company_id'] ?? null
                ];
            
                switch ($user['role']) {
                    case 'admin':
                        header("Location: admin_panel.php");
                        break;
                    case 'company':
                        header("Location: company_panel.php");
                        break;
                    case 'user':
                    default:
                        header("Location: user_panel.php");
                        break;
                }
                exit;
            }
            
            exit;
        } else {
            echo "<p style='color:red;text-align:center;'>❌ Geçersiz e-posta veya şifre.</p>";
        }
    } else {
        echo "<p style='color:red;text-align:center;'>⚠️ Lütfen e-posta ve şifre girin.</p>";
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Giriş Yap</title>
<style>
body {
    font-family: "Segoe UI", Tahoma, sans-serif;
    background: #f0f0f0;
    margin: 0;
    display: flex;
    justify-content: center;
    align-items: center;
    height: 100vh;
}

form {
    background: white;
    padding: 30px 25px;
    border-radius: 12px;
    width: 320px;
    box-shadow: 0 8px 16px rgba(0,0,0,0.15);
    text-align: center;
}

h2 {
    margin-bottom: 15px;
    color: #333;
}

input {
    width: 90%;
    padding: 10px;
    margin: 8px 0;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 15px;
}

button {
    width: 95%;
    padding: 10px;
    background-color: #2196f3;
    border: none;
    color: white;
    border-radius: 6px;
    font-size: 16px;
    cursor: pointer;
    transition: background 0.3s ease;
    margin-top: 10px;
}

button:hover {
    background-color:rgb(103, 171, 240);
}

p {
    margin-top: 15px;
}

a {
    text-decoration: none;
    color: #2196f3;
}

a:hover {
    text-decoration: underline;
}
</style>
</head>
<body>
<form method="post">
    <h2>🔐 Giriş Yap</h2>
    <input type="email" name="email" placeholder="E-posta" required>
    <input type="password" name="password" placeholder="Şifre" required>
    <button type="submit">Giriş Yap</button>
    <p>Hesabın yok mu? <a href="register.php">Kayıt ol</a></p>
</form>
</body>
</html>
