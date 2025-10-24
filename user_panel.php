<?php
declare(strict_types=1);
session_start();

if (
    empty($_SESSION['user']) ||
    !is_array($_SESSION['user']) ||
    ($_SESSION['user']['role'] ?? '') !== 'user'
) {
    header('Location: login.php');
    exit;
}


$db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON;');

function e(string $str): string { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }

$userId = $_SESSION['user']['id'];


$stmt = $db->prepare("SELECT balance, full_name, email FROM users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$balance = (int)$user['balance'];
$fullName = $user['full_name'];
$email = $user['email'];


$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_balance'])) {
    $amount = (int)($_POST['amount'] ?? 0);
    if ($amount > 0) {
        $stmt = $db->prepare("UPDATE users SET balance = balance + :a WHERE id = :id");
        $stmt->execute([':a' => $amount, ':id' => $userId]);
        $balance += $amount;
        $message = "✅ {$amount}₺ bakiyenize eklendi.";
    } else {
        $message = "⚠️ Geçerli bir tutar giriniz.";
    }
}


$stmt = $db->prepare("
    SELECT tk.*, t.departure_city, t.destination_city, t.departure_time, t.price
    FROM tickets tk
    JOIN trips t ON t.id = tk.trip_id
    WHERE tk.user_id = :uid AND tk.status = 'active'
    ORDER BY t.departure_time DESC
");
$stmt->execute([':uid' => $userId]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Kullanıcı Paneli</title>
<style>
body { font-family: Arial, sans-serif; background: #f4f4f9; margin: 0; padding: 0; }
.container { width: 90%; max-width: 900px; margin: 20px auto; background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
header { display: flex; justify-content: space-between; align-items: center; }
header h2 { margin: 0; }
table { width: 100%; border-collapse: collapse; margin-top: 20px; }
th, td { padding: 10px; border-bottom: 1px solid #ccc; text-align: center; }
.balance { text-align: center; margin-top: 15px; font-weight: bold; }
form { text-align: center; margin-top: 10px; }
button { padding: 8px 16px; border: none; background: #2196f3; color: #fff; border-radius: 6px; cursor: pointer; }
button:hover { background: #1976d2; }
.msg { text-align: center; font-weight: bold; color: #444; margin-top: 10px; }
a { color: #1976d2; text-decoration: none; }
a:hover { text-decoration: underline; }
.success-box {
    background: #d4edda;
    color: #155724;
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 10px;
    text-align: center;
    font-weight: bold;
}
</style>
</head>
<body>
<div class="container">
    <header>
        <h2>👤 Hoş geldin, <?= e($fullName) ?></h2>
        <a href="logout.php">🚪 Çıkış Yap</a>
    </header>

    
    <?php if (isset($_GET['success'])): ?>
        <div class="success-box">
            ✅ Bilet başarıyla satın alındı! Koltuk No: <?= e($_GET['seat'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if ($message): ?>
        <p class="msg"><?= e($message) ?></p>
    <?php endif; ?>

    <p class="balance">💰 Bakiye: <?= e((string)$balance) ?> ₺</p>

    <form method="POST">
        <input type="number" name="amount" min="1" placeholder="Tutar (₺)" required>
        <button type="submit" name="add_balance">Bakiye Ekle</button>
    </form>

    <h3>🎟️ Aktif Biletlerim</h3>
    <?php if (count($tickets) === 0): ?>
        <p style="text-align:center;">Henüz biletiniz yok.</p>
    <?php else: ?>
        <table>
            <tr>
                <th>Sefer</th>
                <th>Tarih</th>
                <th>Fiyat</th>
                <th>İşlem</th>
            </tr>
            <?php foreach ($tickets as $t): ?>
                <tr>
                    <td><?= e($t['departure_city']) ?> → <?= e($t['destination_city']) ?></td>
                    <td><?= e(date('d.m.Y H:i', strtotime($t['departure_time']))) ?></td>
                    <td><?= e((string)$t['total_price']) ?> ₺</td>
                    <td>
                        <a href="cancel_ticket.php?id=<?= e($t['id']) ?>">❌ İptal Et</a> |
                        <a href="ticket_pdf.php?id=<?= e($t['id']) ?>">📄 PDF</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <div style="text-align:center; margin-top:20px;">
        <a href="index.php">🚌 Seferleri Gör / Bilet Satın Al</a>
    </div>
</div>
</body>
</html>
