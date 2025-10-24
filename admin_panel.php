<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$adminName = htmlspecialchars($_SESSION['user']['full_name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');

$db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON;');

function e($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$message = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
    $userId = $_POST['user_id'] ?? '';

    $stmt = $db->prepare("SELECT full_name FROM users WHERE id = :id AND role = 'company'");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $companyId = bin2hex(random_bytes(16));

        $stmtCompany = $db->prepare("
            INSERT INTO bus_companies (id, name, created_at)
            VALUES (:id, :name, CURRENT_TIMESTAMP)
        ");
        $stmtCompany->execute([':id' => $companyId, ':name' => $user['full_name']]);

        $stmtUpdate = $db->prepare("
            UPDATE users
            SET status = 'active', company_id = :company_id
            WHERE id = :id
        ");
        $stmtUpdate->execute([':company_id' => $companyId, ':id' => $userId]);

        $message = "✅ Firma onaylandı ve aktif hale getirildi.";
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_company'])) {
    $userId = $_POST['user_id'] ?? '';

    $stmt = $db->prepare("SELECT company_id, full_name FROM users WHERE id = :id AND role = 'company'");
    $stmt->execute([':id' => $userId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($company && $company['company_id']) {
        $companyId = $company['company_id'];

        
        $stmt = $db->prepare("
            SELECT tk.id AS ticket_id, tk.user_id, COALESCE(tk.total_price, t.price, 0) AS total_price
            FROM tickets tk
            JOIN trips t ON t.id = tk.trip_id
            WHERE t.company_id = :cid AND tk.status = 'active'
        ");
        $stmt->execute([':cid' => $companyId]);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $db->beginTransaction();
        try {
            foreach ($tickets as $t) {
                $refund = (float)$t['total_price'];
                $uid = $t['user_id'];

                // Para iadesi
                $db->prepare("UPDATE users SET balance = balance + :r WHERE id = :uid")
                   ->execute([':r' => $refund, ':uid' => $uid]);

                // Bileti iptal et
                $db->prepare("UPDATE tickets SET status = 'canceled' WHERE id = :tid")
                   ->execute([':tid' => $t['ticket_id']]);

                // Koltuk kaydını sil
                $db->prepare("DELETE FROM booked_seats WHERE ticket_id = :tid")
                   ->execute([':tid' => $t['ticket_id']]);
            }

            
            $db->prepare("UPDATE users SET status = 'banned' WHERE id = :id")
               ->execute([':id' => $userId]);

            $db->commit();

           
            $db->prepare("DELETE FROM trips WHERE company_id = :cid")
               ->execute([':cid' => $companyId]);

            $message = "⚠️ Firma devre dışı bırakıldı, biletler iptal edildi ve bakiyeler iade edildi.";
        } catch (Exception $e) {
            $db->rollBack();
            $message = "❌ İşlem hatası: " . e($e->getMessage());
        }
    } else {
        $message = "❌ Firma bulunamadı.";
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
                VALUES (:id, :code, :disc, NULL, :limit, :exp, datetime('now'))
            ");
            $stmt->execute([
                ':id' => bin2hex(random_bytes(16)),
                ':code' => $code,
                ':disc' => $discount,
                ':limit' => $limit,
                ':exp' => $expire
            ]);
            $message = "✅ Genel kupon oluşturuldu.";
        } catch (PDOException $e) {
            $message = str_contains($e->getMessage(), 'UNIQUE')
                ? "⚠️ Bu kupon kodu zaten mevcut!"
                : "❌ Kupon ekleme hatası: " . e($e->getMessage());
        }
    } else {
        $message = "⚠️ Lütfen tüm alanları doldurun.";
    }
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_coupon_id'])) {
    $cid = trim($_POST['delete_coupon_id']);
    $stmt = $db->prepare("DELETE FROM coupons WHERE id = :id AND company_id IS NULL");
    $stmt->execute([':id' => $cid]);
    $message = $stmt->rowCount() > 0 ? "🗑️ Kupon silindi." : "⚠️ Kupon bulunamadı.";
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_user_id'])) {
    $userId = $_POST['promote_user_id'];

    $stmt = $db->prepare("SELECT full_name, email FROM users WHERE id = :id AND role = 'user'");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $companyId = bin2hex(random_bytes(16));

        $stmtCompany = $db->prepare("
            INSERT INTO bus_companies (id, name, created_at)
            VALUES (:id, :name, datetime('now'))
        ");
        $stmtCompany->execute([':id' => $companyId, ':name' => $user['full_name']]);

        $stmtUpdate = $db->prepare("
            UPDATE users
            SET role = 'company', status = 'active', company_id = :cid
            WHERE id = :id
        ");
        $stmtUpdate->execute([':cid' => $companyId, ':id' => $userId]);

        $message = "🚀 Kullanıcı firma admini olarak yükseltildi.";
    } else {
        $message = "❌ Kullanıcı bulunamadı veya zaten firma admini.";
    }
}



$pendingCompanies = $db->query("
    SELECT id, full_name, email, created_at
    FROM users WHERE role = 'company' AND status = 'pending'
")->fetchAll(PDO::FETCH_ASSOC);

$activeCompanies = $db->query("
    SELECT u.id, u.full_name, u.email, b.name AS company_name, b.created_at
    FROM users u JOIN bus_companies b ON u.company_id = b.id
    WHERE u.role = 'company' AND u.status = 'active'
")->fetchAll(PDO::FETCH_ASSOC);

$disabledCompanies = $db->query("
    SELECT u.full_name, u.email, b.name AS company_name
    FROM users u JOIN bus_companies b ON u.company_id = b.id
    WHERE u.role = 'company' AND u.status = 'banned'
")->fetchAll(PDO::FETCH_ASSOC);

$globalCoupons = $db->query("
    SELECT * FROM coupons WHERE company_id IS NULL ORDER BY expire_date
")->fetchAll(PDO::FETCH_ASSOC);

$users = $db->query("
    SELECT id, full_name, email, created_at FROM users WHERE role = 'user'
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<title>👑 Admin Paneli</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5 mb-5">
    <h3 class="text-center mb-4">👑 Admin Paneli</h3>
    <p class="text-center text-muted">
        Hoş geldin, <strong style="color:#1565c0;"><?= $adminName ?></strong>
    </p>

    <?php if ($message): ?>
        <div class="alert alert-info text-center"><?= e($message) ?></div>
    <?php endif; ?>

    <hr>

    <h5 class="mb-3">🧍‍♂️ Tüm Kullanıcılar</h5>
    <?php if ($users): ?>
        <table class="table table-bordered table-striped">
            <thead class="table-primary">
                <tr><th>Ad Soyad</th><th>E-posta</th><th>Kayıt Tarihi</th><th>İşlem</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['full_name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e($u['created_at']) ?></td>
                    <td>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="promote_user_id" value="<?= e($u['id']) ?>">
                            <button type="submit" class="btn btn-warning btn-sm">Firma Admini Yap</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <hr class="my-4">

    <h5 class="mb-3">🕓 Onay Bekleyen Firmalar</h5>
    <?php if ($pendingCompanies): ?>
        <table class="table table-bordered table-striped">
            <thead class="table-warning">
                <tr><th>Firma Adı</th><th>E-posta</th><th>Kayıt Tarihi</th><th>İşlem</th></tr>
            </thead>
            <tbody>
                <?php foreach ($pendingCompanies as $company): ?>
                <tr>
                    <td><?= e($company['full_name']) ?></td>
                    <td><?= e($company['email']) ?></td>
                    <td><?= e($company['created_at']) ?></td>
                    <td>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="user_id" value="<?= e($company['id']) ?>">
                            <button type="submit" name="approve" class="btn btn-success btn-sm">Onayla</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <hr class="my-4">

    <h5 class="mb-3">✅ Aktif Firmalar</h5>
    <?php if ($activeCompanies): ?>
        <table class="table table-bordered table-striped">
            <thead class="table-success">
                <tr><th>Firma Adı</th><th>E-posta</th><th>Kayıt Tarihi</th><th>Durum</th></tr>
            </thead>
            <tbody>
                <?php foreach ($activeCompanies as $company): ?>
                <tr>
                    <td><?= e($company['company_name']) ?></td>
                    <td><?= e($company['email']) ?></td>
                    <td><?= e($company['created_at']) ?></td>
                    <td>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="user_id" value="<?= e($company['id']) ?>">
                            <button type="submit" name="disable_company" class="btn btn-outline-danger btn-sm">Devre Dışı Bırak</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php
// 💸 Genel kupon ekleme / güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['coupon_code'])) {
    $code = strtoupper(trim($_POST['coupon_code']));
    $discount = (float)$_POST['discount'];
    $limit = (int)$_POST['usage_limit'];
    $expire = trim($_POST['expire_date']);
    $editId = $_POST['edit_coupon_id'] ?? '';

    if ($code && $discount > 0 && $limit > 0 && $expire) {
        try {
            if ($editId) {
                // Güncelleme
                $stmt = $db->prepare("
                    UPDATE coupons
                    SET code = :code, discount = :disc, usage_limit = :limit, expire_date = :exp
                    WHERE id = :id AND company_id IS NULL
                ");
                $stmt->execute([
                    ':code' => $code,
                    ':disc' => $discount,
                    ':limit' => $limit,
                    ':exp' => $expire,
                    ':id' => $editId
                ]);
                $message = "✅ Kupon başarıyla güncellendi.";
            } else {
                // Yeni ekleme
                $stmt = $db->prepare("
                    INSERT INTO coupons (id, code, discount, company_id, usage_limit, expire_date, created_at)
                    VALUES (:id, :code, :disc, NULL, :limit, :exp, datetime('now'))
                ");
                $stmt->execute([
                    ':id' => bin2hex(random_bytes(16)),
                    ':code' => $code,
                    ':disc' => $discount,
                    ':limit' => $limit,
                    ':exp' => $expire
                ]);
                $message = "✅ Yeni genel kupon oluşturuldu.";
            }
        } catch (PDOException $e) {
            $message = str_contains($e->getMessage(), 'UNIQUE')
                ? "⚠️ Bu kupon kodu zaten mevcut!"
                : "❌ Kupon işlemi hatası: " . e($e->getMessage());
        }
    } else {
        $message = "⚠️ Lütfen tüm alanları doldurun.";
    }
}

// 💸 Kupon silme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_coupon_id'])) {
    $cid = trim($_POST['delete_coupon_id']);
    $stmt = $db->prepare("DELETE FROM coupons WHERE id = :id AND company_id IS NULL");
    $stmt->execute([':id' => $cid]);
    $message = $stmt->rowCount() > 0 ? "🗑️ Kupon silindi." : "⚠️ Kupon bulunamadı.";
}

// 💸 Düzenlenecek kuponu çek
$editCoupon = null;
if (isset($_GET['edit_coupon']) && $_GET['edit_coupon'] !== '') {
    $stmt = $db->prepare("SELECT * FROM coupons WHERE id = :id AND company_id IS NULL");
    $stmt->execute([':id' => $_GET['edit_coupon']]);
    $editCoupon = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Kuponları çek
$globalCoupons = $db->query("
    SELECT * FROM coupons WHERE company_id IS NULL ORDER BY expire_date
")->fetchAll(PDO::FETCH_ASSOC);
?>

<hr class="my-4">
<h5 class="mb-3">💸 Genel Kupon Yönetimi</h5>

<!-- Kupon ekleme / düzenleme formu -->
<form method="post" class="mb-4 p-3 border rounded bg-white" style="max-width:600px;margin:auto;">
    <div class="row g-2">
        <div class="col-md-3">
            <input type="text" name="coupon_code" class="form-control" placeholder="Kupon Kodu"
                   value="<?= $editCoupon ? e($editCoupon['code']) : '' ?>" required>
        </div>
        <div class="col-md-2">
            <input type="number" name="discount" step="0.1" class="form-control" placeholder="İndirim (₺)"
                   value="<?= $editCoupon ? e($editCoupon['discount']) : '' ?>" required>
        </div>
        <div class="col-md-2">
            <input type="number" name="usage_limit" class="form-control" placeholder="Kullanım"
                   value="<?= $editCoupon ? e($editCoupon['usage_limit']) : '' ?>" required>
        </div>
        <div class="col-md-3">
            <input type="date" name="expire_date" class="form-control"
                   value="<?= $editCoupon ? e($editCoupon['expire_date']) : '' ?>" required>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">
                <?= $editCoupon ? 'Güncelle' : 'Oluştur' ?>
            </button>
        </div>
    </div>
    <?php if ($editCoupon): ?>
        <input type="hidden" name="edit_coupon_id" value="<?= e($editCoupon['id']) ?>">
        <div class="text-center mt-2">
            <a href="admin_panel.php" class="text-decoration-none text-secondary">İptal</a>
        </div>
    <?php endif; ?>
</form>

<!-- Mevcut genel kuponlar -->
<?php if ($globalCoupons): ?>
<table class="table table-bordered table-striped text-center" style="max-width:800px;margin:auto;">
    <thead class="table-info">
        <tr>
            <th>Kod</th>
            <th>İndirim</th>
            <th>Limit</th>
            <th>Son Kullanma</th>
            <th>İşlem</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($globalCoupons as $c): ?>
        <tr>
            <td><?= e($c['code']) ?></td>
            <td><?= e($c['discount']) ?> ₺</td>
            <td><?= e($c['usage_limit']) ?></td>
            <td><?= e(date('d.m.Y', strtotime($c['expire_date']))) ?></td>
            <td>
                <a href="?edit_coupon=<?= e($c['id']) ?>" class="btn btn-warning btn-sm">✏️ Düzenle</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Kupon silinsin mi?');">
                    <input type="hidden" name="delete_coupon_id" value="<?= e($c['id']) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Sil</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
    <p class="text-center text-muted">Henüz genel kupon bulunmuyor.</p>
<?php endif; ?>


    <div class="text-center mt-4">
        <a href="logout.php" class="btn btn-outline-danger">Çıkış Yap</a>
    </div>
</div>
</body>
</html>
