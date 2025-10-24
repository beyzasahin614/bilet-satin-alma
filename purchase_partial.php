<?php
declare(strict_types=1);
session_start();


$db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON;');


function e($str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

$tripId = $_GET['id'] ?? '';
if (!$tripId) die('<p style="color:red;text-align:center;">❌ Sefer ID bulunamadı.</p>');


$stmt = $db->prepare("
    SELECT t.*, c.name AS company_name 
    FROM trips t
    JOIN bus_companies c ON c.id = t.company_id
    WHERE t.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $tripId]);
$trip = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trip) die('<p style="color:red;text-align:center;">❌ Sefer bulunamadı. ID: ' . e($tripId) . '</p>');


$stmt = $db->prepare("
    SELECT code, discount, usage_limit, expire_date, company_id
    FROM coupons
    WHERE (company_id = :cid OR company_id IS NULL)
      AND usage_limit > 0
      AND datetime(expire_date) > datetime('now')
    ORDER BY expire_date
");
$stmt->execute([':cid' => $trip['company_id']]);
$coupons = $stmt->fetchAll(PDO::FETCH_ASSOC);


$stmt = $db->prepare("
    SELECT seat_number FROM booked_seats bs
    JOIN tickets tk ON tk.id = bs.ticket_id
    WHERE tk.trip_id = :tid AND tk.status = 'active'
");
$stmt->execute([':tid' => $tripId]);
$bookedSeats = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'seat_number');


$isLoggedIn = isset($_SESSION['user']) && $_SESSION['user']['role'] === 'user';
?>
<div class="bus-wrapper">
    <h3><?= e($trip['departure_city']) ?> ➜ <?= e($trip['destination_city']) ?> | <?= (int)$trip['price'] ?> ₺</h3>
    <p class="bus-subtitle">Firma: <?= e($trip['company_name']) ?> | <?= (int)$trip['capacity'] ?> koltuklu otobüs</p>

    <?php if ($coupons): ?>
        <div class="coupon-box">
            <h4>🎁 Bu sefer için geçerli aktif kuponlar:</h4>
            <ul>
                <?php foreach ($coupons as $c): ?>
                    <li>
                        <strong><?= e($c['code']) ?></strong> — 
                        <?= (float)$c['discount'] ?> ₺ indirim 
                        (<?= e(date('d.m.Y', strtotime($c['expire_date']))) ?> tarihine kadar)
                        <?= $c['company_id'] === null ? "<span style='color:green;'>(Genel Kupon)</span>" : "" ?>
                        <br>
                        <small style="color:#555;">
                            🔁 Kalan kullanım hakkı: <strong><?= e((string)$c['usage_limit']) ?></strong>
                        </small>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="bus-container" id="busContainer"></div>
    <div class="legend">
        <span><span class="available-box"></span> Boş</span>
        <span><span class="booked-box"></span> Dolu</span>
        <span><span class="selected-box"></span> Seçili</span>
    </div>
</div>

<style>
.bus-wrapper { text-align:center; margin-top:20px; }
.bus-subtitle { color:#777; margin-bottom:15px; }

.coupon-box {
    background:#e8f5e9;
    border:1px solid #81c784;
    padding:10px;
    border-radius:8px;
    width:60%;
    margin:10px auto;
    text-align:left;
}
.coupon-box h4 { margin-top:0; color:#2e7d32; }
.coupon-box ul { margin:0; padding-left:20px; }

.bus-container {
    display:flex;
    flex-direction:column;
    align-items:center;
    background:#eaf2ff;
    padding:25px;
    border-radius:15px;
    width:fit-content;
    margin:auto;
    box-shadow:0 2px 5px rgba(0,0,0,0.1);
}
.row { display:flex; justify-content:center; align-items:center; gap:60px; margin-bottom:10px; }
.side { display:flex; gap:10px; }
.seat {
    width:45px; height:45px;
    display:flex; align-items:center; justify-content:center;
    font-weight:bold; border-radius:8px;
    cursor:pointer; color:white; user-select:none;
    transition:background 0.2s ease, transform 0.1s ease;
    box-shadow:0 1px 2px rgba(0,0,0,0.2);
}
.available { background:#9e9e9e; }
.available:hover { background:#4caf50; transform:scale(1.1); }
.booked { background:#d32f2f; cursor:not-allowed; }
.selected { background:#2196f3; }
.legend { margin-top:15px; font-size:14px; color:#555; }
.legend span { margin:0 10px; display:inline-block; }
.legend .available-box { background:#9e9e9e; width:15px; height:15px; display:inline-block; border-radius:3px; }
.legend .booked-box { background:#d32f2f; width:15px; height:15px; display:inline-block; border-radius:3px; }
.legend .selected-box { background:#2196f3; width:15px; height:15px; display:inline-block; border-radius:3px; }
.driver { width:40px; height:40px; background:#333; border-radius:5px; margin-bottom:15px; color:white; display:flex; align-items:center; justify-content:center; font-size:12px; }
</style>

<script>
(function(){
    const totalSeats = <?= (int)$trip['capacity'] ?>;
    const booked = <?= json_encode(array_map('intval', $bookedSeats)) ?>;
    const container = document.getElementById('busContainer');
    container.innerHTML = '';

    const driver = document.createElement('div');
    driver.classList.add('driver');
    driver.textContent = '🚍';
    container.appendChild(driver);

    const seatsPerRow = 4;
    const rows = Math.ceil(totalSeats / seatsPerRow);
    let seatNum = 1;

    for (let r = 0; r < rows; r++) {
        const row = document.createElement('div');
        row.classList.add('row');

        const leftSide = document.createElement('div');
        leftSide.classList.add('side');

        const rightSide = document.createElement('div');
        rightSide.classList.add('side');

        for (let i = 0; i < 2 && seatNum <= totalSeats; i++, seatNum++) leftSide.appendChild(createSeat(seatNum));
        for (let i = 0; i < 2 && seatNum <= totalSeats; i++, seatNum++) rightSide.appendChild(createSeat(seatNum));

        row.appendChild(leftSide);
        row.appendChild(rightSide);
        container.appendChild(row);
    }

    function createSeat(num) {
        const seat = document.createElement('div');
        seat.classList.add('seat');
        seat.textContent = num;

        if (booked.includes(num)) {
            seat.classList.add('booked');
        } else {
            seat.classList.add('available');
            seat.addEventListener('click', () => {
                <?php if (!$isLoggedIn): ?>
                    alert('⚠️ Lütfen giriş yapın.');
                    window.location.href = 'login.php';
                <?php else: ?>
                    window.location.href = 'purchase.php?id=<?= e($tripId) ?>&seat=' + num;
                <?php endif; ?>
            });
        }
        return seat;
    }
})();
</script>
