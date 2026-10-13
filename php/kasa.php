<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/sync.php';
girisGerekli();
$activePage = 'kasa';
$uid = userId();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    syncKasaHesaplar($pdo, $uid);
    syncKasaHareketleri($pdo, $uid);
}

// ── Hesap ekle ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hesap-ekle') {
    csrfKontrol();
    $ad         = trim($_POST['ad'] ?? '');
    $aciklama   = trim($_POST['aciklama'] ?? '');
    $renk       = trim($_POST['renk'] ?? '#3B82F6');
    $varsayilan = isset($_POST['varsayilan']) ? 1 : 0;
    if ($ad) {
        if ($varsayilan) {
            $pdo->prepare("UPDATE kasa_hesaplari SET varsayilan=0 WHERE user_id=?")->execute([$uid]);
        }
        $tipH   = in_array($_POST['tip_hesap'] ?? 'nakit', ['nakit','banka']) ? $_POST['tip_hesap'] : 'nakit';
        $hRes   = vpsYaz('post', '/kasa/hesaplar', ['ad' => $ad, 'aciklama' => $aciklama ?: null, 'renk' => $renk, 'varsayilan' => $varsayilan]);
        $hApiId = $hRes['hesap']['id'] ?? $hRes['data']['id'] ?? null;
        // api_id varsa zaten sync ile eklenmiş olabilir, tekrar ekleme
        $mevcutCheck = $hApiId ? $pdo->prepare("SELECT id FROM kasa_hesaplari WHERE api_id=? AND user_id=?") : null;
        if ($mevcutCheck) $mevcutCheck->execute([$hApiId, $uid]);
        if (!$mevcutCheck || !$mevcutCheck->fetch()) {
            $pdo->prepare("INSERT INTO kasa_hesaplari (user_id, ad, aciklama, renk, varsayilan, api_id, tip) VALUES (?,?,?,?,?,?,?)")
                ->execute([$uid, $ad, $aciklama ?: null, $renk, $varsayilan, $hApiId, $tipH]);
        }
    }
    header('Location: /kasa'); exit;
}

// ── Hesap düzenle ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hesap-duzenle') {
    csrfKontrol();
    $hid      = (int)($_POST['hesap_id'] ?? 0);
    $ad       = trim($_POST['ad'] ?? '');
    $aciklama = trim($_POST['aciklama'] ?? '');
    $renk     = trim($_POST['renk'] ?? '#3B82F6');
    error_log('DUZENLE: hid='.$hid.' ad='.$ad.' renk='.$renk.' uid='.$uid);
    if ($ad && $hid) {
        $pdo->prepare("UPDATE kasa_hesaplari SET ad=?, aciklama=?, renk=? WHERE id=? AND user_id=?")
            ->execute([$ad, $aciklama ?: null, $renk, $hid, $uid]);
    }
    header('Location: /kasa'); exit;
}

// ── Hesap sil ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hesap-sil') {
    csrfKontrol();
    $hid = (int)($_POST['hesap_id'] ?? 0);
    $hRow = $pdo->prepare("SELECT api_id FROM kasa_hesaplari WHERE id=? AND user_id=?");
    $hRow->execute([$hid, $uid]);
    $hRow = $hRow->fetch();
    if ($hRow) {
        // Hesap hareketleriyle birlikte silinir. Bağlı gelir-gider / fatura / ödeme kayıtları
        // silinmez, yalnızca kasa bağlantıları boşaltılır (önceden hareketli hesap sessizce silinmiyordu).
        $kQ = $pdo->prepare("SELECT * FROM kasa WHERE hesap_id=? AND user_id=?");
        $kQ->execute([$hid, $uid]);
        $kasaRows = $kQ->fetchAll();
        $kasaIds  = array_map('intval', array_column($kasaRows, 'id'));
        $pdo->beginTransaction();
        try {
            if ($kasaIds) {
                $in = implode(',', $kasaIds);
                try { $pdo->exec("UPDATE gelir_gider SET kasa_id=NULL WHERE kasa_id IN ($in)"); } catch (Exception $e) {}
                try { $pdo->exec("UPDATE faturalar SET odeme_kasa_id=NULL WHERE odeme_kasa_id IN ($in)"); } catch (Exception $e) {}
            }
            foreach (["UPDATE gelir_gider SET hesap_id=NULL WHERE hesap_id=? AND user_id=?",
                      "UPDATE faturalar SET odeme_hesap_id=NULL WHERE odeme_hesap_id=? AND user_id=?",
                      "UPDATE odemeler SET hesap_id=NULL WHERE hesap_id=? AND user_id=?"] as $sql) {
                try { $pdo->prepare($sql)->execute([$hid, $uid]); } catch (Exception $e) {}
            }
            $pdo->prepare("DELETE FROM kasa WHERE hesap_id=? AND user_id=?")->execute([$hid, $uid]);
            $pdo->prepare("DELETE FROM kasa_hesaplari WHERE id=? AND user_id=?")->execute([$hid, $uid]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            header('Location: /kasa?hata=hesap-sil'); exit;
        }
        foreach ($kasaRows as $kr) if (!empty($kr['api_id'])) vpsSil('/kasa/hareketler/' . $kr['api_id']);
        if (!empty($hRow['api_id'])) vpsSil('/kasa/hesaplar/' . $hRow['api_id']);
    }
    header('Location: /kasa'); exit;
}

// ── Manuel kasa hareketi ekle ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ekle') {
    csrfKontrol();
    $tip      = $_POST['tip'] ?? '';
    $hesapId  = (int)($_POST['hesap_id'] ?? 0) ?: null;
    $tutar    = (float)str_replace(',', '.', $_POST['tutar'] ?? '0');
    $aciklama = trim($_POST['aciklama'] ?? '');
    $tarih    = trim($_POST['tarih'] ?? date('Y-m-d'));
    $cariId   = (int)($_POST['cari_id'] ?? 0) ?: null;
    if (in_array($tip, ['giris','cikis']) && $tutar > 0) {
        // Hesap api_id'sini bul
        $hRow = $pdo->prepare("SELECT api_id FROM kasa_hesaplari WHERE id=? AND user_id=?");
        $hRow->execute([$hesapId, $uid]);
        $hRow = $hRow->fetch();
        $kRes   = vpsYaz('post', '/kasa/hareketler', [
            'hesap_id' => $hRow['api_id'] ?? null, 'tip' => $tip, 'tutar' => $tutar,
            'aciklama' => $aciklama ?: null, 'tarih' => $tarih, 'kaynak' => 'manuel',
        ]);
        $kApiId = $kRes['data']['id'] ?? $kRes['hareket']['id'] ?? null;
        $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, kaynak, kaynak_id, tarih, api_id) VALUES (?,?,?,?,?,'manuel',?,?,?)")
            ->execute([$uid, $hesapId, $tip, $tutar, $aciklama ?: null, $cariId, $tarih, $kApiId]);
    }
    header('Location: /kasa' . ($hesapId ? '?hesap='.$hesapId : '')); exit;
}

// ── Manuel hareket sil ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sil') {
    csrfKontrol();
    $kid  = (int)($_POST['kasa_id'] ?? 0);
    $kRow = $pdo->prepare("SELECT api_id FROM kasa WHERE id=? AND user_id=?");
    $kRow->execute([$kid, $uid]);
    $kRow = $kRow->fetch();
    $stmt = $pdo->prepare("DELETE FROM kasa WHERE id=? AND user_id=? AND kaynak='manuel'");
    $stmt->execute([$kid, $uid]);
    if ($stmt->rowCount() && !empty($kRow['api_id'])) vpsSil('/kasa/hareketler/' . $kRow['api_id']);
    header('Location: /kasa' . ($_POST['hesap_filtre'] ? '?hesap='.(int)$_POST['hesap_filtre'] : '')); exit;
}

// ── Veri ────────────────────────────────────────────────────────────────────
$hesaplar = $pdo->prepare("SELECT h.*, COALESCE(SUM(CASE WHEN k.tip='giris' THEN k.tutar ELSE 0 END),0) - COALESCE(SUM(CASE WHEN k.tip='cikis' THEN k.tutar ELSE 0 END),0) AS bakiye, COUNT(k.id) AS hareket_sayisi FROM kasa_hesaplari h LEFT JOIN kasa k ON k.hesap_id=h.id WHERE h.user_id=? GROUP BY h.id ORDER BY h.id ASC");
$hesaplar->execute([$uid]);
$hesaplar = $hesaplar->fetchAll();

$seciliHesap = (int)($_GET['hesap'] ?? 0);
$filtre      = $_GET['filtre'] ?? 'tumu';

// Cari listesi (manuel kayıtta seçim için)
$cariListesi = $pdo->prepare("SELECT id, ad FROM cariler WHERE user_id=? ORDER BY ad ASC");
$cariListesi->execute([$uid]);
$cariListesi = $cariListesi->fetchAll();

$where  = "WHERE k.user_id=?";
$params = [$uid];
if ($seciliHesap) { $where .= " AND k.hesap_id=?"; $params[] = $seciliHesap; }
if ($filtre === 'bugun')  { $where .= " AND k.tarih=date('now','localtime')"; }
if ($filtre === 'bu_ay')  { $where .= " AND strftime('%Y-%m',k.tarih)=strftime('%Y-%m','now','localtime')"; }

$hareketler = $pdo->prepare("SELECT k.*, kh.ad AS hesap_ad, kh.renk AS hesap_renk, c.ad AS cari_ad FROM kasa k LEFT JOIN kasa_hesaplari kh ON kh.id=k.hesap_id LEFT JOIN cariler c ON c.id=k.kaynak_id AND k.kaynak IN ('tahsilat','hizli_satis') $where ORDER BY k.tarih DESC, k.id DESC LIMIT 300");
$hareketler->execute($params);
$hareketler = $hareketler->fetchAll();

// Genel toplam bakiye (her zaman tüm hesaplar)
$genelOzet = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tip='giris' THEN tutar ELSE 0 END),0) AS giris, COALESCE(SUM(CASE WHEN tip='cikis' THEN tutar ELSE 0 END),0) AS cikis FROM kasa WHERE user_id=?");
$genelOzet->execute([$uid]);
$genelOzet   = $genelOzet->fetch();
$genelBakiye = $genelOzet['giris'] - $genelOzet['cikis'];

// Seçili hesap özeti (kart bilgileri için)
if ($seciliHesap) {
    $kartOzet = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tip='giris' THEN tutar ELSE 0 END),0) AS giris, COALESCE(SUM(CASE WHEN tip='cikis' THEN tutar ELSE 0 END),0) AS cikis FROM kasa WHERE user_id=? AND hesap_id=?");
    $kartOzet->execute([$uid, $seciliHesap]);
    $kartOzet = $kartOzet->fetch();
    $kartBakiye = $kartOzet['giris'] - $kartOzet['cikis'];

    $kartBuAy = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tip='giris' THEN tutar ELSE 0 END),0) AS giris, COALESCE(SUM(CASE WHEN tip='cikis' THEN tutar ELSE 0 END),0) AS cikis FROM kasa WHERE user_id=? AND hesap_id=? AND strftime('%Y-%m',tarih)=strftime('%Y-%m','now','localtime')");
    $kartBuAy->execute([$uid, $seciliHesap]);
    $kartBuAy = $kartBuAy->fetch();

    // Seçili hesap adı
    $seciliHesapAd = '';
    foreach ($hesaplar as $h) { if ($h['id'] === $seciliHesap) { $seciliHesapAd = $h['ad']; break; } }
} else {
    // Tüm hesaplar özeti
    $kartBakiye = $genelBakiye;
    $kartBuAy = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tip='giris' THEN tutar ELSE 0 END),0) AS giris, COALESCE(SUM(CASE WHEN tip='cikis' THEN tutar ELSE 0 END),0) AS cikis FROM kasa WHERE user_id=? AND strftime('%Y-%m',tarih)=strftime('%Y-%m','now','localtime')");
    $kartBuAy->execute([$uid]);
    $kartBuAy = $kartBuAy->fetch();
    $seciliHesapAd = 'Tüm Hesaplar';
}

$pageTitle = 'Kasa';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kasa — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Kasa<?= $seciliHesap ? ' — '.htmlspecialchars($seciliHesapAd) : '' ?></div>
        <div class="page-sub"><?= $seciliHesap ? 'Hesap detayı' : 'Tüm kasa hesapları' ?></div>
      </div>
      <button class="btn btn-primary btn-sm" onclick="document.getElementById('modalHesapEkle').style.display='flex'">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Yeni Kasa Hesabı
      </button>
    </div>

    <div class="page-body">

      <!-- Kasa hesap kartları -->
      <?php if (empty($hesaplar)): ?>
      <div class="panel" style="padding:1.5rem;text-align:center;color:var(--ink3);margin-bottom:1rem">
        Henüz kasa hesabı yok. Sağ üstten "Yeni Kasa Hesabı" oluşturun.
      </div>
      <?php else: ?>
      <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem">
        <a href="/kasa" class="kasa-hesap-kart <?= !$seciliHesap ? 'aktif' : '' ?>" style="--hrenk:#6B7280">
          <div class="kasa-hesap-kart-ust">
            <span>Tüm Hesaplar</span>
          </div>
          <div class="kasa-hesap-kart-bakiye" style="color:<?= $genelBakiye>=0?'var(--green)':'var(--red)' ?>">
            ₺<?= number_format(abs($genelBakiye),2,',','.') ?>
          </div>
        </a>
        <?php foreach ($hesaplar as $h): ?>
        <div class="kasa-hesap-kart <?= $seciliHesap===$h['id'] ? 'aktif' : '' ?>" style="--hrenk:<?= htmlspecialchars($h['renk']) ?>;cursor:pointer" onclick="window.location='/kasa?hesap=<?= $h['id'] ?>'">
          <div class="kasa-hesap-kart-ust">
            <span style="display:flex;align-items:center;gap:.3rem">
              <?php if (($h['tip'] ?? 'nakit') === 'banka'): ?>
              <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="opacity:.85"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
              <?php else: ?>
              <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="opacity:.85"><circle cx="12" cy="12" r="9"/><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              <?php endif; ?>
              <?= htmlspecialchars($h['ad']) ?>
            </span>
            <span style="display:flex;align-items:center;gap:.15rem" onclick="event.stopPropagation()">
              <a href="/kasa-duzenle?id=<?= $h['id'] ?>" class="kasa-hesap-sil" title="Düzenle" onclick="event.stopPropagation()">
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              </a>
              <button type="button" class="kasa-hesap-sil kasa-btn-sil" title="Hesabı Sil"
                onclick="event.stopPropagation();kasaHesapSilOnayla('silForm<?= $h['id'] ?>',<?= htmlspecialchars(json_encode($h['ad'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,<?= (int)$h['hareket_sayisi'] ?>)"
                >×</button>
            </span>
          </div>
          <?php if ($h['aciklama']): ?>
          <div style="font-size:.7rem;color:rgba(255,255,255,.65);margin-bottom:.25rem"><?= htmlspecialchars($h['aciklama']) ?></div>
          <?php endif; ?>
          <div class="kasa-hesap-kart-bakiye">₺<?= number_format($h['bakiye'],2,',','.') ?></div>
        </div>
        <form method="post" action="/kasa" style="visibility:hidden;position:absolute;height:0" id="silForm<?= $h['id'] ?>">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="hesap-sil">
          <input type="hidden" name="hesap_id" value="<?= $h['id'] ?>">
        </form>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Özet bar — seçili hesaba göre değişir -->
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1rem">
        <div class="panel" style="padding:.75rem 1rem;border-left:3px solid <?= $kartBakiye>=0?'var(--green)':'var(--red)' ?>">
          <div class="stat-label"><?= $seciliHesap ? htmlspecialchars($seciliHesapAd).' Bakiyesi' : 'Toplam Bakiye' ?></div>
          <div class="stat-val" style="font-size:1.15rem;color:<?= $kartBakiye>=0?'var(--green)':'var(--red)' ?>;margin:.15rem 0;font-weight:800">
            <?= $kartBakiye>=0?'':'-' ?>₺<?= number_format(abs($kartBakiye),2,',','.') ?>
          </div>
          <div class="stat-sub"><?= $seciliHesap ? 'hesap bakiyesi' : 'tüm hesaplar' ?></div>
        </div>
        <div class="panel" style="padding:.75rem 1rem">
          <div class="stat-label">Bu Ay Giriş</div>
          <div class="stat-val" style="font-size:1.1rem;color:var(--green);margin:.15rem 0">₺<?= number_format($kartBuAy['giris'],2,',','.') ?></div>
          <div class="stat-sub"><?= $seciliHesap ? htmlspecialchars($seciliHesapAd) : 'tüm hesaplar' ?></div>
        </div>
        <div class="panel" style="padding:.75rem 1rem">
          <div class="stat-label">Bu Ay Çıkış</div>
          <div class="stat-val" style="font-size:1.1rem;color:var(--red);margin:.15rem 0">₺<?= number_format($kartBuAy['cikis'],2,',','.') ?></div>
          <div class="stat-sub"><?= $seciliHesap ? htmlspecialchars($seciliHesapAd) : 'tüm hesaplar' ?></div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 280px;gap:1rem;align-items:start">

        <!-- Hareket listesi -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">Hareketler<?= $seciliHesap ? ' — '.htmlspecialchars(array_column($hesaplar,'ad','id')[$seciliHesap] ?? '') : '' ?></div>
            <div style="display:flex;gap:.4rem">
              <a href="/kasa<?= $seciliHesap?'?hesap='.$seciliHesap.'&':'?' ?>filtre=tumu"   class="btn btn-sm <?= $filtre==='tumu'  ?'btn-primary':'btn-ghost' ?>">Tümü</a>
              <a href="/kasa<?= $seciliHesap?'?hesap='.$seciliHesap.'&':'?' ?>filtre=bugun"  class="btn btn-sm <?= $filtre==='bugun' ?'btn-primary':'btn-ghost' ?>">Bugün</a>
              <a href="/kasa<?= $seciliHesap?'?hesap='.$seciliHesap.'&':'?' ?>filtre=bu_ay"  class="btn btn-sm <?= $filtre==='bu_ay' ?'btn-primary':'btn-ghost' ?>">Bu Ay</a>
            </div>
          </div>
          <?php if (empty($hareketler)): ?>
          <div class="empty-state">
            <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
            <h3>Hareket yok</h3>
            <p>Bu dönemde kayıtlı hareket bulunmuyor.</p>
          </div>
          <?php else: ?>
          <table class="data-table">
            <thead><tr>
              <th>Tarih</th><th>Tip</th><th>Açıklama</th>
              <th>Hesap</th><th>Kaynak</th><th style="text-align:right">Tutar</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($hareketler as $h): ?>
            <tr>
              <td style="white-space:nowrap;font-size:.8rem;color:var(--ink3)"><?= date('d.m.Y',strtotime($h['tarih'])) ?></td>
              <td><?php if ($h['tip']==='giris'): ?>
                <span class="badge" style="background:var(--green-lt);color:var(--green)">↑ Giriş</span>
              <?php else: ?>
                <span class="badge" style="background:var(--red-lt);color:var(--red)">↓ Çıkış</span>
              <?php endif; ?></td>
              <td style="font-size:.84rem"><?= htmlspecialchars($h['aciklama'] ?? '—') ?></td>
              <td><?php if ($h['hesap_ad']): ?>
                <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem">
                  <span style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($h['hesap_renk']??'#6B7280') ?>;flex-shrink:0"></span>
                  <?= htmlspecialchars($h['hesap_ad']) ?>
                </span>
              <?php else: ?>
                <span style="font-size:.75rem;color:var(--ink3)">—</span>
              <?php endif; ?></td>
              <td style="font-size:.78rem;color:var(--ink3)"><?php
                echo match($h['kaynak']) {
                  'tahsilat'    => '📋 Cari',
                  'hizli_satis' => '⚡ Hızlı Satış',
                  'manuel'      => '✏️ Manuel',
                  default       => $h['kaynak'] ?? '—'
                };
                if ($h['cari_ad']) echo ' · <a href="/cari?id='.$h['kaynak_id'].'" style="color:var(--accent)">'.htmlspecialchars($h['cari_ad']).'</a>';
              ?></td>
              <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums;color:<?= $h['tip']==='giris'?'var(--green)':'var(--red)' ?>">
                <?= $h['tip']==='giris'?'+':'-' ?>₺<?= number_format($h['tutar'],2,',','.') ?>
              </td>
              <td><?php if ($h['kaynak']==='manuel'): ?>
                <form method="post" action="/kasa" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= csrf() ?>">
                  <input type="hidden" name="action" value="sil">
                  <input type="hidden" name="kasa_id" value="<?= $h['id'] ?>">
                  <input type="hidden" name="hesap_filtre" value="<?= $seciliHesap ?>">
                  <button type="button" class="btn-icon" title="Sil"
                    onclick="var f=this.closest('form');kasaHesapSilOnayla2(f)">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                  </button>
                </form>
              <?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </div>

        <!-- Manuel kayıt formu -->
        <div class="panel" style="min-width:0">
          <div class="panel-head"><div class="panel-title">Manuel Kayıt</div></div>
          <div style="padding:1rem">
            <form method="post" action="/kasa">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="action" value="ekle">

              <div class="form-group">
                <label class="form-label">Tip</label>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem">
                  <label class="kasa-tip-lbl" id="lbl-giris">
                    <input type="radio" name="tip" value="giris" checked onchange="kasaTipDeg()" style="display:none"> ↑ Giriş
                  </label>
                  <label class="kasa-tip-lbl" id="lbl-cikis">
                    <input type="radio" name="tip" value="cikis" onchange="kasaTipDeg()" style="display:none"> ↓ Çıkış
                  </label>
                </div>
              </div>

              <?php if (!empty($hesaplar)): ?>
              <div class="form-group">
                <label class="form-label">Kasa Hesabı</label>
                <select name="hesap_id" class="form-input">
                  <option value="">— Hesap seç —</option>
                  <?php foreach ($hesaplar as $h): ?>
                  <option value="<?= $h['id'] ?>" <?= $seciliHesap===$h['id']?'selected':'' ?>><?= htmlspecialchars($h['ad']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>

              <div class="form-group">
                <label class="form-label">Tutar (₺)</label>
                <input type="number" name="tutar" class="form-input" min="0.01" step="0.01" placeholder="0,00" required>
              </div>
              <div class="form-group">
                <label class="form-label">Tarih</label>
                <input type="date" name="tarih" class="form-input" value="<?= date('Y-m-d') ?>">
              </div>
              <div class="form-group">
                <label class="form-label">Açıklama</label>
                <input type="text" name="aciklama" class="form-input" placeholder="Gider türü, not vb.">
              </div>
              <?php if (!empty($cariListesi)): ?>
              <div class="form-group">
                <label class="form-label">Cari <span style="color:var(--ink3);font-weight:400">(opt.)</span></label>
                <select name="cari_id" class="form-input">
                  <option value="">— Cari seç —</option>
                  <?php foreach ($cariListesi as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['ad']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
              <button type="submit" class="btn btn-primary" style="width:100%">Kaydet</button>
            </form>
          </div>
        </div>

      </div>
    </div>
  </main>
</div>

<!-- Yeni Hesap Modal -->
<div id="modalHesapEkle" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.45);align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--surface);border-radius:12px;padding:1.5rem;width:340px;max-width:90vw;box-shadow:0 8px 32px rgba(0,0,0,.2)" onclick="event.stopPropagation()">
    <div style="font-weight:700;font-size:1rem;margin-bottom:1rem">Yeni Kasa Hesabı</div>
    <form method="post" action="/kasa">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="hesap-ekle">
      <div class="form-group">
        <label class="form-label">Hesap Türü</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem">
          <label id="lbl-nakit" style="display:flex;align-items:center;justify-content:center;gap:.35rem;padding:.45rem .5rem;border:1.5px solid var(--green,#16a34a);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600;background:var(--green-lt,#dcfce7);color:var(--green,#16a34a)">
            <input type="radio" name="tip_hesap" value="nakit" checked style="display:none" onchange="kasaHesapTipDeg()">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            Nakit
          </label>
          <label id="lbl-banka" style="display:flex;align-items:center;justify-content:center;gap:.35rem;padding:.45rem .5rem;border:1.5px solid var(--border);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600">
            <input type="radio" name="tip_hesap" value="banka" style="display:none" onchange="kasaHesapTipDeg()">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
            Banka
          </label>
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Hesap Adı <span style="color:var(--red)">*</span></label>
        <input type="text" name="ad" class="form-input" placeholder="Nakit Kasa, Ziraat Bankası…" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">Açıklama</label>
        <input type="text" name="aciklama" class="form-input" placeholder="İsteğe bağlı">
      </div>
      <div class="form-group">
        <label class="form-label">Renk</label>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap" id="renkSecici">
          <?php foreach (['#3B82F6','#10B981','#F59E0B','#EF4444','#8B5CF6','#EC4899','#06B6D4','#6B7280'] as $r): ?>
          <label style="cursor:pointer">
            <input type="radio" name="renk" value="<?= $r ?>" style="display:none" <?= $r==='#3B82F6'?'checked':'' ?>>
            <span style="display:block;width:24px;height:24px;border-radius:50%;background:<?= $r ?>;border:2.5px solid transparent" class="renk-dot"></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-group" style="margin-bottom:.5rem">
        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.86rem">
          <input type="checkbox" name="varsayilan" value="1" style="width:15px;height:15px;accent-color:var(--accent)">
          <span>Varsayılan kasa hesabı olarak ayarla</span>
        </label>
        <div class="form-hint">Hızlı satışta otomatik seçilir</div>
      </div>
      <div style="display:flex;gap:.5rem;margin-top:.75rem">
        <button type="submit" class="btn btn-primary">Ekle</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modalHesapEkle').style.display='none'">İptal</button>
      </div>
    </form>
  </div>
</div>

<!-- Hesap Düzenle Modal -->
<div id="modalHesapDuzenle" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.45);align-items:center;justify-content:center" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:var(--surface);border-radius:12px;padding:1.5rem;width:340px;max-width:90vw;box-shadow:0 8px 32px rgba(0,0,0,.2)" onclick="event.stopPropagation()">
    <div style="font-weight:700;font-size:1rem;margin-bottom:1rem">Hesap Düzenle</div>
    <form method="post" action="/kasa" id="formHesapDuzenle">
      <input type="hidden" name="csrf" value="<?= csrf() ?>">
      <input type="hidden" name="action" value="hesap-duzenle">
      <input type="hidden" name="hesap_id" id="duzHesapId">
      <div class="form-group">
        <label class="form-label">Hesap Adı <span style="color:var(--red)">*</span></label>
        <input type="text" name="ad" id="duzAd" class="form-input" placeholder="Ana Kasa, Banka, Pos…" required>
      </div>
      <div class="form-group">
        <label class="form-label">Açıklama</label>
        <input type="text" name="aciklama" id="duzAciklama" class="form-input" placeholder="İsteğe bağlı">
      </div>
      <div class="form-group">
        <label class="form-label">Renk</label>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap" id="duzRenkSecici">
          <?php foreach (['#3B82F6','#10B981','#F59E0B','#EF4444','#8B5CF6','#EC4899','#06B6D4','#6B7280'] as $r): ?>
          <label style="cursor:pointer">
            <input type="radio" name="renk" value="<?= $r ?>" style="display:none">
            <span style="display:block;width:24px;height:24px;border-radius:50%;background:<?= $r ?>;border:2.5px solid transparent" class="renk-dot"></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="display:flex;gap:.5rem;margin-top:.75rem">
        <button type="button" class="btn btn-primary" onclick="duzenlemeKaydet()">Kaydet</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modalHesapDuzenle').style.display='none'">İptal</button>
      </div>
    </form>
  </div>
</div>

<style>
.kasa-hesap-kart {
  display:flex;flex-direction:column;min-width:140px;padding:.75rem 1rem;
  border-radius:10px;border:2px solid transparent;cursor:pointer;
  background:linear-gradient(135deg, var(--hrenk) 0%, color-mix(in srgb,var(--hrenk) 70%,#000) 100%);
  color:#fff;text-decoration:none;transition:transform .12s,box-shadow .12s;
}
.kasa-hesap-kart:hover { transform:translateY(-1px);box-shadow:0 4px 16px rgba(0,0,0,.15); }
.kasa-hesap-kart.aktif { border-color:#fff; box-shadow:0 0 0 3px rgba(255,255,255,.3); }
.kasa-hesap-kart-ust { display:flex;align-items:center;justify-content:space-between;font-size:.78rem;font-weight:600;margin-bottom:.35rem;opacity:.9 }
.kasa-hesap-kart-bakiye { font-size:1.05rem;font-weight:700;font-variant-numeric:tabular-nums }
.kasa-hesap-sil { background:none;border:none;color:rgba(255,255,255,.6);cursor:pointer;font-size:1rem;line-height:1;padding:0;margin-left:.25rem }
.kasa-hesap-sil:hover { color:#fff }
.kasa-tip-lbl { display:flex;align-items:center;justify-content:center;padding:.4rem .5rem;border:1.5px solid var(--border);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600 }
#renkSecici input:checked + .renk-dot { border-color:var(--ink1)!important;box-shadow:0 0 0 2px var(--surface),0 0 0 4px var(--ink1) }
</style>
<script>
function kasaHesapSilOnayla2(form) {
  var modal = document.createElement('div');
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center';
  modal.innerHTML = '<div style="background:var(--bg);border-radius:var(--r);padding:1.5rem;max-width:300px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.3)">'
    + '<div style="font-weight:700;margin-bottom:.5rem">Kaydı Sil</div>'
    + '<div style="font-size:.88rem;color:var(--ink2);margin-bottom:1.25rem">Bu kasa kaydı silinsin mi?</div>'
    + '<div style="display:flex;gap:.5rem;justify-content:flex-end">'
    + '<button onclick="this.closest(\'[data-modal]\').remove()" style="padding:.4rem .9rem;border:1.5px solid var(--border);border-radius:var(--r);background:transparent;cursor:pointer;font-family:inherit">İptal</button>'
    + '<button id="_silBtn2" style="padding:.4rem .9rem;border:none;border-radius:var(--r);background:var(--red,#ef4444);color:#fff;cursor:pointer;font-family:inherit;font-weight:600">Sil</button>'
    + '</div></div>';
  modal.setAttribute('data-modal','1');
  document.body.appendChild(modal);
  modal.querySelector('#_silBtn2').onclick = function(){ form.submit(); };
}

function kasaHesapSilOnayla(formId, ad, hareketSayisi) {
  ad = String(ad).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; });
  var uyari = hareketSayisi > 0
    ? '<div style="font-size:.8rem;color:var(--red,#ef4444);background:var(--red-lt,#fee2e2);border-radius:var(--r);padding:.5rem .65rem;margin:-.5rem 0 1.25rem">Bu hesapta <b>' + hareketSayisi + ' hareket</b> var, hepsi kasadan silinecek. Bağlı fatura, gelir-gider ve tedarikçi kayıtları kalır.</div>'
    : '';
  var modal = document.createElement('div');
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:flex;align-items:center;justify-content:center';
  modal.innerHTML = '<div style="background:var(--bg);border-radius:var(--r);padding:1.5rem;max-width:320px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.3)">'
    + '<div style="font-weight:700;margin-bottom:.5rem">Hesabı Sil</div>'
    + '<div style="font-size:.88rem;color:var(--ink2);margin-bottom:1.25rem"><b>' + ad + '</b> hesabını silmek istiyor musunuz?</div>'
    + uyari
    + '<div style="display:flex;gap:.5rem;justify-content:flex-end">'
    + '<button onclick="this.closest(\'[data-modal]\').remove()" style="padding:.4rem .9rem;border:1.5px solid var(--border);border-radius:var(--r);background:transparent;cursor:pointer;font-family:inherit">İptal</button>'
    + '<button onclick="document.getElementById(\''+formId+'\').submit()" style="padding:.4rem .9rem;border:none;border-radius:var(--r);background:var(--red,#ef4444);color:#fff;cursor:pointer;font-family:inherit;font-weight:600">Sil</button>'
    + '</div></div>';
  modal.setAttribute('data-modal','1');
  document.body.appendChild(modal);
}

function kasaHesapTipDeg() {
  var nakit = document.querySelector('input[name="tip_hesap"][value="nakit"]').checked;
  var ln = document.getElementById('lbl-nakit');
  var lb = document.getElementById('lbl-banka');
  if (nakit) {
    ln.style.borderColor = 'var(--green,#16a34a)'; ln.style.background = 'var(--green-lt,#dcfce7)'; ln.style.color = 'var(--green,#16a34a)';
    lb.style.borderColor = 'var(--border)'; lb.style.background = ''; lb.style.color = '';
  } else {
    lb.style.borderColor = '#2563eb'; lb.style.background = '#dbeafe'; lb.style.color = '#2563eb';
    ln.style.borderColor = 'var(--border)'; ln.style.background = ''; ln.style.color = '';
  }
}

function kasaTipDeg() {
  var g = document.querySelector('input[name="tip"][value="giris"]').checked;
  var lg = document.getElementById('lbl-giris');
  var lc = document.getElementById('lbl-cikis');
  lg.style.cssText = g ? 'display:flex;align-items:center;justify-content:center;padding:.4rem .5rem;border:1.5px solid var(--green);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600;background:var(--green-lt);color:var(--green)' : 'display:flex;align-items:center;justify-content:center;padding:.4rem .5rem;border:1.5px solid var(--border);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600';
  lc.style.cssText = !g ? 'display:flex;align-items:center;justify-content:center;padding:.4rem .5rem;border:1.5px solid var(--red);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600;background:var(--red-lt);color:var(--red)' : 'display:flex;align-items:center;justify-content:center;padding:.4rem .5rem;border:1.5px solid var(--border);border-radius:var(--r);cursor:pointer;font-size:.82rem;font-weight:600';
}
kasaTipDeg();

document.addEventListener('click', function(e) {
  var btnD = e.target.closest('.kasa-btn-duzenle');
  if (btnD) {
    e.stopPropagation();
    hesapDuzenleAc(btnD.dataset.hid, btnD.dataset.had, btnD.dataset.hacik, btnD.dataset.hrenk);
    return;
  }
  var btnS = e.target.closest('.kasa-btn-sil');
  if (btnS) {
    e.stopPropagation();
    if (confirm(btnS.dataset.had + ' hesabını silmek istiyor musunuz?')) {
      document.getElementById(btnS.dataset.formid).submit();
    }
    return;
  }
});

function duzenlemeKaydet() {
  var hid  = document.getElementById('duzHesapId').value;
  var ad   = document.getElementById('duzAd').value.trim();
  var acik = document.getElementById('duzAciklama').value.trim();
  var renk = '#3B82F6';
  var renkEl = document.querySelector('#duzRenkSecici input[type="radio"]:checked');
  if (renkEl) renk = renkEl.value;
  var csrf = document.querySelector('#formHesapDuzenle input[name="csrf"]').value;
  if (!ad || !hid) { alert('Hesap adı zorunludur.'); return; }
  console.log('DUZENLE:', hid, ad, renk, 'csrf='+csrf.slice(0,8)+'...');
  var f = document.createElement('form');
  f.method = 'post'; f.action = '/kasa';
  [['csrf',csrf],['action','hesap-duzenle'],['hesap_id',hid],['ad',ad],['aciklama',acik],['renk',renk]].forEach(function(p){
    var i = document.createElement('input'); i.type='hidden'; i.name=p[0]; i.value=p[1]; f.appendChild(i);
  });
  document.body.appendChild(f);
  f.submit();
}

function hesapDuzenleAc(id, ad, aciklama, renk) {
  document.getElementById('duzHesapId').value   = id;
  document.getElementById('duzAd').value        = ad;
  document.getElementById('duzAciklama').value  = aciklama;
  // Renk seç
  var renkler = document.querySelectorAll('#duzRenkSecici input[type="radio"]');
  var secildi = false;
  renkler.forEach(function(r) {
    if (r.value === renk) { r.checked = true; secildi = true; }
    else r.checked = false;
  });
  if (!secildi && renkler.length) renkler[0].checked = true;
  document.getElementById('modalHesapDuzenle').style.display = 'flex';
  setTimeout(function(){ document.getElementById('duzAd').focus(); }, 80);
}
</script>
</body>
</html>
