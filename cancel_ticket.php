<?php
declare(strict_types=1);
session_start();


if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'user') {
    header('Location: login.php');
    exit;
}


$db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON;');


function e(string $str): string { return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); }

$userId = $_SESSION['user']['id'];
$ticketId = $_GET['id'] ?? '';

if (!$ticketId) {
    die("<p style='color:red;text-align:center;'>❌ Bilet ID eksik.</p>");
}


$stmt = $db->prepare("
    SELECT tk.*, t.departure_time, t.price 
    FROM tickets tk
    JOIN trips t ON t.id = tk.trip_id
    WHERE tk.id = :tid AND tk.user_id = :uid
");
$stmt->execute([':tid' => $ticketId, ':uid' => $userId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    die("<p style='color:red;text-align:center;'>❌ Bilet bulunamadı.</p>");
}


$departureTime = strtotime($ticket['departure_time']);
$now = time();
$diffHours = ($departureTime - $now) / 3600;
if ($diffHours < 1) {
    echo "<p style='color:red;text-align:center;'>⚠️ Kalkışa 1 saatten az kaldığı için iptal edilemez.</p>";
    echo "<p style='text-align:center;'><a href='user_panel.php'>🔙 Geri Dön</a></p>";
    exit;
}

try {
    $db->beginTransaction();

    
    $stmt = $db->prepare("UPDATE tickets SET status = 'canceled' WHERE id = :id");
    $stmt->execute([':id' => $ticketId]);

    $stmt = $db->prepare("DELETE FROM booked_seats WHERE ticket_id = :tid");
    $stmt->execute([':tid' => $ticketId]);

    $stmt = $db->prepare("UPDATE users SET balance = balance + :p WHERE id = :uid");
    $stmt->execute([':p' => (int)$ticket['total_price'], ':uid' => $userId]);

    $db->commit();

    
    echo "<div style='font-family:Arial;text-align:center;margin-top:50px;'>
            <h2 style='color:green;'>✅ Bilet başarıyla iptal edildi</h2>
            <p>Koltuk tekrar satışa açılmıştır.</p>
            <p>Ücret hesabınıza iade edilmiştir.</p>
            <p><a href='user_panel.php' style='text-decoration:none;color:#2196f3;font-weight:bold;'>🏠 Kullanıcı Paneline Dön</a></p>
          </div>";
    exit;

} catch (Exception $e) {
    $db->rollBack();
    echo "<p style='color:red;text-align:center;'>❌ Hata: " . e($e->getMessage()) . "</p>";
    echo "<p style='text-align:center;'><a href='user_panel.php'>🔙 Geri Dön</a></p>";
    exit;
}
?>
