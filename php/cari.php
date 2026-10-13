<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/sync.php';
girisGerekli();
$activePage = 'cariler';

$id  = (int)($_GET['id'] ?? 0);
$uid = userId();
if (!$id) { header('Location: /cariler'); exit; }

// Firma adı ayarı
$firmaRow = $pdo->prepare("SELECT deger FROM ayarlar WHERE user_id=? AND anahtar='firma_adi'");
$firmaRow->execute([$uid]);
$firmaRow = $firmaRow->fetch();
$firmaAdi = ($firmaRow && $firmaRow['deger']) ? $firmaRow['deger'] : 'veresiyem';

$cariStmt = $pdo->prepare("SELECT * FROM cariler WHERE id = ? AND user_id = ?");
$cariStmt->execute([$id, $uid]);
$cari = $cariStmt->fetch();
if (!$cari) { http_response_code(404); require __DIR__ . '/404.php'; exit; }

// VPS'ten hareketleri çekip SQLite'ı güncelle (sadece GET - sayfa açılışı)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($cari['api_id'])) {
    syncHareketler($pdo, $uid, (int)$cari['api_id'], (int)$cari['id']);
}

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sil-hareket') {
    csrfKontrol();
    $hid = (int)($_POST['hareket_id'] ?? 0);
    $chk = $pdo->prepare("SELECT h.id FROM hareketler h JOIN cariler c ON c.id=h.cari_id WHERE h.id=? AND c.user_id=?");
    $chk->execute([$hid, $uid]);
    if ($chk->fetch()) {
        // Stok miktarını geri yükle
        $hRow = $pdo->prepare("SELECT stok_id, stok_miktar, api_id FROM hareketler WHERE id=?");
        $hRow->execute([$hid]);
        $hRow = $hRow->fetch();
        if ($hRow && $hRow['stok_id'] && $hRow['stok_miktar'] > 0) {
            $pdo->prepare("UPDATE stoklar SET miktar = miktar + ? WHERE id = ? AND user_id = ?")
                ->execute([$hRow['stok_miktar'], $hRow['stok_id'], $uid]);
        }
        $pdo->prepare("DELETE FROM tahsilatlar WHERE hareket_id=?")->execute([$hid]);
        $pdo->prepare("DELETE FROM hareketler WHERE id=?")->execute([$hid]);
        if (!empty($hRow['api_id'])) apiSync('delete', '/hareketler/' . $hRow['api_id']);
        header('Location: /cari?id=' . $id . '&msg=silindi'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ekle-tahsilat') {
    csrfKontrol();
    $hid      = (int)($_POST['hareket_id'] ?? 0);
    $tutar    = (float)str_replace(',', '.', $_POST['tutar'] ?? '0');
    $tarih    = trim($_POST['tarih'] ?? date('Y-m-d'));
    $aciklama = trim($_POST['aciklama'] ?? '');
    $hesapId  = (int)($_POST['kasa_hesap_id'] ?? 0) ?: null;
    $chk = $pdo->prepare("SELECT h.id, h.tutar FROM hareketler h JOIN cariler c ON c.id=h.cari_id WHERE h.id=? AND h.tip='borc' AND c.user_id=?");
    $chk->execute([$hid, $uid]);
    if ($chk->fetch() && $tutar > 0) {
        $pdo->prepare("INSERT INTO tahsilatlar (cari_id, hareket_id, tutar, tarih, aciklama) VALUES (?,?,?,?,?)")
            ->execute([$id, $hid, $tutar, $tarih, $aciklama ?: null]);
        // Kasaya giriş kaydı
        $kasaAciklama = ($aciklama ?: 'Cari Tahsilat') . ' — ' . $cari['ad'];
        $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, kaynak, kaynak_id, tarih) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$uid, $hesapId, 'giris', $tutar, $kasaAciklama, 'tahsilat', $id, $tarih]);
        // VPS'e gönder — hareket api_id ve kasa hesap api_id kullan
        $hApiRow = $pdo->prepare("SELECT api_id FROM hareketler WHERE id=?");
        $hApiRow->execute([$hid]);
        $hApiRow = $hApiRow->fetch();
        $khApiRow = $hesapId ? $pdo->prepare("SELECT api_id FROM kasa_hesaplari WHERE id=? AND user_id=?") : null;
        if ($khApiRow) { $khApiRow->execute([$hesapId, $uid]); $khApiRow = $khApiRow->fetch(); }
        if (!empty($hApiRow['api_id'])) {
            apiSync('post', '/tahsilat', [
                'cari_id'       => $cari['api_id'] ?? null,
                'hareket_id'    => $hApiRow['api_id'],
                'tutar'         => $tutar,
                'tarih'         => $tarih,
                'aciklama'      => $aciklama ?: null,
                'kasa_hesap_id' => $khApiRow['api_id'] ?? null,
            ]);
        }
        header('Location: /cari?id=' . $id . '&msg=tahsilat'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sil-tahsilat') {
    csrfKontrol();
    $tid = (int)($_POST['tahsilat_id'] ?? 0);
    $chk = $pdo->prepare("SELECT t.id FROM tahsilatlar t JOIN cariler c ON c.id=t.cari_id WHERE t.id=? AND c.user_id=?");
    $chk->execute([$tid, $uid]);
    if ($chk->fetch()) {
        $pdo->prepare("DELETE FROM tahsilatlar WHERE id=?")->execute([$tid]);
        header('Location: /cari?id=' . $id . '&msg=silindi'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ekle-hareket') {
    csrfKontrol();
    $tip         = $_POST['tip'] ?? '';
    $tutar       = (float)str_replace(',', '.', $_POST['tutar'] ?? '0');
    $aciklama    = trim($_POST['aciklama'] ?? '');
    $tarih       = trim($_POST['tarih'] ?? '');
    $vade        = trim($_POST['vade_tarihi'] ?? '') ?: null;
    $stok_id     = (int)($_POST['stok_id'] ?? 0) ?: null;
    $stok_miktar = $stok_id ? (float)str_replace(',', '.', $_POST['stok_miktar'] ?? '0') : null;
    $kasaHesapId = (int)($_POST['kasa_hesap_id'] ?? 0) ?: null;

    if (!in_array($tip, ['borc', 'alacak'])) { $error = 'Geçersiz tip.'; }
    elseif ($tutar <= 0)                       { $error = 'Tutar sıfırdan büyük olmalı.'; }
    elseif (!$tarih)                           { $error = 'Tarih zorunlu.'; }
    else {
        require_once __DIR__ . '/includes/sync.php';
        // Önce VPS'e yaz (cari api_id gerekli)
        $hApiId = null;
        if (!empty($cari['api_id'])) {
            $hRes = vpsYaz('post', '/hareketler', [
                'cari_id'     => (int)$cari['api_id'], 'tip' => $tip, 'tutar' => $tutar,
                'aciklama'    => $aciklama ?: null, 'tarih' => $tarih,
                'vade_tarihi' => $vade, 'stok_id' => $stok_id,
                'miktar'      => $stok_miktar, 'hesap_id' => $kasaHesapId,
            ]);
            $hApiId = $hRes['data']['id'] ?? $hRes['hareket']['id'] ?? null;
        }
        // Sonra SQLite'a kaydet
        $pdo->prepare("INSERT INTO hareketler (cari_id,tip,tutar,aciklama,tarih,vade_tarihi,stok_id,stok_miktar,api_id) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$id, $tip, $tutar, $aciklama ?: null, $tarih, $vade, $stok_id, $stok_miktar, $hApiId]);
        if ($stok_id && $stok_miktar > 0) {
            $pdo->prepare("UPDATE stoklar SET miktar = miktar - ? WHERE id = ? AND user_id = ?")
                ->execute([$stok_miktar, $stok_id, $uid]);
        }
        if ($tip === 'alacak') {
            $ka = ($aciklama ?: 'Nakit Satış') . ' — ' . $cari['ad'];
            $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, kaynak, kaynak_id, tarih) VALUES (?,?,'giris',?,?,'tahsilat',?,?)")
                ->execute([$uid, $kasaHesapId, $tutar, $ka, $id, $tarih]);
        }
        header('Location: /cari?id=' . $id . '&msg=eklendi'); exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ekle-sepet') {
    csrfKontrol();
    $tip    = $_POST['tip'] ?? '';
    $tarih  = trim($_POST['tarih'] ?? '');
    $vade   = trim($_POST['vade_tarihi'] ?? '') ?: null;
    $not    = trim($_POST['sepet_not'] ?? '');
    $kalemler = $_POST['kalemler'] ?? [];

    if (!in_array($tip, ['borc', 'alacak'])) { $error = 'Geçersiz tip.'; }
    elseif (!$tarih)                           { $error = 'Tarih zorunlu.'; }
    elseif (empty($kalemler))                  { $error = 'Sepet boş.'; }
    else {
        $pdo->beginTransaction();
        try {
            $grupId = 'g_' . uniqid();
            $toplamTutar = 0;
            $kalemRows = [];

            foreach ($kalemler as $k) {
                $stok_id     = (int)($k['stok_id'] ?? 0) ?: null;
                $stok_miktar = $stok_id ? (float)str_replace(',', '.', $k['miktar'] ?? '0') : null;
                $birimFiyat  = (float)str_replace(',', '.', $k['birim_fiyat'] ?? '0');
                $miktar      = (float)str_replace(',', '.', $k['miktar'] ?? '0');
                $kTutar      = (float)str_replace(',', '.', $k['tutar'] ?? '0');
                $aciklama    = trim($k['aciklama'] ?? '');

                if ($kTutar <= 0) continue;

                $pdo->prepare("INSERT INTO hareketler (cari_id,tip,tutar,aciklama,tarih,vade_tarihi,stok_id,stok_miktar,grup_id) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$id, $tip, $kTutar, $aciklama ?: null, $tarih, $vade, $stok_id, $stok_miktar ?: null, $grupId]);

                if ($stok_id && $stok_miktar > 0) {
                    $pdo->prepare("UPDATE stoklar SET miktar = miktar - ? WHERE id = ? AND user_id = ?")
                        ->execute([$stok_miktar, $stok_id, $uid]);
                }
                $toplamTutar += $kTutar;
                $kalemRows[] = ['aciklama' => $aciklama ?: null, 'tutar' => $kTutar, 'stok_id' => $stok_id, 'miktar' => $stok_miktar];
            }

            // Notlu özet satırı opsiyonel
            if ($not !== '' && count($kalemRows) > 0) {
                // Notu ilk satırın aciklama'sına ekle (zaten ayrı tutulabilir)
            }

            $pdo->commit();
            header('Location: /cari?id=' . $id . '&msg=eklendi'); exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Kayıt hatası: ' . $e->getMessage();
        }
    }
}

if (isset($_GET['msg'])) {
    $msgs = ['eklendi' => 'Hareket eklendi.', 'silindi' => 'Silindi.', 'tahsilat' => 'Tahsilat kaydedildi.'];
    $success = $msgs[$_GET['msg']] ?? '';
}

$hStmt = $pdo->prepare("SELECT h.*, s.ad AS stok_ad, s.birim AS stok_birim FROM hareketler h LEFT JOIN stoklar s ON s.id=h.stok_id WHERE h.cari_id=? ORDER BY h.tarih DESC, h.id DESC");
$hStmt->execute([$id]);
$hareketler = $hStmt->fetchAll();

// Her vadeli hareket için tahsilat toplamı
$tahsilatStmt = $pdo->prepare("SELECT hareket_id, id, tutar, tarih, aciklama FROM tahsilatlar WHERE cari_id=? ORDER BY tarih ASC, id ASC");
$tahsilatStmt->execute([$id]);
$tahsilatlar = $tahsilatStmt->fetchAll();
$tahsilatMap = []; // hareket_id => ['toplam' => x, 'kayitlar' => [...]]
foreach ($tahsilatlar as $t) {
    $tahsilatMap[$t['hareket_id']]['toplam'] = ($tahsilatMap[$t['hareket_id']]['toplam'] ?? 0) + $t['tutar'];
    $tahsilatMap[$t['hareket_id']]['kayitlar'][] = $t;
}

$bStmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN tip='alacak' THEN tutar ELSE 0 END),0) AS a,
    COALESCE(SUM(CASE WHEN tip='borc'   THEN tutar ELSE 0 END),0) AS b
    FROM hareketler WHERE cari_id=?");
$bStmt->execute([$id]);
$ozet = $bStmt->fetch();

$toplamTahsilat = array_sum(array_column($tahsilatlar, 'tutar'));
$toplamVadeli   = $ozet['b'];
$bakiye         = $toplamVadeli - $toplamTahsilat; // kalan vadeli borç

$bugun     = date('Y-m-d');
$vadeSayisi = 0;
foreach ($hareketler as $h) {
    if ($h['vade_tarihi'] && $h['vade_tarihi'] < $bugun && $h['tip'] === 'borc') $vadeSayisi++;
}

// Grup satırlarını özetlemek için: grup_id => [toplam, tarih, tip, vade, kalemler]
$grupMap = [];
foreach ($hareketler as $h) {
    if (!$h['grup_id']) continue;
    $g = $h['grup_id'];
    if (!isset($grupMap[$g])) $grupMap[$g] = ['toplam' => 0, 'tarih' => $h['tarih'], 'tip' => $h['tip'], 'vade' => $h['vade_tarihi'], 'kalemler' => []];
    $grupMap[$g]['toplam']    += $h['tutar'];
    $grupMap[$g]['kalemler'][] = $h;
}

$stoklar = $pdo->prepare("SELECT id, ad, birim, miktar, fiyat, alis_fiyat, nakit_fiyat, vadeli_fiyat, barkod FROM stoklar WHERE user_id=? ORDER BY ad ASC");
$stoklar->execute([$uid]);
$stoklar = $stoklar->fetchAll();

$kasaHesaplari = $pdo->prepare("SELECT id, ad, renk FROM kasa_hesaplari WHERE user_id=? AND aktif=1 ORDER BY id ASC");
$kasaHesaplari->execute([$uid]);
$kasaHesaplari = $kasaHesaplari->fetchAll();

$pageTitle = htmlspecialchars($cari['ad']);

$words = explode(' ', trim($cari['ad']));
$harf  = '';
foreach (array_slice($words, 0, 2) as $w) $harf .= mb_strtoupper(mb_substr($w, 0, 1, 'UTF-8'), 'UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($cari['ad']) ?> — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.print-header { display:none; }
.vade-gecmis  { color:var(--red);  font-size:.72rem; font-weight:700; display:inline-flex; align-items:center; gap:.25rem; }
.vade-yaklasan{ color:var(--gold); font-size:.72rem; font-weight:700; display:inline-flex; align-items:center; gap:.25rem; }
.vade-ok      { color:var(--green);font-size:.72rem; }
.tip-radio-lbl { display:flex; align-items:center; gap:.45rem; padding:.5rem .75rem; border:1.5px solid var(--border); border-radius:var(--r); cursor:pointer; font-size:.84rem; font-weight:600; transition:.12s; color:var(--ink2); }
.tip-radio-lbl input { display:none; }
.tip-radio-lbl.active-alacak { border-color:var(--green); background:var(--green-lt); color:var(--green); }
.tip-radio-lbl.active-borc   { border-color:var(--red);   background:var(--red-lt);   color:var(--red); }
.stok-ara-item:hover { background:var(--surface2); }
.stok-ara-item:last-child { border-bottom:none; }

/* Fiş yazdırma */
#fisKatmani { display:none; }
body.fis-mod #fisKatmani {
  display:block !important;
  position:fixed; top:0; left:0; width:80mm;
  font-family:"Courier New",monospace; font-size:12px;
  color:#000; background:#fff; padding:3mm; z-index:9999;
}
@media print {
  .titlebar,.sidebar,.page-header,.no-print,.app-shell,.main-content,.vrm-overlay { display:none !important; }
  #fisKatmani { display:block !important; position:static; width:80mm; font-family:"Courier New",monospace; font-size:12px; color:#000; background:#fff; padding:3mm; }
  @page { margin:0; size:80mm auto; }
}
</style>
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header no-print">
      <div class="page-header-left" style="display:flex;align-items:center;gap:.85rem">
        <div style="width:42px;height:42px;border-radius:11px;background:var(--green-lt);color:var(--green);display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-weight:900;font-size:.9rem;flex-shrink:0">
          <?= $harf ?>
        </div>
        <div>
          <a href="/cariler" class="breadcrumb">
            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            Cariler
          </a>
          <div class="page-title"><?= htmlspecialchars($cari['ad']) ?></div>
          <?php if ($cari['telefon']): ?><div class="page-sub"><?= htmlspecialchars($cari['telefon']) ?></div><?php endif; ?>
        </div>
      </div>
      <div class="page-header-right">
        <?php if ($cari['telefon']): ?>
        <a href="https://wa.me/90<?= preg_replace('/[^0-9]/', '', ltrim($cari['telefon'], '0')) ?>?text=<?= urlencode('Sayın ' . $cari['ad'] . ', bakiyeniz ₺' . number_format(abs($bakiye), 2, ',', '.') . ' tutarındadır.') ?>"
           target="_blank" class="btn btn-sm" style="background:#25D366;color:#fff">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M11.999 2C6.477 2 2 6.484 2 12.017c0 1.99.522 3.858 1.433 5.476L2 22l4.658-1.403A9.96 9.96 0 0 0 12 22c5.523 0 10-4.484 10-10.017C22 6.48 17.522 2 11.999 2z"/></svg>
          WhatsApp
        </a>
        <?php endif; ?>
        <a href="/cari-ekstre?id=<?= $id ?>" class="btn btn-primary btn-sm">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          Ekstre / Yazdır
        </a>
        <a href="/export-cari?id=<?= $id ?>" class="btn btn-secondary btn-sm">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          Excel
        </a>
        <a href="/cari-duzenle?id=<?= $id ?>" class="btn btn-secondary btn-sm">Düzenle</a>
        <form method="post" action="/cari-sil" style="display:inline" data-confirm-sil>
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <button type="submit" class="btn btn-sm" style="background:var(--red-lt);color:var(--red)">Sil</button>
        </form>
      </div>
    </div>

    <!-- Yazdırma başlığı -->
    <div class="print-header" style="padding:1rem 1.75rem">
      <strong style="font-size:1.2rem">veresi<span style="color:var(--green)">yem</span></strong>
      <div style="margin-top:.5rem"><strong><?= htmlspecialchars($cari['ad']) ?></strong><?php if ($cari['telefon']): ?> · <?= htmlspecialchars($cari['telefon']) ?><?php endif; ?></div>
      <div style="font-size:.8rem;color:#666;margin-top:.2rem">Yazdırma: <?= date('d.m.Y') ?></div>
    </div>

    <div class="page-body">

      <!-- Cari Bilgileri Paneli (toggle ile açılır) -->
      <div id="cariDetayPanel" style="margin-bottom:1rem">
        <div class="panel" style="padding:1rem 1.25rem">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem">
            <div style="font-weight:700;font-size:.88rem;color:var(--ink1)">Cari Bilgileri</div>
            <a href="/cari-duzenle?id=<?= $id ?>" class="btn btn-sm btn-ghost" style="font-size:.75rem">Düzenle</a>
          </div>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem 1.25rem">
            <?php
            $bilgiler = [
              ['Müşteri Kodu',  $cari['musteri_kodu'] ?? null,  'tag'],
              ['Telefon',       $cari['telefon'] ?? null,       'phone'],
              ['İl',            $cari['il'] ?? null,            'map'],
              ['İlçe',          $cari['ilce'] ?? null,          'map'],
              ['Vergi / TC No', $cari['vergi_tc'] ?? null,      'id'],
              ['Not',           $cari['not_'] ?? null,          'note'],
            ];
            foreach ($bilgiler as [$etiket, $deger, $ikon]):
              if (!$deger) continue;
            ?>
            <div style="min-width:0">
              <div style="font-size:.7rem;font-weight:600;color:var(--ink3);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.15rem"><?= $etiket ?></div>
              <div style="font-size:.85rem;color:var(--ink1);font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($deger) ?></div>
            </div>
            <?php endforeach; ?>
            <?php if ($cari['adres']): ?>
            <div style="grid-column:1/-1;min-width:0">
              <div style="font-size:.7rem;font-weight:600;color:var(--ink3);text-transform:uppercase;letter-spacing:.04em;margin-bottom:.15rem">Adres</div>
              <div style="font-size:.85rem;color:var(--ink1)"><?= htmlspecialchars($cari['adres']) ?></div>
            </div>
            <?php endif; ?>
          </div>
          <?php
          $doldurulanAlan = array_filter([$cari['musteri_kodu']??null,$cari['telefon']??null,$cari['il']??null,$cari['ilce']??null,$cari['vergi_tc']??null,$cari['not_']??null,$cari['adres']??null]);
          if (empty($doldurulanAlan)):
          ?>
          <div style="color:var(--ink3);font-size:.84rem;text-align:center;padding:.5rem 0">
            Henüz cari bilgisi eklenmemiş. <a href="/cari-duzenle?id=<?= $id ?>" style="color:var(--accent)">Düzenle</a>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($error):   ?><div class="alert alert-error"   style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert alert-success no-print" style="margin-bottom:1rem"><?= htmlspecialchars($success) ?></div><?php endif; ?>

      <?php if ($vadeSayisi > 0): ?>
      <div class="alert alert-error no-print" style="margin-bottom:1.25rem">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <strong><?= $vadeSayisi ?> hareketin vadesi geçmiş</strong> — tahsilat yapılması gerekiyor.
      </div>
      <?php endif; ?>

      <!-- Bakiye özet kartları -->
      <?php
        $cToplamSatis = $ozet['a'] + $ozet['b'];
        $cNakitYuzde  = $cToplamSatis > 0 ? round($ozet['a'] / $cToplamSatis * 100, 1) : 0;
        $cVadeliYuzde = $cToplamSatis > 0 ? round($ozet['b'] / $cToplamSatis * 100, 1) : 0;
      ?>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.9rem;margin-bottom:1.25rem">
        <div class="panel" style="padding:1rem 1.2rem">
          <div class="stat-label">Nakit Satış</div>
          <div class="stat-val" style="font-size:1.25rem;color:var(--green);margin:.25rem 0">₺<?= number_format($ozet['a'], 2, ',', '.') ?></div>
          <div class="stat-sub">peşin tahsilat</div>
        </div>
        <div class="panel" style="padding:1rem 1.2rem">
          <div class="stat-label">Vadeli Satış</div>
          <div class="stat-val" style="font-size:1.25rem;color:var(--blue);margin:.25rem 0">₺<?= number_format($ozet['b'], 2, ',', '.') ?></div>
          <div class="stat-sub">
            <?php if ($toplamTahsilat > 0): ?>
              <span style="color:var(--green)">₺<?= number_format($toplamTahsilat,2,',','.') ?> tahsil edildi</span>
            <?php else: ?>
              veresiye toplam
            <?php endif; ?>
          </div>
        </div>
        <div class="panel" style="padding:1rem 1.2rem">
          <div class="stat-label">Toplam Satış</div>
          <div class="stat-val" style="font-size:1.25rem;color:var(--gold);margin:.25rem 0">₺<?= number_format($cToplamSatis, 2, ',', '.') ?></div>
          <div style="margin-top:.35rem">
            <div style="display:flex;gap:.6rem;font-size:.7rem;margin-bottom:.3rem">
              <span style="color:var(--green);font-weight:600">%<?= $cNakitYuzde ?> Nakit</span>
              <span style="color:var(--blue);font-weight:600">%<?= $cVadeliYuzde ?> Vadeli</span>
            </div>
            <div style="height:4px;border-radius:3px;background:var(--surface2);overflow:hidden">
              <div style="height:100%;width:<?= $cNakitYuzde ?>%;background:var(--green);border-radius:3px"></div>
            </div>
          </div>
        </div>
        <div class="panel" style="padding:1rem 1.2rem;border-left:3px solid <?= $bakiye > 0 ? 'var(--red)' : ($bakiye == 0 ? 'var(--green)' : 'var(--border2)') ?>">
          <div class="stat-label">Kalan Vadeli</div>
          <div class="stat-val" style="font-size:1.25rem;color:<?= $bakiye > 0 ? 'var(--red)' : 'var(--green)' ?>;margin:.25rem 0">
            ₺<?= number_format(max(0, $bakiye), 2, ',', '.') ?>
          </div>
          <div class="stat-sub"><?= $bakiye <= 0 ? '✓ Tüm vadeli tahsil edildi' : 'tahsil bekliyor' ?></div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:1rem;align-items:start">

        <!-- Hareket Listesi -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--green)"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              Hareketler
            </div>
            <div style="display:flex;align-items:center;gap:.5rem">
              <span class="badge badge-gray"><?= count($hareketler) ?> kayıt</span>
              <button onclick="document.getElementById('hizliModal').classList.add('open')" class="btn btn-primary btn-sm no-print">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Hızlı Ekle
              </button>
            </div>
          </div>
          <?php if (empty($hareketler)): ?>
          <div class="empty-state">
            <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <h3>Henüz hareket yok</h3>
            <p>Sağdaki formdan kayıt ekleyin.</p>
          </div>
          <?php else: ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Tarih</th>
                <th>Tip</th>
                <th style="text-align:right">Tutar</th>
                <th>Açıklama</th>
                <th>Vade</th>
                <th class="no-print"></th>
              </tr>
            </thead>
            <tbody>
              <?php
              $gosterilmisGruplar = [];
              foreach ($hareketler as $h):
                // Grup satırı ise: ilk görünüşte grup başlığı göster, diğerlerini atla
                if ($h['grup_id']) {
                    if (isset($gosterilmisGruplar[$h['grup_id']])) continue;
                    $gosterilmisGruplar[$h['grup_id']] = true;
                    $g = $grupMap[$h['grup_id']];
                    // Grup için tahsilat: tüm kalem tahsilatlarını topla
                    $gTahsilToplam = 0;
                    foreach ($g['kalemler'] as $gk) {
                        $gTahsilToplam += $tahsilatMap[$gk['id']]['toplam'] ?? 0;
                    }
                    $gKalan = $g['tip'] === 'borc' ? max(0, $g['toplam'] - $gTahsilToplam) : null;
                    $gTamTahsil = $g['tip'] === 'borc' && $gKalan <= 0;
                    $gVadeClass = $gVadeLabel = '';
                    if ($g['vade'] && $g['tip'] === 'borc' && !$gTamTahsil) {
                        $vg = (int)((strtotime($g['vade']) - strtotime($bugun)) / 86400);
                        if ($vg < 0)      { $gVadeClass = 'vade-gecmis';   $gVadeLabel = abs($vg) . ' gün geçti'; }
                        elseif ($vg <= 7) { $gVadeClass = 'vade-yaklasan'; $gVadeLabel = $vg === 0 ? 'Bugün!' : $vg . ' gün kaldı'; }
                        else              { $gVadeClass = 'vade-ok';        $gVadeLabel = date('d.m', strtotime($g['vade'])); }
                    }
                    // Grup için tüm kalemlerden fiş data oluştur
                    $gFisKalemler = [];
                    foreach ($g['kalemler'] as $gk) {
                        $gFisKalemler[] = [
                            'aciklama'    => $gk['aciklama'] ?? '',
                            'stok'        => $gk['stok_ad'] ?? '',
                            'stok_miktar' => $gk['stok_miktar'] !== null ? (float)$gk['stok_miktar'] : null,
                            'stok_birim'  => $gk['stok_birim'] ?? '',
                            'tutar'       => (float)$gk['tutar'],
                        ];
                    }
                    $gFisData = htmlspecialchars(json_encode([
                        'tip'       => $g['tip'],
                        'tarih'     => $g['tarih'],
                        'vade'      => $g['vade'],
                        'toplam'    => $g['toplam'],
                        'kalemler'  => $gFisKalemler,
                        'cari'      => $cari['ad'],
                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                    // Grup silme: tüm kalemleri sil
                    $gKalemIds = array_column($g['kalemler'], 'id');
              ?>
              <tr style="<?= $gTamTahsil ? 'opacity:.6' : '' ?>;background:var(--surface2)">
                <td class="text-muted" style="font-size:.78rem;white-space:nowrap"><?= date('d.m.Y', strtotime($g['tarih'])) ?></td>
                <td>
                  <span class="badge <?= $g['tip'] === 'alacak' ? 'badge-green' : 'badge-blue' ?>"><?= $g['tip'] === 'alacak' ? 'Nakit Satış' : 'Vadeli Satış' ?></span>
                  <span class="badge badge-gray" style="margin-left:.2rem"><?= count($g['kalemler']) ?> kalem</span>
                  <?php if ($gTamTahsil): ?>
                    <span class="badge badge-green" style="margin-left:.2rem">✓ Tahsil</span>
                  <?php elseif ($g['tip'] === 'borc' && $gTahsilToplam > 0): ?>
                    <span class="badge badge-gold" style="margin-left:.2rem">Kısmi</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;white-space:nowrap" class="fw-bold">
                  <?php if ($g['tip'] === 'alacak'): ?>
                    <span class="text-green">+₺<?= number_format($g['toplam'], 2, ',', '.') ?></span>
                  <?php else: ?>
                    <span class="text-blue">₺<?= number_format($g['toplam'], 2, ',', '.') ?></span>
                    <?php if ($gTahsilToplam > 0 && !$gTamTahsil): ?>
                      <div style="font-size:.7rem;color:var(--ink3);font-weight:400">Kalan: ₺<?= number_format($gKalan, 2, ',', '.') ?></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td style="font-size:.82rem;min-width:260px;color:var(--ink2)">
                  <?php foreach ($g['kalemler'] as $gk): ?>
                    <div style="display:flex;gap:.3rem;align-items:baseline">
                      <span style="color:var(--ink3);font-size:.72rem">·</span>
                      <span><?= $gk['stok_ad'] ? htmlspecialchars($gk['stok_ad']) : htmlspecialchars($gk['aciklama'] ?? '—') ?></span>
                      <?php if ($gk['stok_miktar']): ?><span class="text-muted" style="font-size:.72rem"><?= number_format($gk['stok_miktar'],2,',','.') ?> <?= htmlspecialchars($gk['stok_birim']??'') ?></span><?php endif; ?>
                      <span style="margin-left:auto;font-weight:600;color:var(--ink2)">₺<?= number_format($gk['tutar'],2,',','.') ?></span>
                    </div>
                  <?php endforeach; ?>
                  <?php if ($g['tip'] === 'borc'): ?>
                    <?php foreach ($g['kalemler'] as $gk):
                      $gkTahsilat = $tahsilatMap[$gk['id']] ?? ['toplam' => 0, 'kayitlar' => []];
                      foreach ($gkTahsilat['kayitlar'] as $tk): ?>
                        <div style="font-size:.72rem;color:var(--green);margin-top:.2rem">
                          ↳ <?= date('d.m.Y', strtotime($tk['tarih'])) ?> ₺<?= number_format($tk['tutar'],2,',','.') ?> tahsil
                          <?= $tk['aciklama'] ? '· ' . htmlspecialchars($tk['aciklama']) : '' ?>
                          <form method="post" action="/cari?id=<?= $id ?>" style="display:inline" data-confirm-hareket>
                            <input type="hidden" name="csrf" value="<?= csrf() ?>">
                            <input type="hidden" name="action" value="sil-tahsilat">
                            <input type="hidden" name="tahsilat_id" value="<?= $tk['id'] ?>">
                            <button type="submit" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:.68rem;padding:0 .2rem" title="Sil">✕</button>
                          </form>
                        </div>
                      <?php endforeach; endforeach; ?>
                  <?php endif; ?>
                </td>
                <td style="white-space:nowrap;text-align:right">
                  <?php if ($gVadeLabel): ?><span class="<?= $gVadeClass ?>"><?= $gVadeLabel ?></span><?php endif; ?>
                  <?php if ($g['tip'] === 'borc' && !$gTamTahsil): ?>
                    <button class="btn btn-sm no-print" style="background:var(--green-lt);color:var(--green);border:1px solid rgba(22,163,74,.2);margin-left:.25rem"
                      onclick="tahsilModal(<?= $gk['id'] ?>, <?= $g['toplam'] ?>, <?= $gKalan ?>, '')">Tahsilat</button>
                  <?php endif; ?>
                </td>
                <td style="text-align:center;white-space:nowrap" class="no-print">
                  <button class="btn btn-sm" style="background:var(--blue-lt);color:var(--blue);border:1px solid rgba(37,99,235,.2)"
                    data-fis="<?= $gFisData ?>"
                    onclick="sepetFisYazdir(JSON.parse(this.dataset.fis))" title="Fiş Yazdır">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Fiş
                  </button>
                  <?php foreach ($gKalemIds as $kid): ?>
                  <form method="post" action="/cari?id=<?= $id ?>" style="display:none" data-confirm-hareket class="grup-sil-form">
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="sil-hareket">
                    <input type="hidden" name="hareket_id" value="<?= $kid ?>">
                  </form>
                  <?php endforeach; ?>
                  <button type="button" class="btn-icon no-print" title="Grubu Sil"
                    onclick="grupSil(this)">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                  </button>
                </td>
              </tr>
              <?php
                    continue;
                } // end grup

                $hTahsilat     = $tahsilatMap[$h['id']] ?? ['toplam' => 0, 'kayitlar' => []];
                $hTahsilToplam = $hTahsilat['toplam'];
                $hKalan       = $h['tip'] === 'borc' ? max(0, $h['tutar'] - $hTahsilToplam) : null;
                $hTamTahsil   = $h['tip'] === 'borc' && $hKalan <= 0;
                $vadeClass = $vadeLabel = '';
                if ($h['vade_tarihi'] && $h['tip'] === 'borc' && !$hTamTahsil) {
                    $vg = (int)((strtotime($h['vade_tarihi']) - strtotime($bugun)) / 86400);
                    if ($vg < 0)     { $vadeClass = 'vade-gecmis';   $vadeLabel = abs($vg) . ' gün geçti'; }
                    elseif ($vg <= 7){ $vadeClass = 'vade-yaklasan'; $vadeLabel = $vg === 0 ? 'Bugün!' : $vg . ' gün kaldı'; }
                    else             { $vadeClass = 'vade-ok';        $vadeLabel = date('d.m', strtotime($h['vade_tarihi'])); }
                }
              ?>
              <tr style="<?= $hTamTahsil ? 'opacity:.6' : '' ?>">
                <td class="text-muted" style="font-size:.78rem;white-space:nowrap"><?= date('d.m.Y', strtotime($h['tarih'])) ?></td>
                <td>
                  <span class="badge <?= $h['tip'] === 'alacak' ? 'badge-green' : 'badge-blue' ?>"><?= $h['tip'] === 'alacak' ? 'Nakit Satış' : 'Vadeli Satış' ?></span>
                  <?php if ($hTamTahsil): ?>
                    <span class="badge badge-green" style="margin-left:.25rem">✓ Tahsil</span>
                  <?php elseif ($h['tip'] === 'borc' && $hTahsilToplam > 0): ?>
                    <span class="badge badge-gold" style="margin-left:.25rem">Kısmi</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:right;white-space:nowrap" class="fw-bold">
                  <?php if ($h['tip'] === 'alacak'): ?>
                    <span class="text-green">+₺<?= number_format($h['tutar'], 2, ',', '.') ?></span>
                  <?php else: ?>
                    <span class="text-blue">₺<?= number_format($h['tutar'], 2, ',', '.') ?></span>
                    <?php if ($hTahsilToplam > 0 && !$hTamTahsil): ?>
                      <div style="font-size:.7rem;color:var(--ink3);font-weight:400">Kalan: ₺<?= number_format($hKalan, 2, ',', '.') ?></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td style="font-size:.82rem;min-width:260px;color:var(--ink2)">
                  <?= htmlspecialchars($h['aciklama'] ?? '') ?>
                  <?php if ($h['stok_ad']): ?>
                    <span class="text-muted"> · <?= htmlspecialchars($h['stok_ad']) ?><?php if ($h['stok_miktar']): ?> <?= number_format($h['stok_miktar'],2,',','.') ?> <?= htmlspecialchars($h['stok_birim']??'') ?><?php endif; ?></span>
                  <?php endif; ?>
                  <?php foreach ($hTahsilat['kayitlar'] as $tk): ?>
                    <div style="font-size:.72rem;color:var(--green);margin-top:.2rem">
                      ↳ <?= date('d.m.Y', strtotime($tk['tarih'])) ?> ₺<?= number_format($tk['tutar'],2,',','.') ?> tahsil
                      <?= $tk['aciklama'] ? '· ' . htmlspecialchars($tk['aciklama']) : '' ?>
                      <form method="post" action="/cari?id=<?= $id ?>" style="display:inline" data-confirm-hareket>
                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                        <input type="hidden" name="action" value="sil-tahsilat">
                        <input type="hidden" name="tahsilat_id" value="<?= $tk['id'] ?>">
                        <button type="submit" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:.68rem;padding:0 .2rem" title="Sil">✕</button>
                      </form>
                    </div>
                  <?php endforeach; ?>
                </td>
                <td style="white-space:nowrap">
                  <?php if ($vadeLabel): ?>
                    <span class="<?= $vadeClass ?>">
                      <?php if ($vadeClass === 'vade-gecmis'): ?><svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg><?php endif; ?>
                      <?= $vadeLabel ?>
                    </span>
                  <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td class="no-print" style="white-space:nowrap">
                  <?php if ($h['tip'] === 'borc' && !$hTamTahsil): ?>
                    <button type="button" class="btn btn-sm" style="background:var(--green-lt);color:var(--green);margin-right:.3rem"
                      onclick="tahsilModal(<?= $h['id'] ?>, <?= $h['tutar'] ?>, <?= $hKalan ?>, '<?= htmlspecialchars(addslashes($h['aciklama'] ?? ''), ENT_QUOTES) ?>')">
                      Tahsil Et
                    </button>
                  <?php endif; ?>
                  <?php $fisData = htmlspecialchars(json_encode([
                      'tip'         => $h['tip'],
                      'tutar'       => (float)$h['tutar'],
                      'tarih'       => $h['tarih'],
                      'vade_tarihi' => $h['vade_tarihi'] ?? '',
                      'aciklama'    => $h['aciklama'] ?? '',
                      'stok'        => $h['stok_ad'] ?? '',
                      'stok_miktar' => $h['stok_miktar'] !== null ? (float)$h['stok_miktar'] : null,
                      'stok_birim'  => $h['stok_birim'] ?? '',
                      'cari'        => $cari['ad'],
                      'telefon'     => $cari['telefon'] ?? '',
                      'tahsil'      => (float)$hTahsilToplam,
                      'kalan'       => (float)($hKalan ?? 0),
                  ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>
                  <button type="button" class="btn btn-sm" style="margin-right:.3rem;background:var(--blue-lt);color:var(--blue);border:1px solid rgba(37,99,235,.2)"
                    data-fis="<?= $fisData ?>"
                    onclick="fisYazdir(JSON.parse(this.dataset.fis))" title="Fiş Yazdır">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Fiş
                  </button>
                  <form method="post" action="/cari?id=<?= $id ?>" style="display:inline" data-confirm-hareket>
                    <input type="hidden" name="csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="action" value="sil-hareket">
                    <input type="hidden" name="hareket_id" value="<?= $h['id'] ?>">
                    <button type="submit" class="btn-icon" title="Sil">
                      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>

        <!-- Yeni Hareket Formu -->
        <div class="panel no-print" style="min-width:0">
          <div class="panel-head">
            <div class="panel-title">Yeni Satış</div>
          </div>

          <div style="padding:1rem">
            <form method="post" action="/cari?id=<?= $id ?>" id="hareketForm">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="ekle-hareket">

              <div class="form-group">
                <label class="form-label">İşlem Tipi</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                  <label class="tip-radio-lbl active-alacak" id="lbl-alacak2">
                    <input type="radio" name="tip" value="alacak" checked onchange="tipDeg2(this)"> <span>↑ Nakit Satış</span>
                  </label>
                  <label class="tip-radio-lbl" id="lbl-borc2">
                    <input type="radio" name="tip" value="borc" onchange="tipDeg2(this)"> <span>↓ Vadeli Satış</span>
                  </label>
                </div>
              </div>

              <?php if (!empty($stoklar)): ?>
              <div class="form-group" style="position:relative">
                <label class="form-label">Stok <span class="text-muted">(opt.)</span></label>
                <input type="text" id="stok_ara" class="form-input" placeholder="Stok adı yazın..." autocomplete="off"
                  oninput="this.value.trim()==='' ? stokSifirla() : stokAra(this.value)"
                  onfocus="stokAra(this.value)">
                <div id="stok_liste" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:200;background:var(--surface);border:1px solid var(--border2);border-radius:var(--r);box-shadow:var(--shadow);max-height:210px;overflow-y:auto;margin-top:2px"></div>
                <input type="hidden" name="stok_id" id="stok_select_val">
                <!-- Gizli select — stokDeg() uyumluluğu için veri taşır -->
                <select id="stok_select" style="display:none">
                  <option value=""></option>
                  <?php foreach ($stoklar as $s): ?>
                    <option value="<?= $s['id'] ?>" data-fiyat="<?= (float)($s['alis_fiyat'] ?? $s['fiyat'] ?? 0) ?>" data-nakit="<?= (float)($s['nakit_fiyat'] ?? 0) ?>" data-vadeli="<?= (float)($s['vadeli_fiyat'] ?? 0) ?>" data-birim="<?= htmlspecialchars($s['birim']) ?>" data-miktar="<?= (float)$s['miktar'] ?>" data-barkod="<?= htmlspecialchars($s['barkod'] ?? '') ?>"><?= htmlspecialchars($s['ad']) ?> (<?= htmlspecialchars($s['birim']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group" id="stok_miktar_wrap" style="display:none">
                <label class="form-label" id="stok_miktar_lbl">Miktar</label>
                <input type="number" name="stok_miktar" id="stok_miktar" class="form-input" step="0.01" min="0.01" placeholder="0" oninput="hesaplaStokTutar()">
              </div>
              <div id="stok_fiyat_bilgi" style="display:none;margin:-0.5rem 0 .75rem;padding:.45rem .75rem;background:var(--surface2);border-radius:var(--r);font-size:.82rem;color:var(--ink2)"></div>
              <?php endif; ?>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                <div class="form-group">
                  <label class="form-label">Tarih</label>
                  <input type="date" name="tarih" class="form-input" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Vade <span class="text-muted">(opt.)</span></label>
                  <input type="date" name="vade_tarihi" class="form-input">
                </div>
              </div>

              <div class="form-group">
                <label class="form-label">Tutar (₺)</label>
                <input type="number" name="tutar" class="form-input" id="tutarInput" step="0.01" min="0.01" required placeholder="0,00" style="font-size:1.05rem;font-weight:700;text-align:right">
              </div>

              <div class="form-group">
                <label class="form-label">Açıklama <span class="text-muted">(opt.)</span></label>
                <input type="text" name="aciklama" class="form-input" placeholder="Not ekleyin...">
              </div>

              <?php if (!empty($kasaHesaplari)): ?>
              <div class="form-group" id="kasaSeciciSatis" style="display:none">
                <label class="form-label">Kasa Hesabı</label>
                <select name="kasa_hesap_id" class="form-input">
                  <option value="">— Hesap seç —</option>
                  <?php foreach ($kasaHesaplari as $kh): ?>
                  <option value="<?= $kh['id'] ?>"><?= htmlspecialchars($kh['ad']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>

              <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center">Kaydet</button>
            </form>
          </div>

        </div>

      </div>
    </div>
  </main>
</div>

<!-- Hızlı Modal -->
<div class="vrm-overlay no-print" id="hizliModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="vrm-dialog" style="max-width:380px">
    <div style="padding:1.1rem 1.5rem .75rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <div style="font-family:var(--font-display);font-weight:800;font-size:.95rem">Hızlı Hareket Ekle</div>
      <button class="btn-icon" onclick="document.getElementById('hizliModal').classList.remove('open')">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div style="padding:1.25rem 1.5rem">
      <form method="post" action="/cari?id=<?= $id ?>" id="hizliForm">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="ekle-hareket">
        <input type="hidden" name="tarih" value="<?= date('Y-m-d') ?>">
        <div class="form-group">
          <label class="form-label">İşlem Tipi</label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
            <label class="tip-radio-lbl active-alacak" id="lbl-h-alacak">
              <input type="radio" name="tip" value="alacak" checked onchange="tipDegH(this)"> <span>↑ Nakit Satış</span>
            </label>
            <label class="tip-radio-lbl" id="lbl-h-borc">
              <input type="radio" name="tip" value="borc" onchange="tipDegH(this)"> <span>↓ Vadeli Satış</span>
            </label>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Tutar (₺)</label>
          <input type="number" name="tutar" class="form-input" step="0.01" min="0.01" required placeholder="0,00" autofocus style="font-size:1.1rem;font-weight:700;text-align:right">
        </div>
        <div class="form-group">
          <label class="form-label">Açıklama <span class="text-muted">(opt.)</span></label>
          <input type="text" name="aciklama" class="form-input" placeholder="Not...">
        </div>
        <?php if (!empty($kasaHesaplari)): ?>
        <div class="form-group" id="kasaSeciciHizli" style="display:none">
          <label class="form-label">Kasa Hesabı</label>
          <select name="kasa_hesap_id" class="form-input">
            <option value="">— Hesap seç —</option>
            <?php foreach ($kasaHesaplari as $kh): ?>
            <option value="<?= $kh['id'] ?>"><?= htmlspecialchars($kh['ad']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
      </form>
    </div>
    <div class="vrm-dialog-foot">
      <button class="btn btn-ghost" onclick="document.getElementById('hizliModal').classList.remove('open')">İptal</button>
      <button class="btn btn-primary" onclick="document.getElementById('hizliForm').submit()">Kaydet</button>
    </div>
  </div>
</div>

<!-- Tahsilat Modalı -->
<div class="vrm-overlay no-print" id="tahsilatModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="vrm-dialog" style="max-width:360px">
    <div style="padding:1.1rem 1.5rem .75rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <div style="font-family:var(--font-display);font-weight:800;font-size:.95rem;color:var(--green)">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="vertical-align:-.15em"><polyline points="20 6 9 17 4 12"/></svg>
        Tahsilat Ekle
      </div>
      <button class="btn-icon" onclick="document.getElementById('tahsilatModal').classList.remove('open')">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div style="padding:1.25rem 1.5rem">
      <div id="tahsilat_ozet" style="background:var(--surface2);border-radius:var(--r);padding:.65rem .85rem;margin-bottom:1rem;font-size:.82rem">
        <div style="display:flex;justify-content:space-between">
          <span class="text-muted">Vadeli Satış:</span>
          <strong id="tm_toplam">—</strong>
        </div>
        <div style="display:flex;justify-content:space-between;margin-top:.25rem">
          <span class="text-muted">Kalan Borç:</span>
          <strong id="tm_kalan" style="color:var(--red)">—</strong>
        </div>
      </div>
      <form method="post" action="/cari?id=<?= $id ?>" id="tahsilatForm">
        <input type="hidden" name="csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" value="ekle-tahsilat">
        <input type="hidden" name="hareket_id" id="tm_hareket_id" value="">
        <div class="form-group">
          <label class="form-label">Alınan Tutar (₺)</label>
          <input type="number" name="tutar" id="tm_tutar" class="form-input" step="0.01" min="0.01" required placeholder="0,00" autofocus style="font-size:1.1rem;font-weight:700;text-align:right">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
          <div class="form-group">
            <label class="form-label">Tarih</label>
            <input type="date" name="tarih" class="form-input" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">Not <span class="text-muted">(opt.)</span></label>
            <input type="text" name="aciklama" class="form-input" placeholder="Nakit, havale...">
          </div>
        </div>
        <?php if (!empty($kasaHesaplari)): ?>
        <div class="form-group">
          <label class="form-label">Kasa Hesabı</label>
          <select name="kasa_hesap_id" class="form-input">
            <option value="">— Hesap seçin —</option>
            <?php foreach ($kasaHesaplari as $kh): ?>
            <option value="<?= $kh['id'] ?>"><?= htmlspecialchars($kh['ad']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:.5rem;margin-top:.25rem">
          <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('tm_tutar').value=document.getElementById('tm_kalan').dataset.val">Tamamını Al</button>
        </div>
      </form>
    </div>
    <div class="vrm-dialog-foot">
      <button class="btn btn-ghost" onclick="document.getElementById('tahsilatModal').classList.remove('open')">İptal</button>
      <button class="btn btn-primary" style="background:var(--green)" onclick="document.getElementById('tahsilatForm').submit()">Kaydet</button>
    </div>
  </div>
</div>

<script>

function secilenTip() {
  var r = document.querySelector('input[name="tip"]:checked');
  return r ? r.value : 'alacak';
}
function tipDeg2(r) {
  document.getElementById('lbl-alacak2').className = 'tip-radio-lbl' + (r.value==='alacak' ? ' active-alacak' : '');
  document.getElementById('lbl-borc2').className   = 'tip-radio-lbl' + (r.value==='borc'   ? ' active-borc'   : '');
  var ks = document.getElementById('kasaSeciciSatis');
  if (ks) ks.style.display = r.value==='alacak' ? 'block' : 'none';
  guncelleFiyatBilgi();
}
function tipDegH(r) {
  document.getElementById('lbl-h-alacak').className = 'tip-radio-lbl' + (r.value==='alacak' ? ' active-alacak' : '');
  document.getElementById('lbl-h-borc').className   = 'tip-radio-lbl' + (r.value==='borc'   ? ' active-borc'   : '');
  var kh = document.getElementById('kasaSeciciHizli');
  if (kh) kh.style.display = r.value==='alacak' ? 'block' : 'none';
}
// Sayfa yüklenince nakit seçili olduğu için kasa seçiciyi göster
(function(){
  var ks = document.getElementById('kasaSeciciSatis');
  if (ks) ks.style.display = 'block';
  var kh = document.getElementById('kasaSeciciHizli');
  if (kh) kh.style.display = 'block';
})();
var _firmaAdi = <?= json_encode($firmaAdi, JSON_UNESCAPED_UNICODE) ?>;
function fisYazdir(d) {
  var normalized = {
    tip:        d.tip,
    cari_ad:    d.cari || d.cari_ad || '',
    tarih:      d.tarih,
    vade:       d.vade_tarihi || d.vade || null,
    toplam:     d.tutar,
    kalan_borc: _cariKalanBorc,
    kalemler: d.stok ? [{
      aciklama: d.stok + (d.aciklama ? ' - ' + d.aciklama : ''),
      tutar:    d.tutar,
      miktar:   d.stok_miktar || 1
    }] : (d.kalemler || [])
  };
  veresiyemFisYazdir(normalized);
}
function tahsilModal(hid, toplam, kalan, aciklama) {
  document.getElementById('tm_hareket_id').value = hid;
  document.getElementById('tm_tutar').value = '';
  document.getElementById('tm_toplam').textContent = '₺' + toplam.toFixed(2).replace('.', ',');
  var kalanEl = document.getElementById('tm_kalan');
  kalanEl.textContent = '₺' + kalan.toFixed(2).replace('.', ',');
  kalanEl.dataset.val = kalan.toFixed(2);
  document.getElementById('tahsilatModal').classList.add('open');
  setTimeout(function(){ document.getElementById('tm_tutar').focus(); }, 100);
}
function fmt(v) {
  var n = parseFloat(v);
  return (n > 0) ? '₺' + n.toFixed(2).replace('.', ',') : '<span style="color:var(--ink3)">—</span>';
}
function guncelleFiyatBilgi() {
  var sel = document.getElementById('stok_select');
  var bilgiEl = document.getElementById('stok_fiyat_bilgi');
  if (!sel || !bilgiEl) return;
  if (!sel.value) { bilgiEl.style.display = 'none'; return; }
  var opt = sel.options[sel.selectedIndex];
  var tip = secilenTip();
  var alisFiyat  = parseFloat(opt.dataset.fiyat)  || 0;
  var nakitFiyat = parseFloat(opt.dataset.nakit)   || 0;
  var vadeliFiyat= parseFloat(opt.dataset.vadeli)  || 0;
  var secilenFiyat = tip === 'alacak' ? nakitFiyat : vadeliFiyat;
  var secilenEtiket = tip === 'alacak' ? 'Nakit Satış' : 'Vadeli Satış';
  var stokId = sel.value;
  var html;
  if (alisFiyat === 0 && nakitFiyat === 0 && vadeliFiyat === 0) {
    html = '<span style="color:var(--gold)">⚠ Bu stoka fiyat girilmemiş.</span> '
      + '<a href="/stok-duzenle?id=' + stokId + '" style="color:var(--accent);text-decoration:underline;font-size:.78rem">Fiyat ekle →</a>';
  } else {
  var stokMiktar = parseFloat(opt.dataset.miktar) || 0;
  var birim = opt.dataset.birim || 'adet';
  var miktarRenk = stokMiktar <= 0 ? 'color:var(--red)' : (stokMiktar <= 5 ? 'color:var(--gold)' : 'color:var(--green)');
    html = '<div style="display:flex;gap:1.25rem;flex-wrap:wrap;align-items:center">'
      + '<span><span style="color:var(--ink3);font-size:.78rem">Alış Fiyatı:</span> ' + fmt(alisFiyat) + '</span>'
      + '<span><span style="color:var(--ink3);font-size:.78rem">' + secilenEtiket + ':</span> <strong>' + fmt(secilenFiyat) + '</strong></span>'
      + '<span style="margin-left:auto"><span style="color:var(--ink3);font-size:.78rem">Stok:</span> <strong style="' + miktarRenk + '">' + stokMiktar.toLocaleString('tr-TR') + ' ' + birim + '</strong></span>'
      + '</div>';
  }
  bilgiEl.innerHTML = html;
  bilgiEl.style.display = 'block';
  hesaplaStokTutar();
}
function hesaplaStokTutar() {
  var sel = document.getElementById('stok_select');
  var miktarEl = document.getElementById('stok_miktar');
  if (!sel || !miktarEl || !sel.value) return;
  var opt = sel.options[sel.selectedIndex];
  var tip = secilenTip();
  var fiyat = tip === 'alacak' ? (parseFloat(opt.dataset.nakit) || 0) : (parseFloat(opt.dataset.vadeli) || 0);
  if (!fiyat) fiyat = parseFloat(opt.dataset.fiyat) || 0;
  var miktar = parseFloat(miktarEl.value) || 0;
  if (fiyat > 0 && miktar > 0) {
    document.getElementById('tutarInput').value = (miktar * fiyat).toFixed(2);
  }
}
function stokAra(q) {
  var liste = document.getElementById('stok_liste');
  var sel   = document.getElementById('stok_select');
  if (!liste || !sel) return;
  var opts   = Array.from(sel.options).filter(function(o){ return o.value; });
  var araStr = q.trim().toLowerCase();
  var eslesen = araStr === '' ? opts : opts.filter(function(o){
    return o.text.toLowerCase().indexOf(araStr) !== -1
        || (o.dataset.barkod && o.dataset.barkod.toLowerCase().indexOf(araStr) !== -1);
  });
  liste.innerHTML = '';
  if (eslesen.length === 0) { liste.style.display = 'none'; return; }
  eslesen.forEach(function(o) {
    var miktar = parseFloat(o.dataset.miktar) || 0;
    var birim  = o.dataset.birim || 'adet';
    var mRenk  = miktar <= 0 ? 'var(--red)' : (miktar <= 5 ? 'var(--gold)' : 'var(--green)');
    var item = document.createElement('div');
    item.className = 'stok-ara-item';
    item.style.cssText = 'padding:.5rem .75rem;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-size:.84rem;border-bottom:1px solid var(--border)';
    item.innerHTML = '<span>' + o.text + '</span>'
      + '<span style="font-size:.75rem;color:' + mRenk + ';font-weight:600">' + miktar.toLocaleString('tr-TR') + ' ' + birim + '</span>';
    item.dataset.val  = o.value;
    item.dataset.text = o.text;
    item.addEventListener('mousedown', function(e) {
      e.preventDefault();
      stokSec(this.dataset.val, this.dataset.text);
    });
    liste.appendChild(item);
  });
  liste.style.display = 'block';
}
function stokSec(val, text) {
  var sel      = document.getElementById('stok_select');
  var araInput = document.getElementById('stok_ara');
  var valInput = document.getElementById('stok_select_val');
  for (var i = 0; i < sel.options.length; i++) {
    if (sel.options[i].value == val) { sel.selectedIndex = i; break; }
  }
  if (araInput) araInput.value = text.replace(/\s*\([^)]*\)\s*$/, '');
  if (valInput) valInput.value = val;
  stokListeGizle();
  stokDeg(sel);
}
function stokListeGizle() {
  var liste = document.getElementById('stok_liste');
  if (liste) liste.style.display = 'none';
}
// Stok listesi dışına tıklayınca kapat
document.addEventListener('click', function(e) {
  var ara   = document.getElementById('stok_ara');
  var liste = document.getElementById('stok_liste');
  if (!ara || !liste) return;
  if (!ara.contains(e.target) && !liste.contains(e.target)) {
    liste.style.display = 'none';
  }
});
function stokSifirla() {
  var sel      = document.getElementById('stok_select');
  var araInput = document.getElementById('stok_ara');
  var valInput = document.getElementById('stok_select_val');
  if (sel)      sel.selectedIndex = 0;
  if (araInput) araInput.value = '';
  if (valInput) valInput.value = '';
  stokListeGizle();
  if (sel) stokDeg(sel);
}
function stokDeg(sel) {
  var wrap = document.getElementById('stok_miktar_wrap');
  if (!wrap) return;
  if (sel.value) {
    var opt = sel.options[sel.selectedIndex];
    document.getElementById('stok_miktar_lbl').textContent = 'Miktar (' + (opt.dataset.birim || 'adet') + ')';
    document.getElementById('stok_miktar').value = 1;
    wrap.style.display = 'block';
    guncelleFiyatBilgi();
  } else {
    wrap.style.display = 'none';
    var bilgiEl = document.getElementById('stok_fiyat_bilgi');
    if (bilgiEl) bilgiEl.style.display = 'none';
  }
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.getElementById('hizliModal').classList.remove('open');
    document.getElementById('tahsilatModal').classList.remove('open');
  }
});

// ── GRUP SİL ───────────────────────────────────────────────
function grupSil(btn) {
  var row   = btn.closest('tr');
  var forms = row.querySelectorAll('.grup-sil-form');
  if (!forms.length) return;
  veresiyemConfirm('Bu satışın tüm kalemleri silinecek. Emin misiniz?').then(function(ok) {
    if (!ok) return;
    forms.forEach(function(f) { f.submit(); });
  });
}

// ── GRUP FİŞ ───────────────────────────────────────────────
var _firmaAdi = <?= json_encode($firmaAdi, JSON_UNESCAPED_UNICODE) ?>;

var _cariKalanBorc = <?= json_encode(max(0, (float)$bakiye), JSON_UNESCAPED_UNICODE) ?>;

function sepetFisYazdir(d) {
  // cari.php veri formatını veresiyemFisYazdir formatına normalize et
  var normalized = {
    tip:        d.tip,
    cari_ad:    d.cari || d.cari_ad || '',
    tarih:      d.tarih,
    vade:       d.vade,
    toplam:     d.toplam,
    kalan_borc: _cariKalanBorc,
    kalemler: (d.kalemler || []).map(function(k) {
      return {
        aciklama: k.stok || k.aciklama || '',
        tutar:    k.tutar,
        miktar:   k.stok_miktar
      };
    })
  };
  veresiyemFisYazdir(normalized);
}

</script>
<div id="fisKatmani"></div>
</body>
</html>