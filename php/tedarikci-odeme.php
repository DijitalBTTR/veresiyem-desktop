<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$uid = userId();

$tedarikci_id = (int)($_POST['tedarikci_id'] ?? 0);
$hesap_id     = (int)($_POST['hesap_id'] ?? 0);
$fatura_id    = (int)($_POST['fatura_id'] ?? 0);
$tutar        = (float)($_POST['tutar'] ?? 0);
$aciklama     = trim($_POST['aciklama'] ?? '');
$tarih        = trim($_POST['tarih'] ?? date('Y-m-d'));

// Tedarikçi bu kullanıcıya ait mi?
$tKontrol = $pdo->prepare("SELECT ad FROM tedarikciler WHERE id = ? AND user_id = ?");
$tKontrol->execute([$tedarikci_id, $uid]);
$tedarikci = $tKontrol->fetch();
if (!$tedarikci || $tutar <= 0 || !$hesap_id) {
    header('Location: /tedarikci?id=' . $tedarikci_id);
    exit;
}

// Kasa hesabı bu kullanıcıya ait mi?
$kasaKontrol = $pdo->prepare("SELECT id, ad FROM kasa_hesaplari WHERE id = ? AND user_id = ? AND aktif = 1");
$kasaKontrol->execute([$hesap_id, $uid]);
if (!$kasaKontrol->fetch()) {
    header('Location: /tedarikci?id=' . $tedarikci_id);
    exit;
}

$pdo->beginTransaction();

// Kasa'ya çıkış hareketi ekle
$kasaAciklama = $tedarikci['ad'] . ' ödemesi';
if ($aciklama) $kasaAciklama .= ' — ' . $aciklama;
if ($fatura_id) {
    $fNo = $pdo->prepare("SELECT fatura_no FROM faturalar WHERE id = ?")->execute([$fatura_id]);
    $kasaAciklama .= ' (Fatura #' . $fatura_id . ')';
}

$kasaIns = $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, kaynak, kaynak_id, tarih) VALUES (?,?,?,?,?,?,?,?)");
$kasaIns->execute([
    $uid,
    $hesap_id,
    'cikis',
    $tutar,
    $kasaAciklama,
    'tedarikci_odeme',
    $tedarikci_id,
    $tarih,
]);

// Fatura seçildiyse durumunu güncelle
if ($fatura_id) {
    // Fatura bu tedarikçiye ve bu kullanıcıya ait mi kontrol
    $fKontrol = $pdo->prepare("
        SELECT f.id, f.genel_toplam FROM faturalar f
        JOIN tedarikciler t ON t.id = f.tedarikci_id
        WHERE f.id = ? AND f.tedarikci_id = ? AND t.user_id = ?
    ");
    $fKontrol->execute([$fatura_id, $tedarikci_id, $uid]);
    $fatura = $fKontrol->fetch();
    if ($fatura) {
        // Ödeme tutarı fatura tutarına eşit veya fazlaysa ödendi yap
        if ($tutar >= $fatura['genel_toplam'] * 0.999) {
            $pdo->prepare("UPDATE faturalar SET durum = 'odendi' WHERE id = ?")->execute([$fatura_id]);
        }
    }
} else {
    // Genel ödeme: bekleyen faturalara sırayla uygula (eskiden yeniye)
    $bekleyenler = $pdo->prepare("
        SELECT id, genel_toplam FROM faturalar
        WHERE tedarikci_id = ? AND durum = 'odenmedi'
        ORDER BY tarih ASC, id ASC
    ");
    $bekleyenler->execute([$tedarikci_id]);
    $kalanOdeme = $tutar;
    foreach ($bekleyenler->fetchAll() as $bf) {
        if ($kalanOdeme <= 0) break;
        if ($kalanOdeme >= $bf['genel_toplam'] * 0.999) {
            $pdo->prepare("UPDATE faturalar SET durum = 'odendi' WHERE id = ?")->execute([$bf['id']]);
            $kalanOdeme -= $bf['genel_toplam'];
        } else {
            break; // Kısmi ödeme — faturayı yarım bırakma
        }
    }
}

$pdo->commit();

header('Location: /tedarikci?id=' . $tedarikci_id);
exit;
