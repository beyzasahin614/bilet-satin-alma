<?php
declare(strict_types=1);
session_start();


if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'company') {
    header('Location: login.php');
    exit;
}


$db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON;');
$db->exec('PRAGMA busy_timeout = 5000;');


function e($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
function uuid(): string {
    return bin2hex(random_bytes(16));
}


$stmt = $db->prepare("SELECT company_id, full_name, email FROM users WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $_SESSION['user']['id']]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$userData || empty($userData['company_id'])) {
    die("<p style='color:red;text-align:center;'>❌ Firma bilgisi bulunamadı veya admin onayı bekleniyor.</p>");
}

$companyId = $userData['company_id'];
$message = '';
$editTrip = null;



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['logo_file'])) {
    $uploadDir = __DIR__ . '/uploads/logos/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $file = $_FILES['logo_file'];
    $fileName = $file['name'];
    $tmpName = $file['tmp_name'];
    $error = $file['error'];
    $size = $file['size'];

    if ($error !== UPLOAD_ERR_OK) {
        $message = "⚠️ Dosya yüklenemedi (kod: $error)";
    } elseif ($size > 2 * 1024 * 1024) {
        $message = "⚠️ Dosya boyutu 2MB'den fazla olamaz.";
    } else {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $allowedExt = ['png', 'jpg', 'jpeg'];
        if (!in_array($ext, $allowedExt, true)) {
            $message = "⚠️ Sadece PNG veya JPEG formatına izin verilir.";
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmpName);
            finfo_close($finfo);
            $allowedMime = ['image/png', 'image/jpeg'];
            if (!in_array($mime, $allowedMime, true)) {
                $message = "⚠️ Geçersiz dosya tipi (gerçek MIME: $mime).";
            } else {
                $newName = bin2hex(random_bytes(8)) . '.' . $ext;
                $dest = $uploadDir . $newName;

                if (move_uploaded_file($tmpName, $dest)) {
                    $stmt = $db->prepare("UPDATE bus_companies SET logo_path = :logo WHERE id = :cid");
                    $stmt->execute([
                        ':logo' => 'uploads/logos/' . $newName,
                        ':cid' => $companyId
                    ]);
                    $message = "✅ Logo başarıyla yüklendi!";
                } else {
                    $message = "❌ Dosya taşınamadı.";
                }
            }
        }
    }
}



if (isset($_GET['edit']) && $_GET['edit'] !== '') {
    $tripId = $_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM trips WHERE id = :id AND company_id = :cid LIMIT 1");
    $stmt->execute([':id' => $tripId, ':cid' => $companyId]);
    $editTrip = $stmt->fetch(PDO::FETCH_ASSOC);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_trip_id'])) {
    $tripId = trim($_POST['update_trip_id']);
    $departure = trim($_POST['departure_city']);
    $destination = trim($_POST['destination_city']);
    $departureTime = str_replace('T', ' ', trim($_POST['departure_time']));
    $arrivalTime = str_replace('T', ' ', trim($_POST['arrival_time']));
    $price = (int)$_POST['price'];
    $capacity = (int)$_POST['capacity'];

    $stmt = $db->prepare("
        UPDATE trips 
        SET departure_city = :dep, destination_city = :dest,
            departure_time = :dep_time, arrival_time = :arr_time,
            price = :price, capacity = :cap
        WHERE id = :id AND company_id = :cid
    ");
    $stmt->execute([
        ':dep' => $departure,
        ':dest' => $destination,
        ':dep_time' => $departureTime,
        ':arr_time' => $arrivalTime,
        ':price' => $price,
        ':cap' => $capacity,
        ':id' => $tripId,
        ':cid' => $companyId
    ]);
    $message = $stmt->rowCount() > 0 ? "✅ Sefer güncellendi!" : "⚠️ Güncelleme başarısız.";
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_trip_id'])) {
    $tripId = trim($_POST['delete_trip_id']);

   
    $stmt = $db->prepare("
        SELECT tk.id, tk.user_id, tk.total_price
        FROM tickets tk
        WHERE tk.trip_id = :tid AND tk.status = 'active'
    ");
    $stmt->execute([':tid' => $tripId]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    
    foreach ($tickets as $t) {
        $refund = (float)$t['total_price'];
        $uid = $t['user_id'];

       
        $db->prepare("UPDATE users SET balance = balance + :refund WHERE id = :uid")
           ->execute([':refund' => $refund, ':uid' => $uid]);

        
        $db->prepare("UPDATE tickets SET status = 'canceled' WHERE id = :tid")
           ->execute([':tid' => $t['id']]);

        
        $db->prepare("DELETE FROM booked_seats WHERE ticket_id = :tid")
           ->execute([':tid' => $t['id']]);
    }

   
    $stmt = $db->prepare("DELETE FROM trips WHERE id = :id AND company_id = :cid");
    $stmt->execute([':id' => $tripId, ':cid' => $companyId]);

    $message = $stmt->rowCount() > 0
        ? "✅ Sefer silindi, bilet sahiplerine ödedikleri tutar kadar iade yapıldı."
        : "⚠️ Sefer bulunamadı.";
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['departure_city']) && !isset($_POST['update_trip_id'])) {
    $departure = trim($_POST['departure_city']);
    $destination = trim($_POST['destination_city']);
    $departureTime = str_replace('T', ' ', trim($_POST['departure_time']));
    $arrivalTime = str_replace('T', ' ', trim($_POST['arrival_time']));
    $price = (int)$_POST['price'];
    $capacity = (int)$_POST['capacity'];

    if ($departure && $destination && $price > 0 && $capacity > 0) {
        $stmt = $db->prepare("
            INSERT INTO trips (id, company_id, departure_city, destination_city, departure_time, arrival_time, price, capacity)
            VALUES (:id, :cid, :dep, :dest, :dep_time, :arr_time, :price, :cap)
        ");
        $stmt->execute([
            ':id' => uuid(),
            ':cid' => $companyId,
            ':dep' => $departure,
            ':dest' => $destination,
            ':dep_time' => $departureTime,
            ':arr_time' => $arrivalTime,
            ':price' => $price,
            ':cap' => $capacity
        ]);
        $message = "✅ Sefer eklendi!";
    } else {
        $message = "⚠️ Lütfen tüm alanları doldurun.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['coupon_code'])) {
    $code = strtoupper(trim($_POST['coupon_code']));
    $discount = (float)$_POST['discount'];
    $limit = (int)$_POST['usage_limit'];
    $expire = trim($_POST['expire_date']);

    if ($code && $discount > 0 && $limit > 0 && $expire) {
        try {
            $stmt = $db->prepare("
                INSERT INTO coupons (id, code, discount, company_id, usage_limit, expire_date, created_at)
                VALUES (:id, :code, :disc, :cid, :limit, :exp, datetime('now'))
            ");
            $stmt->execute([
                ':id' => uuid(),
                ':code' => $code,
                ':disc' => $discount,
                ':cid' => $companyId,
                ':limit' => $limit,
                ':exp' => $expire
            ]);
            $message = "✅ Kupon başarıyla oluşturuldu!";
        } catch (PDOException $e) {
            $message = str_contains($e->getMessage(), 'UNIQUE')
                ? "⚠️ Bu kupon kodu zaten mevcut!"
                : "❌ Kupon ekleme hatası: " . e($e->getMessage());
        }
    } else {
        $message = "⚠️ Lütfen tüm kupon alanlarını doldurun.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_coupon_id'])) {
    $cid = trim($_POST['delete_coupon_id']);
    $stmt = $db->prepare("DELETE FROM coupons WHERE id = :id AND company_id = :cid");
    $stmt->execute([':id' => $cid, ':cid' => $companyId]);
    $message = $stmt->rowCount() > 0 ? "✅ Kupon silindi!" : "⚠️ Kupon bulunamadı.";
}


$stmt = $db->prepare("SELECT * FROM trips WHERE company_id = :cid ORDER BY datetime(departure_time)");
$stmt->execute([':cid' => $companyId]);
$trips = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $db->prepare("SELECT * FROM coupons WHERE company_id = :cid ORDER BY expire_date");
$stmt->execute([':cid' => $companyId]);
$coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>Firma Paneli | Sefer & Kupon Yönetimi</title>
<style>
body { font-family: Arial, sans-serif; background:#f5f5f5; margin:0; padding:0; }
header { background:#1565c0; color:white; padding:15px 25px; display:flex; justify-content:space-between; align-items:center; }
form, table { background:white; width:90%; margin:20px auto; padding:20px; border-radius:10px; }
input, button { padding:8px; margin:5px; }
th, td { padding:10px; border:1px solid #ddd; text-align:center; }
th { background:#2196f3; color:white; }
.delete-btn { background:#e53935; color:white; border:none; padding:5px 10px; border-radius:5px; cursor:pointer; }
.delete-btn:hover { background:#c62828; }
.logout { color:white; text-decoration:none; }
.msg { text-align:center; font-weight:bold; }
a.edit-btn { background:#ffa000; color:white; padding:5px 10px; border-radius:5px; text-decoration:none; }
a.edit-btn:hover { background:#f57c00; }
</style>
</head>
<body>

<header>
    <h2>🚌 Firma Paneli </h2>
    <div>
        <?= e($userData['full_name']) ?> |
        <a class="logout" href="logout.php">Çıkış Yap</a>
    </div>
</header>

<?php if ($message): ?>
<p class="msg"><?= e($message) ?></p>
<?php endif; ?>

<h3 style="text-align:center;">🖼️ Firma Logosu Güncelle</h3>
<form method="post" enctype="multipart/form-data" style="text-align:center;">
    <input type="file" name="logo_file" accept=".png,.jpg,.jpeg" required>
    <button type="submit">Logo Yükle</button>
</form>
<?php
$stmt = $db->prepare("SELECT logo_path FROM bus_companies WHERE id = :cid");
$stmt->execute([':cid' => $companyId]);
$logo = $stmt->fetchColumn();
if ($logo):
?>
<div style="text-align:center; margin-top:10px;">
    <p>Mevcut Logo:</p>
    <img src="<?= e($logo) ?>" alt="Firma Logosu" style="max-height:100px;border:1px solid #ccc;border-radius:6px;">
</div>
<?php endif; ?>

<h3 style="text-align:center;"><?= $editTrip ? "Sefer Düzenle" : "Yeni Sefer Ekle" ?></h3>
<form method="post">
    <input type="text" name="departure_city" placeholder="Kalkış Şehri"
           value="<?= $editTrip ? e($editTrip['departure_city']) : '' ?>" required>
    <input type="text" name="destination_city" placeholder="Varış Şehri"
           value="<?= $editTrip ? e($editTrip['destination_city']) : '' ?>" required><br>
    <label>Kalkış:</label>
    <input type="datetime-local" name="departure_time"
           value="<?= $editTrip ? e(date('Y-m-d\TH:i', strtotime($editTrip['departure_time']))) : '' ?>" required>
    <label>Varış:</label>
    <input type="datetime-local" name="arrival_time"
           value="<?= $editTrip ? e(date('Y-m-d\TH:i', strtotime($editTrip['arrival_time']))) : '' ?>" required><br>
    <input type="number" name="price" placeholder="Fiyat (₺)"
           value="<?= $editTrip ? e($editTrip['price']) : '' ?>" required>
    <input type="number" name="capacity" placeholder="Koltuk Sayısı"
           value="<?= $editTrip ? e($editTrip['capacity']) : '' ?>" required>

    <?php if ($editTrip): ?>
        <input type="hidden" name="update_trip_id" value="<?= e($editTrip['id']) ?>">
        <button type="submit">💾 Güncelle</button>
        <a href="company_panel.php" style="margin-left:10px;">İptal</a>
    <?php else: ?>
        <button type="submit">Sefer Ekle</button>
    <?php endif; ?>
</form>

<h3 style="text-align:center;">Mevcut Seferler</h3>
<?php if ($trips): ?>
<table>
<tr>
<th>Kalkış</th><th>Varış</th><th>Kalkış Saati</th><th>Varış Saati</th><th>Fiyat</th><th>Kapasite</th><th>İşlem</th>
</tr>
<?php foreach ($trips as $trip): ?>
<tr>
<td><?= e($trip['departure_city']) ?></td>
<td><?= e($trip['destination_city']) ?></td>
<td><?= e(date('d.m.Y H:i', strtotime($trip['departure_time']))) ?></td>
<td><?= e(date('d.m.Y H:i', strtotime($trip['arrival_time']))) ?></td>
<td><?= $trip['price'] ?> ₺</td>
<td><?= $trip['capacity'] ?></td>
<td>
<a class="edit-btn" href="?edit=<?= e($trip['id']) ?>">✏️ Düzenle</a>
<form method="post" style="display:inline;" onsubmit="return confirm('Sefer silinsin mi?');">
<input type="hidden" name="delete_trip_id" value="<?= e($trip['id']) ?>">
<button class="delete-btn">❌ Sil</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</table>
<?php else: ?><p style="text-align:center;">Henüz sefer yok.</p><?php endif; ?>

    <h3 style="text-align:center;">Kupon Yönetimi</h3>

<?php


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['coupon_code'])) {
    $code = strtoupper(trim($_POST['coupon_code']));
    $discount = (float)$_POST['discount'];
    $limit = (int)$_POST['usage_limit'];
    $expire = trim($_POST['expire_date']);
    $editId = $_POST['edit_coupon_id'] ?? '';

    if ($code && $discount > 0 && $limit > 0 && $expire) {
        try {
            if ($editId) {
                
                $stmt = $db->prepare("
                    UPDATE coupons
                    SET code = :code, discount = :disc, usage_limit = :limit, expire_date = :exp
                    WHERE id = :id AND company_id = :cid
                ");
                $stmt->execute([
                    ':code' => $code,
                    ':disc' => $discount,
                    ':limit' => $limit,
                    ':exp' => $expire,
                    ':id' => $editId,
                    ':cid' => $companyId
                ]);
                $message = "✅ Kupon başarıyla güncellendi.";
            } else {
                
                $stmt = $db->prepare("
                    INSERT INTO coupons (id, code, discount, company_id, usage_limit, expire_date, created_at)
                    VALUES (:id, :code, :disc, :cid, :limit, :exp, datetime('now'))
                ");
                $stmt->execute([
                    ':id' => uuid(),
                    ':code' => $code,
                    ':disc' => $discount,
                    ':cid' => $companyId,
                    ':limit' => $limit,
                    ':exp' => $expire
                ]);
                $message = "✅ Kupon başarıyla eklendi.";
            }
        } catch (PDOException $e) {
            $message = str_contains($e->getMessage(), 'UNIQUE')
                ? "⚠️ Bu kupon kodu zaten mevcut!"
                : "❌ Kupon işlem hatası: " . e($e->getMessage());
        }
    } else {
        $message = "⚠️ Lütfen tüm alanları doldurun.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_coupon_id'])) {
    $cid = trim($_POST['delete_coupon_id']);
    $stmt = $db->prepare("DELETE FROM coupons WHERE id = :id AND company_id = :cid");
    $stmt->execute([':id' => $cid, ':cid' => $companyId]);
    $message = $stmt->rowCount() > 0 ? "✅ Kupon silindi!" : "⚠️ Kupon bulunamadı.";
}


$editCoupon = null;
if (isset($_GET['edit_coupon']) && $_GET['edit_coupon'] !== '') {
    $stmt = $db->prepare("SELECT * FROM coupons WHERE id = :id AND company_id = :cid");
    $stmt->execute([':id' => $_GET['edit_coupon'], ':cid' => $companyId]);
    $editCoupon = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<form method="post" style="text-align:center;">
    <input type="text" name="coupon_code" placeholder="Kupon Kodu"
           value="<?= $editCoupon ? e($editCoupon['code']) : '' ?>" required>
    <input type="number" name="discount" placeholder="İndirim Tutarı (₺)" step="0.1"
           value="<?= $editCoupon ? e($editCoupon['discount']) : '' ?>" required>
    <input type="number" name="usage_limit" placeholder="Kullanım Limiti"
           value="<?= $editCoupon ? e($editCoupon['usage_limit']) : '' ?>" required>
    <label>Son Kullanma Tarihi:</label>
    <input type="date" name="expire_date"
           value="<?= $editCoupon ? e($editCoupon['expire_date']) : '' ?>" required>
    <button type="submit"><?= $editCoupon ? 'Güncelle' : 'Kupon Ekle' ?></button>

    <?php if ($editCoupon): ?>
        <input type="hidden" name="edit_coupon_id" value="<?= e($editCoupon['id']) ?>">
        <a href="company_panel.php" style="margin-left:10px;color:#555;">İptal</a>
    <?php endif; ?>
</form>

<h3 style="text-align:center;">Mevcut Kuponlar</h3>
<?php
$stmt = $db->prepare("SELECT * FROM coupons WHERE company_id = :cid ORDER BY expire_date");
$stmt->execute([':cid' => $companyId]);
$coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if ($coupons): ?>
<table>
<tr><th>Kod</th><th>İndirim</th><th>Limit</th><th>Bitiş</th><th>İşlem</th></tr>
<?php foreach ($coupons as $c): ?>
<tr>
<td><?= e($c['code']) ?></td>
<td><?= e($c['discount']) ?></td>
<td><?= e($c['usage_limit']) ?></td>
<td><?= e(date('d.m.Y', strtotime($c['expire_date']))) ?></td>
<td>
    <a href="?edit_coupon=<?= e($c['id']) ?>" class="edit-btn">✏️ Düzenle</a>
    <form method="post" style="display:inline;" onsubmit="return confirm('Kupon silinsin mi?');">
        <input type="hidden" name="delete_coupon_id" value="<?= e($c['id']) ?>">
        <button class="delete-btn">❌ Sil</button>
    </form>
</td>
</tr>
<?php endforeach; ?>
</table>
<?php else: ?>
<p style="text-align:center;">Henüz kupon yok.</p>
<?php endif; ?>


<?php
if (isset($_GET['cancel_ticket'])) {
    $ticketId = $_GET['cancel_ticket'];

    try {
        $db->beginTransaction();

        $stmt = $db->prepare("
            SELECT tk.id, tk.total_price, tk.user_id, t.departure_time
            FROM tickets tk
            JOIN trips t ON t.id = tk.trip_id
            WHERE tk.id = :tid AND t.company_id = :cid AND tk.status = 'active'
        ");
        $stmt->execute([':tid' => $ticketId, ':cid' => $companyId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($ticket) {
            $now = new DateTime('now');
            $departure = new DateTime($ticket['departure_time']);
            $diffHours = ($departure->getTimestamp() - $now->getTimestamp()) / 3600;

            if ($diffHours < 1) {
                echo "<p style='color:red;text-align:center;'>⚠️ Kalkışa 1 saatten az kaldığı için iptal edilemez.</p>";
            } else {
                $db->prepare("UPDATE tickets SET status = 'canceled' WHERE id = :id")
                   ->execute([':id' => $ticketId]);

                $db->prepare("DELETE FROM booked_seats WHERE ticket_id = :tid")
                   ->execute([':tid' => $ticketId]);

                $db->prepare("UPDATE users SET balance = balance + :p WHERE id = :u")
                   ->execute([':p' => $ticket['total_price'], ':u' => $ticket['user_id']]);

                $db->commit();
                echo "<p style='color:green;text-align:center;'>✅ Bilet iptal edildi ve {$ticket['total_price']}₺ iade yapıldı.</p>";
            }
        } else {
            echo "<p style='color:red;text-align:center;'>⚠️ Bilet bulunamadı veya zaten iptal edilmiş.</p>";
        }
    } catch (Exception $e) {
        $db->rollBack();
        echo "<p style='color:red;text-align:center;'>❌ Hata: " . e($e->getMessage()) . "</p>";
    }
}


$stmt = $db->prepare("
    SELECT tk.id AS ticket_id, u.full_name, t.departure_city, t.destination_city, 
           bs.seat_number, tk.total_price, tk.status, tk.created_at
    FROM tickets tk
    JOIN trips t ON t.id = tk.trip_id
    JOIN users u ON u.id = tk.user_id
    LEFT JOIN booked_seats bs ON bs.ticket_id = tk.id
    WHERE t.company_id = :cid
    ORDER BY tk.created_at DESC
");
$stmt->execute([':cid' => $companyId]);
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h3 style="text-align:center; margin-top:50px;">🎟️ Satılan Biletler</h3>
<?php if (empty($tickets)): ?>
    <p style="text-align:center;">Henüz satılmış bilet bulunmamaktadır.</p>
<?php else: ?>
    <table>
        <tr>
            <th>Kullanıcı</th>
            <th>Sefer</th>
            <th>Koltuk</th>
            <th>Fiyat</th>
            <th>Durum</th>
            <th>Tarih</th>
            <th>İşlem</th>
        </tr>
        <?php foreach ($tickets as $t): ?>
            <tr>
                <td><?= e($t['full_name']) ?></td>
                <td><?= e($t['departure_city']) ?> ➜ <?= e($t['destination_city']) ?></td>
                <td><?= e((string)$t['seat_number']) ?></td>
                <td><?= e((string)$t['total_price']) ?> ₺</td>
                <td><?= e($t['status']) ?></td>
                <td><?= e(date('d.m.Y H:i', strtotime($t['created_at']))) ?></td>
                <td>
                    <?php if ($t['status'] === 'active'): ?>
                        <a href="?cancel_ticket=<?= e($t['ticket_id']) ?>"
                           onclick="return confirm('Bu bileti iptal etmek istediğinize emin misiniz?')"
                           style="color:white;background:#d32f2f;padding:5px 10px;border-radius:6px;text-decoration:none;">
                           ❌ İptal Et
                        </a>
                    <?php else: ?>
                        <span style="color:#999;">İptal Edildi</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
</body>
</html>
