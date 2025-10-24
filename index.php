<?php
declare(strict_types=1);
session_start();

$db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON;');

function e(string $str): string { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }

$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $from = trim($_POST['departure_city'] ?? '');
    $to = trim($_POST['destination_city'] ?? '');
    if ($from && $to) {
        $stmt = $db->prepare("
            SELECT t.*, c.name AS company_name
            FROM trips t
            JOIN bus_companies c ON t.company_id = c.id
            WHERE t.departure_city LIKE :from AND t.destination_city LIKE :to
            ORDER BY datetime(t.departure_time)
        ");
        $stmt->execute([':from' => "%$from%", ':to' => "%$to%"]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (empty($results)) {
    $stmt = $db->prepare("
        SELECT t.*, c.name AS company_name
        FROM trips t
        JOIN bus_companies c ON t.company_id = c.id
        ORDER BY datetime(t.departure_time)
    ");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Ana Sayfa | Sefer Listesi</title>
<style>
body {
    font-family: Arial, sans-serif;
    background:#f5f5f5;
    margin:0;
    padding:0;
}
header {
    background:#2196f3;
    color:white;
    padding:15px 25px;
    display:flex;
    justify-content:space-between;
    align-items:center;
}
header h2 {
    margin:0;
    display:flex;
    align-items:center;
    gap:8px;
}
header .auth-buttons a, header .auth-buttons span {
    background:white;
    color:#2196f3;
    text-decoration:none;
    padding:6px 12px;
    margin-left:8px;
    border-radius:6px;
    font-weight:bold;
    transition:background 0.3s;
}
header .auth-buttons a:hover {
    background:#e3f2fd;
}
.container {
    width: 90%;
    margin: 40px auto;
    background:white;
    padding:30px;
    border-radius:10px;
    text-align:center;
}
form {
    display:inline-block;
}
input, button {
    padding:8px;
    margin:5px;
}
table {
    width:100%;
    border-collapse:collapse;
    margin-top:20px;
}
th, td {
    border:1px solid #ddd;
    padding:10px;
    text-align:center;
}
th {
    background:#2196f3;
    color:white;
}
button.buy-btn {
    background:#2196f3;
    color:white;
    border:none;
    padding:6px 12px;
    border-radius:5px;
    cursor:pointer;
}
button.buy-btn:hover {
    background:#1565c0;
}
#bus-container {
    margin-top:30px;
}
</style>
</head>
<body>
<header>
    <h2>🚌 Sefer Ara</h2>
    <div class="auth-buttons">
        <?php if (isset($_SESSION['user'])): ?>
            <?php
                $role = $_SESSION['user']['role'] ?? '';
                $panel = match ($role) {
                    'admin' => 'admin_panel.php',
                    'company' => 'company_panel.php',
                    'user' => 'user_panel.php',
                    default => '#'
                };
            ?>
            <a href="<?= e($panel) ?>">
                👋 <?= e($_SESSION['user']['full_name'] ?? $_SESSION['user']['email'] ?? 'Kullanıcı') ?>
            </a>
            <a href="logout.php">Çıkış Yap</a>
        <?php else: ?>
            <a href="login.php">Giriş Yap</a>
            <a href="register.php">Kayıt Ol</a>
        <?php endif; ?>
    </div>
</header>

<div class="container">
    <form method="POST">
        <input type="text" name="departure_city" placeholder="Kalkış Şehri" required>
        <input type="text" name="destination_city" placeholder="Varış Şehri" required>
        <button type="submit">Ara</button>
    </form>

    <?php if ($results): ?>
    <table>
        <tr>
            <th>Firma</th>
            <th>Kalkış</th>
            <th>Varış</th>
            <th>Kalkış Saati</th>
            <th>Varış Saati</th>
            <th>Fiyat</th>
            <th>Kapasite</th>
            <th>İşlem</th>
        </tr>
        <?php foreach ($results as $r): ?>
        <tr>
            <td><?= e($r['company_name']) ?></td>
            <td><?= e($r['departure_city']) ?></td>
            <td><?= e($r['destination_city']) ?></td>
            <td><?= e(date('d.m.Y H:i', strtotime($r['departure_time']))) ?></td>
            <td><?= e(date('d.m.Y H:i', strtotime($r['arrival_time']))) ?></td>
            <td><?= (int)$r['price'] ?> ₺</td>
            <td><?= (int)$r['capacity'] ?></td>
            <td><button class="buy-btn" data-trip="<?= e($r['id']) ?>">Detay</button></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php else: ?>
        <p style="text-align:center;">❌ Henüz aktif sefer bulunamadı.</p>
    <?php endif; ?>

    <div id="bus-container"></div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
    const container = document.getElementById('bus-container');
    const buttons = document.querySelectorAll('.buy-btn');

    buttons.forEach(btn => {
        btn.addEventListener('click', async () => {
            const tripId = btn.dataset.trip;
            container.innerHTML = '<p style="text-align:center;">🕓 Sefer detayları yükleniyor...</p>';

            try {
                const response = await fetch('purchase_partial.php?id=' + tripId, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const html = await response.text();
                container.innerHTML = html;

                const scripts = container.querySelectorAll('script');
                scripts.forEach(oldScript => {
                    const newScript = document.createElement('script');
                    if (oldScript.src) newScript.src = oldScript.src;
                    else newScript.textContent = oldScript.textContent;
                    document.body.appendChild(newScript);
                });
            } catch (error) {
                console.error(error);
                container.innerHTML = '<p style="color:red;text-align:center;">❌ Sefer detayları yüklenemedi.</p>';
            }
        });
    });
});
</script>
</body>
</html>
