<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'user') {
    header('Location: login.php');
    exit;
}


error_reporting(0);
ob_start();

// pdf için böyle bir araç varmis ilk defa kullanıyorum.
// türkçe karakterleri falan tek tek düzelttim ama yine de düz php ile yapmaktan daha mantıklı geldi.
require_once(__DIR__ . '/fpdf186/fpdf.php');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'user') {
    header('Location: login.php');
    exit;
}

$db = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA foreign_keys = ON;');

$userId = $_SESSION['user']['id'];
$ticketId = $_GET['id'] ?? '';

if (!$ticketId) {
    die('<p style="color:red;text-align:center;">❌ Bilet ID eksik.</p>');
}


$stmt = $db->prepare("
    SELECT 
        tk.total_price,
        t.departure_city,
        t.destination_city,
        t.departure_time,
        t.price AS trip_price,
        u.full_name,
        u.email,
        bs.seat_number,
        c.name AS company_name
    FROM tickets tk
    JOIN trips t ON t.id = tk.trip_id
    JOIN users u ON u.id = tk.user_id
    JOIN booked_seats bs ON bs.ticket_id = tk.id
    JOIN bus_companies c ON c.id = t.company_id
    WHERE tk.id = :id AND tk.user_id = :uid
");
$stmt->execute([':id' => $ticketId, ':uid' => $userId]);
$ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$ticket) {
    die('<p style="color:red;text-align:center;">❌ Bilet bulunamadı.</p>');
}


$price = (int)($ticket['total_price'] ?: $ticket['trip_price']);


function cleanText(string $text): string {
    $map = [
        'Ç' => 'C', 'Ö' => 'O', 'Ş' => 'S', 'İ' => 'I', 'I' => 'I', 'Ü' => 'U', 'Ğ' => 'G',
        'ç' => 'c', 'ö' => 'o', 'ş' => 's', 'ı' => 'i', 'ü' => 'u', 'ğ' => 'g'
    ];
    return strtr($text, $map);
}


$pdf = new FPDF('P', 'mm', 'A4');
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 18);
$pdf->Cell(0, 12, cleanText('BİLET BİLGİSİ'), 0, 1, 'C');
$pdf->Ln(10);

$pdf->SetFont('Arial', '', 13);

$pdf->Cell(50, 10, cleanText('Ad Soyad:'), 0, 0);
$pdf->Cell(0, 10, cleanText($ticket['full_name']), 0, 1);

$pdf->Cell(50, 10, cleanText('Firma:'), 0, 0);
$pdf->Cell(0, 10, cleanText($ticket['company_name']), 0, 1);

$pdf->Cell(50, 10, cleanText('Sefer:'), 0, 0);
$pdf->Cell(0, 10, cleanText($ticket['departure_city'] . ' -> ' . $ticket['destination_city']), 0, 1);

$pdf->Cell(50, 10, cleanText('Kalkış:'), 0, 0);
$pdf->Cell(0, 10, date('d.m.Y H:i', strtotime($ticket['departure_time'])), 0, 1);

$pdf->Cell(50, 10, cleanText('Koltuk No:'), 0, 0);
$pdf->Cell(0, 10, (string)$ticket['seat_number'], 0, 1);

$pdf->Cell(50, 10, cleanText('Fiyat:'), 0, 0);
$pdf->Cell(0, 10, $price . ' TL', 0, 1);

$pdf->Ln(15);
$pdf->SetFont('Arial', 'I', 11);
$pdf->Cell(0, 10, cleanText('Bu belge sistem tarafından otomatik oluşturulmuştur.'), 0, 1, 'C');


ob_end_clean();
$pdf->Output('I', 'Bilet-' . substr($ticketId, 0, 8) . '.pdf');
exit;
?>
