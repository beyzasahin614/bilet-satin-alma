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

function e($str): string { return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8'); }
function uuid(): string { return bin2hex(random_bytes(16)); }


$tripId = $_GET['id'] ?? '';
$seatNumber = isset($_GET['seat']) ? (int)$_GET['seat'] : 0;
if (!$tripId || !$seatNumber) die("<p style='color:red;text-align:center;'>❌ Sefer ID veya koltuk numarası eksik.</p>");


$stmt = $db->prepare("
    SELECT t.*, c.name AS company_name
    FROM trips t
    JOIN bus_companies c ON c.id = t.company_id
    WHERE t.id = :id
");
$stmt->execute([':id' => $tripId]);
$trip = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$trip) die("<p style='color:red;text-align:center;'>❌ Sefer bulunamadı.</p>");

$price = (float)$trip['price'];
$companyId = $trip['company_id'];


$userId = $_SESSION['user']['id'];
$stmt = $db->prepare("SELECT balance, full_name FROM users WHERE id = :id");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$userBalance = (float)$user['balance'];
$userName = $user['full_name'] ?? 'Kullanıcı';


$stmt = $db->prepare("
    SELECT COUNT(*) FROM booked_seats bs
    JOIN tickets tk ON tk.id = bs.ticket_id
    WHERE tk.trip_id = :trip AND bs.seat_number = :seat AND tk.status = 'active'
");
$stmt->execute([':trip' => $tripId, ':seat' => $seatNumber]);
$isSeatTaken = (int)$stmt->fetchColumn() > 0;


$discount = 0.0;
$appliedCoupon = null;
$message = '';
$ticketId = '';


$stmt = $db->prepare("
    SELECT * FROM coupons 
    WHERE (company_id = :cid OR company_id IS NULL)
      AND usage_limit > 0
      AND datetime(expire_date) > datetime('now')
    ORDER BY expire_date
");
$stmt->execute([':cid' => $companyId]);
$coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);


if (isset($_GET['apply_coupon'])) {
    $code = $_GET['apply_coupon'];
    $stmt = $db->prepare("
        SELECT * FROM coupons 
        WHERE code = :code 
          AND (company_id = :cid OR company_id IS NULL)
          AND usage_limit > 0
          AND datetime(expire_date) > datetime('now')
        LIMIT 1
    ");
    $stmt->execute([':code' => $code, ':cid' => $companyId]);
    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($coupon) {
        $discount = (float)$coupon['discount'];
        $appliedCoupon = $coupon;
        $message = "🎟️ Kupon uygulandı: {$code} ({$discount}₺ indirim)";
    } else {
        $message = "⚠️ Kupon geçersiz veya süresi dolmuş.";
    }
}


$finalPrice = max(0, $price - $discount);


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_ticket'])) {
    if ($isSeatTaken) {
        $message = "⚠️ Bu koltuk zaten dolu!";
    } elseif ($userBalance < $finalPrice) {
        $message = "💸 Yetersiz bakiye! Mevcut: {$userBalance}₺, Gerekli: {$finalPrice}₺";
    } else {
        try {
            $db->beginTransaction();

            
            $ticketId = uuid();
            $stmt = $db->prepare("
                INSERT INTO tickets (id, trip_id, user_id, total_price, status, created_at)
                VALUES (:id, :trip, :user, :price, 'active', datetime('now'))
            ");
            $stmt->execute([
                ':id' => $ticketId,
                ':trip' => $tripId,
                ':user' => $userId,
                ':price' => $finalPrice
            ]);

           
            $stmt = $db->prepare("
                INSERT INTO booked_seats (id, ticket_id, seat_number)
                VALUES (:id, :ticket, :seat)
            ");
            $stmt->execute([
                ':id' => uuid(),
                ':ticket' => $ticketId,
                ':seat' => $seatNumber
            ]);

            
            $stmt = $db->prepare("UPDATE users SET balance = balance - :p WHERE id = :id");
            $stmt->execute([':p' => $finalPrice, ':id' => $userId]);

            
            if ($appliedCoupon) {
                $db->prepare("UPDATE coupons SET usage_limit = usage_limit - 1 WHERE id = :id")
                   ->execute([':id' => $appliedCoupon['id']]);

                $db->prepare("INSERT INTO user_coupons (id, coupon_id, user_id, created_at)
                              VALUES (:id, :cid, :uid, datetime('now'))")
                   ->execute([
                       ':id' => uuid(),
                       ':cid' => $appliedCoupon['id'],
                       ':uid' => $userId
                   ]);
            }

            $db->commit();
            header("Location: user_panel.php?success=1&seat=$seatNumber");
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $message = "❌ Hata: " . e($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Bilet Satın Al</title>
<style>
body { font-family: Arial, sans-serif; background: #f4f4f9; margin: 0; padding: 0; }
.container { width: 90%; max-width: 800px; margin: 20px auto; background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
h2, h3 { text-align: center; margin-bottom: 10px; }
.details { margin-top: 15px; background: #f8fafc; padding: 15px; border-radius: 10px; }
.label { font-weight: bold; width: 160px; display: inline-block; }
.msg { text-align: center; font-weight: bold; margin: 10px 0; }
form { text-align: center; margin-top: 15px; }
button { background: #2196f3; color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-size: 16px; }
button:hover { background: #1976d2; }
.coupon-box { background:#f8fafc; border-radius:10px; padding:10px; margin-top:15px; }
.coupon-item { margin:5px 0; }
.coupon-item small { color:#666; }
</style>
</head>
<body>
<div class="container">
    <h2>🚌 <?= e($trip['company_name']) ?></h2>
    <h3><?= e($trip['departure_city']) ?> ➜ <?= e($trip['destination_city']) ?></h3>
    <p style="text-align:center;">🕒 Kalkış: <?= e(date('d.m.Y H:i', strtotime($trip['departure_time']))) ?>  
    &nbsp; ⏱ Varış: <?= e(date('d.m.Y H:i', strtotime($trip['arrival_time']))) ?></p>

    <?php if ($message): ?>
        <p class="msg"><?= e($message) ?></p>
    <?php endif; ?>

    <div class="details">
        <p><span class="label">🎟️ Bilet Fiyatı:</span> <?= e((string)$price) ?> ₺</p>
        <?php if ($discount > 0): ?>
            <p><span class="label">💸 Kupon İndirimi:</span> -<?= e((string)$discount) ?> ₺</p>
            <p><span class="label">🧾 Yeni Fiyat:</span> <?= e((string)($price - $discount)) ?> ₺</p>
        <?php endif; ?>
        <p><span class="label">💺 Seçilen Koltuk:</span> <?= e((string)$seatNumber) ?></p>
        <p><span class="label">👤 Ad Soyad:</span> <?= e($userName) ?></p>
        <p><span class="label">💰 Mevcut Bakiye:</span> <?= e((string)$userBalance) ?> ₺</p>
    </div>

    <?php if (count($coupons) > 0): ?>
        <div class="coupon-box">
            <h4 style="text-align:center;">🎁 Mevcut Kuponlar</h4>
            <?php foreach ($coupons as $c): ?>
                <div class="coupon-item" style="text-align:center;">
                    <strong><?= e($c['code']) ?></strong> — <?= (float)$c['discount'] ?> ₺ indirim
                    (<?= e(date('d.m.Y', strtotime($c['expire_date']))) ?> tarihine kadar)
                    <?= $c['company_id'] === null ? "<span style='color:green;'>(Genel)</span>" : "" ?>
                    <br>
                    <small>🔁 Kalan kullanım hakkı: <?= e((string)$c['usage_limit']) ?></small><br>
                    <a href="?id=<?= e($tripId) ?>&seat=<?= e((string)$seatNumber) ?>&apply_coupon=<?= e($c['code']) ?>"
                       style="color:white;background:#4caf50;padding:5px 10px;border-radius:6px;text-decoration:none;display:inline-block;margin-top:5px;">
                       ✅ Kullan
                    </a>
                </div>
                <hr>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <button type="submit" name="buy_ticket" <?= $isSeatTaken ? 'disabled style="background:#aaa;cursor:not-allowed;"' : '' ?>>
            🎫 Bileti Satın Al
        </button>
    </form>
</div>
</body>
</html>
