<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();

header('Content-Type: application/json; charset=utf-8');

$q   = trim($_GET['q'] ?? '');
$uid = userId();

if (strlen($q) < 1) { echo json_encode([]); exit; }

$like = '%' . $q . '%';
$sonuclar = [];

// Cariler
$stmt = $pdo->prepare("SELECT id, ad, telefon, not_ FROM cariler WHERE user_id=? AND (ad LIKE ? OR not_ LIKE ?) ORDER BY ad ASC LIMIT 5");
$stmt->execute([$uid, $like, $like]);
foreach ($stmt->fetchAll() as $c) {
    // Bakiye
    $b = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tip='alacak' THEN tutar ELSE 0 END),0)-COALESCE(SUM(CASE WHEN tip='borc' THEN tutar ELSE 0 END),0) AS bakiye FROM hareketler WHERE cari_id=?");
    $b->execute([$c['id']]);
    $bakiye = (float)$b->fetchColumn();
    $altCari = $c['telefon'] ?: 'Telefon yok';
    if (!empty($c['not_'])) $altCari .= ' · ' . $c['not_'];
    $sonuclar[] = [
        'tip'    => 'cari',
        'id'     => $c['id'],
        'ad'     => $c['ad'],
        'alt'    => $altCari,
        'bakiye' => $bakiye,
        'url'    => '/cari?id=' . $c['id'],
    ];
}

// Stoklar
$stmt = $pdo->prepare("SELECT id, ad, miktar, birim, aciklama FROM stoklar WHERE user_id=? AND (ad LIKE ? OR aciklama LIKE ?) ORDER BY ad ASC LIMIT 4");
$stmt->execute([$uid, $like, $like]);
foreach ($stmt->fetchAll() as $s) {
    $altStok = number_format($s['miktar'], 2, ',', '.') . ' ' . $s['birim'];
    if (!empty($s['aciklama'])) $altStok .= ' · ' . $s['aciklama'];
    $sonuclar[] = [
        'tip'    => 'stok',
        'id'     => $s['id'],
        'ad'     => $s['ad'],
        'alt'    => $altStok,
        'url'    => '/stok-duzenle?id=' . $s['id'],
    ];
}

// Hareketler (açıklama)
$stmt = $pdo->prepare("
    SELECT h.id, h.tip, h.tutar, h.aciklama, h.tarih, c.ad AS cari_ad, c.id AS cari_id
    FROM hareketler h JOIN cariler c ON c.id=h.cari_id
    WHERE c.user_id=? AND h.aciklama LIKE ?
    ORDER BY h.tarih DESC LIMIT 4
");
$stmt->execute([$uid, $like]);
foreach ($stmt->fetchAll() as $h) {
    $sonuclar[] = [
        'tip'    => 'hareket',
        'id'     => $h['id'],
        'ad'     => $h['cari_ad'] . ' — ' . $h['aciklama'],
        'alt'    => ($h['tip'] === 'alacak' ? '+' : '−') . '₺' . number_format($h['tutar'], 2, ',', '.') . ' · ' . date('d.m.Y', strtotime($h['tarih'])),
        'htip'   => $h['tip'],
        'url'    => '/cari?id=' . $h['cari_id'],
    ];
}

echo json_encode($sonuclar, JSON_UNESCAPED_UNICODE);
