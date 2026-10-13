<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'profil';

$uid = userId();
$userStmt = $pdo->prepare("SELECT id, ad, lisans_kodu, lisans_aktif, demo_mod FROM users WHERE id=?");
$userStmt->execute([$uid]);
$user = $userStmt->fetch();

$adError = $adSuccess = $sifreError = $sifreSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'ad') {
    csrfKontrol();
    $yeniAd = trim($_POST['ad'] ?? '');
    if ($yeniAd === '') {
        $adError = 'Ad boş olamaz.';
    } else {
        $pdo->prepare("UPDATE users SET ad=? WHERE id=?")->execute([$yeniAd, $uid]);
        $_SESSION['user_ad'] = $yeniAd;
        $user['ad'] = $yeniAd;
        $adSuccess = 'Kullanıcı adı güncellendi.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'sifre') {
    csrfKontrol();
    $mevcutSifre = $_POST['mevcut_sifre'] ?? '';
    $yeniSifre   = $_POST['yeni_sifre']   ?? '';
    $tekrar      = $_POST['yeni_sifre_tekrar'] ?? '';

    $row = $pdo->prepare("SELECT sifre FROM users WHERE id=?");
    $row->execute([$uid]);
    $row = $row->fetch();

    if (!password_verify($mevcutSifre, $row['sifre'])) {
        $sifreError = 'Mevcut şifre yanlış.';
    } elseif (strlen($yeniSifre) < 6) {
        $sifreError = 'Yeni şifre en az 6 karakter olmalı.';
    } elseif ($yeniSifre !== $tekrar) {
        $sifreError = 'Şifreler eşleşmiyor.';
    } else {
        $pdo->prepare("UPDATE users SET sifre=? WHERE id=?")->execute([password_hash($yeniSifre, PASSWORD_DEFAULT), $uid]);
        $sifreSuccess = 'Şifre başarıyla değiştirildi.';
    }
}

// Firma adı ayarı
$firmaAdi = '';
$firmaRow = $pdo->prepare("SELECT deger FROM ayarlar WHERE user_id=? AND anahtar='firma_adi'");
$firmaRow->execute([$uid]);
$firmaRow = $firmaRow->fetch();
$firmaAdi = $firmaRow ? ($firmaRow['deger'] ?? '') : '';

$firmaError = $firmaSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'firma') {
    csrfKontrol();
    $yeniFirma = trim($_POST['firma_adi'] ?? '');
    $pdo->prepare("INSERT INTO ayarlar (user_id, anahtar, deger) VALUES (?,?,?)
        ON CONFLICT(user_id, anahtar) DO UPDATE SET deger=excluded.deger")
        ->execute([$uid, 'firma_adi', $yeniFirma]);
    $firmaAdi = $yeniFirma;
    $firmaSuccess = 'Firma adı güncellendi.';
}

// Panel kartları ayarı
$panelKartlarRow = $pdo->prepare("SELECT deger FROM ayarlar WHERE user_id=? AND anahtar='panel_kartlar'");
$panelKartlarRow->execute([$uid]);
$panelKartlarRow = $panelKartlarRow->fetch();
$panelKartlarJson = $panelKartlarRow ? ($panelKartlarRow['deger'] ?? '') : '';
$panelKartlarVarsayilan = [
    'nakit_satis'   => ['aktif' => true,  'etiket' => 'Nakit Satış',   'veri' => 'toplam_alacak'],
    'vadeli_satis'  => ['aktif' => true,  'etiket' => 'Vadeli Satış',  'veri' => 'toplam_borc'],
    'toplam_satis'  => ['aktif' => true,  'etiket' => 'Toplam Satış',  'veri' => 'toplam_satis'],
    'net_bakiye'    => ['aktif' => true,  'etiket' => 'Net Bakiye',    'veri' => 'net_bakiye'],
    'bu_ay_nakit'   => ['aktif' => false, 'etiket' => 'Bu Ay Nakit',   'veri' => 'bu_ay_alacak'],
    'bu_ay_vadeli'  => ['aktif' => false, 'etiket' => 'Bu Ay Vadeli',  'veri' => 'bu_ay_tahsilat'],
    'cari_sayisi'   => ['aktif' => false, 'etiket' => 'Cari Sayısı',   'veri' => 'cari_sayisi'],
];
$panelKartlar = $panelKartlarVarsayilan;
if ($panelKartlarJson) {
    $decoded = json_decode($panelKartlarJson, true);
    if (is_array($decoded)) {
        foreach ($decoded as $key => $val) {
            if (isset($panelKartlar[$key])) {
                $panelKartlar[$key]['aktif'] = (bool)($val['aktif'] ?? false);
                if (isset($val['veri'])) $panelKartlar[$key]['veri'] = $val['veri'];
            }
        }
    }
}

// Hatırlatıcı panel kartı ayarı
$phAyarRow = $pdo->prepare("SELECT deger FROM ayarlar WHERE user_id=? AND anahtar='panel_hatirlatici'");
$phAyarRow->execute([$uid]);
$phAyarRow = $phAyarRow->fetch();
$panelHatirlaticiAktif = $phAyarRow ? ($phAyarRow['deger'] !== '0') : true;

$panelHatirlaticiSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'panel-hatirlatici') {
    csrfKontrol();
    $deger = isset($_POST['panel_hatirlatici_aktif']) ? '1' : '0';
    $pdo->prepare("INSERT INTO ayarlar (user_id, anahtar, deger) VALUES (?,?,?)
        ON CONFLICT(user_id, anahtar) DO UPDATE SET deger=excluded.deger")
        ->execute([$uid, 'panel_hatirlatici', $deger]);
    $panelHatirlaticiAktif = $deger === '1';
    $panelHatirlaticiSuccess = 'Ayar güncellendi.';
}

$panelKartError = $panelKartSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'panel-kartlar') {
    csrfKontrol();
    $yeniKartlar = [];
    foreach ($panelKartlar as $key => $def) {
        $yeniKartlar[$key] = [
            'aktif' => isset($_POST['kart_aktif'][$key]),
            'veri'  => $_POST['kart_veri'][$key] ?? $def['veri'],
        ];
    }
    $jsonStr = json_encode($yeniKartlar, JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO ayarlar (user_id, anahtar, deger) VALUES (?,?,?)
        ON CONFLICT(user_id, anahtar) DO UPDATE SET deger=excluded.deger")
        ->execute([$uid, 'panel_kartlar', $jsonStr]);
    // güncelle
    foreach ($yeniKartlar as $key => $val) {
        if (isset($panelKartlar[$key])) {
            $panelKartlar[$key]['aktif'] = $val['aktif'];
            $panelKartlar[$key]['veri']  = $val['veri'];
        }
    }
    $panelKartSuccess = 'Panel kartları güncellendi.';
}

// Fiş ayarları
$fisAyarlar = ['boyut' => '80mm', 'sablon' => 'standart'];
$fasStmt = $pdo->prepare("SELECT anahtar, deger FROM ayarlar WHERE user_id=? AND anahtar IN ('fis_boyut','fis_sablon')");
$fasStmt->execute([$uid]);
foreach ($fasStmt->fetchAll() as $far) {
    if ($far['anahtar'] === 'fis_boyut')  $fisAyarlar['boyut']  = $far['deger'];
    if ($far['anahtar'] === 'fis_sablon') $fisAyarlar['sablon'] = $far['deger'];
}

$fisError = $fisSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'fis-ayar') {
    csrfKontrol();
    $yeniBoyut  = in_array($_POST['fis_boyut']  ?? '', ['80mm','58mm'])       ? $_POST['fis_boyut']  : '80mm';
    $yeniSablon = in_array($_POST['fis_sablon'] ?? '', ['standart','detayli','minimal']) ? $_POST['fis_sablon'] : 'standart';
    $pdo->prepare("INSERT INTO ayarlar (user_id, anahtar, deger) VALUES (?,?,?) ON CONFLICT(user_id, anahtar) DO UPDATE SET deger=excluded.deger")
        ->execute([$uid, 'fis_boyut', $yeniBoyut]);
    $pdo->prepare("INSERT INTO ayarlar (user_id, anahtar, deger) VALUES (?,?,?) ON CONFLICT(user_id, anahtar) DO UPDATE SET deger=excluded.deger")
        ->execute([$uid, 'fis_sablon', $yeniSablon]);
    $fisAyarlar = ['boyut' => $yeniBoyut, 'sablon' => $yeniSablon];
    $fisSuccess = 'Fiş ayarları güncellendi.';
}

// ── Bulut bağlantı durumu ────────────────────────────────────────────────────
$bulutBagliMi = !empty($_SESSION['api_token']);
$bulutEmail   = '';
$bulutRow = $pdo->prepare("SELECT lisans_kodu FROM users WHERE id=?");
$bulutRow->execute([$uid]);
$bulutRow = $bulutRow->fetch();
// lisans_kodu'nda @ varsa e-posta girilmiş demektir (api-giris akışında email buraya yazılıyor)
if ($bulutRow && strpos($bulutRow['lisans_kodu'] ?? '', '@') !== false) {
    $bulutEmail = $bulutRow['lisans_kodu'];
}

$bulutError = $bulutSuccess = '';

// Mevcut hesabı API'ye kaydet (ilk bağlantı — kayıt)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'bulut-kayit') {
    csrfKontrol();
    $bEmail  = trim($_POST['email'] ?? '');
    $bSifre  = $_POST['sifre'] ?? '';
    $bSifre2 = $_POST['sifre2'] ?? '';
    if (!$bEmail || !filter_var($bEmail, FILTER_VALIDATE_EMAIL)) {
        $bulutError = 'Geçerli bir e-posta girin.';
    } elseif (strlen($bSifre) < 6) {
        $bulutError = 'Şifre en az 6 karakter olmalı.';
    } elseif ($bSifre !== $bSifre2) {
        $bulutError = 'Şifreler eşleşmiyor.';
    } else {
        $apiBase2 = getenv('VERESIYEM_API_BASE') ?: 'https://api.veresiyem.com.tr/api/v1';
        // Önce giriş dene (zaten kayıtlı olabilir)
        $ctx = stream_context_create(['http' => ['method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json",
            'content' => json_encode(['email' => $bEmail, 'sifre' => $bSifre]),
            'timeout' => 8, 'ignore_errors' => true]]);
        $body = @file_get_contents($apiBase2 . '/giris', false, $ctx);
        $sonuc = $body ? (json_decode($body, true) ?: []) : [];
        if (empty($sonuc['ok'])) {
            // Giriş olmadı — yeni kayıt dene
            $ctx2 = stream_context_create(['http' => ['method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json",
                'content' => json_encode(['ad' => $user['ad'], 'email' => $bEmail, 'sifre' => $bSifre]),
                'timeout' => 8, 'ignore_errors' => true]]);
            $body2 = @file_get_contents($apiBase2 . '/kayit', false, $ctx2);
            $sonuc = $body2 ? (json_decode($body2, true) ?: []) : [];
        }
        if (!empty($sonuc['ok']) && !empty($sonuc['token'])) {
            $_SESSION['api_token']   = $sonuc['token'];
            $_SESSION['api_user_id'] = $sonuc['user']['id'] ?? null;
            // Lisans kodunu API'ye gönder
            if (!empty($user['lisans_kodu'])) {
                $ctxL = stream_context_create(['http' => ['method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer " . $sonuc['token'],
                    'content' => json_encode(['lisans_kodu' => $user['lisans_kodu']]),
                    'timeout' => 8, 'ignore_errors' => true]]);
                @file_get_contents($apiBase2 . '/profil/lisans', false, $ctxL);
            }
            // E-postayı yerel users tablosuna kaydet (lisans_kodu alanına — mevcut şema kullanılıyor)
            $pdo->prepare("UPDATE users SET lisans_kodu=? WHERE id=?")->execute([$bEmail, $uid]);
            $bulutBagliMi = true;
            $bulutEmail   = $bEmail;
            $bulutSuccess = 'Hesabınız buluta bağlandı! Artık mobilden de giriş yapabilirsiniz.';
            // index.php üzerinden token'ı Electron'a kaydet, sonra profil'e yönlendir
            header('Location: /?savetoken=' . urlencode($sonuc['token']) . '&redirect=' . urlencode('/profil?bulut=ok'));
            exit;
        } else {
            $bulutError = $sonuc['mesaj'] ?? $sonuc['message'] ?? 'Bağlantı başarısız. İnternet bağlantınızı kontrol edin.';
        }
    }
}

if (isset($_GET['bulut']) && $_GET['bulut'] === 'ok') {
    $bulutSuccess = 'Hesabınız buluta bağlandı! Artık mobilden de giriş yapabilirsiniz.';
    $bulutBagliMi = true;
}

$harf = mb_strtoupper(mb_substr($user['ad'], 0, 1, 'UTF-8'), 'UTF-8');
$pageTitle = 'Profil';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Profil — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.pkart-row {
  display: flex; align-items: center; justify-content: space-between; gap: 1rem;
  padding: .7rem .9rem; border-radius: var(--r);
  border: 1.5px solid var(--border); background: var(--surface2);
  transition: border-color .12s, background .12s;
}
.pkart-row.pkart-aktif { border-color: var(--green); background: var(--green-lt); }
.pkart-sol { display: flex; align-items: center; gap: .75rem; flex: 1; min-width: 0; }
.pkart-toggle { position: relative; cursor: pointer; flex-shrink: 0; }
.pkart-toggle input { position: absolute; opacity: 0; width: 0; height: 0; }
.pkart-sw {
  display: block; width: 34px; height: 19px;
  background: var(--border2); border-radius: 99px; transition: background .15s;
  position: relative;
}
.pkart-sw::after {
  content: ''; position: absolute; top: 2px; left: 2px;
  width: 15px; height: 15px; border-radius: 50%;
  background: #fff; transition: transform .15s;
}
.pkart-toggle input:checked ~ .pkart-sw { background: var(--green); }
.pkart-toggle input:checked ~ .pkart-sw::after { transform: translateX(15px); }
.pkart-color-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.pkart-bilgi { min-width: 0; }
.pkart-ad { font-size: .86rem; font-weight: 700; color: var(--ink); }
.pkart-durum { font-size: .73rem; color: var(--ink3); }
.pkart-row.pkart-aktif .pkart-durum { color: var(--green); }
.pkart-sag { flex-shrink: 0; }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left" style="display:flex;align-items:center;gap:.85rem">
        <div style="width:42px;height:42px;border-radius:11px;background:var(--green-lt);color:var(--green);display:flex;align-items:center;justify-content:center;font-family:var(--font-display);font-weight:900;font-size:1rem">
          <?= $harf ?>
        </div>
        <div>
          <div class="page-title">Profil &amp; Ayarlar</div>
          <div class="page-sub"><?= htmlspecialchars($user['ad']) ?></div>
        </div>
      </div>
    </div>

    <div class="page-body" style="max-width:580px">

      <!-- Bulut Bağlantısı -->
      <div class="panel" style="margin-bottom:1.25rem">
        <div class="panel-head">
          <div class="panel-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:<?= $bulutBagliMi ? 'var(--green)' : 'var(--ink3)' ?>"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
            Mobil & Bulut Bağlantısı
          </div>
          <?php if ($bulutBagliMi): ?>
            <span class="badge badge-green">Bağlı</span>
          <?php else: ?>
            <span class="badge badge-gray">Bağlı Değil</span>
          <?php endif; ?>
        </div>
        <div style="padding:1.25rem">
          <?php if ($bulutError): ?>
            <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($bulutError) ?></div>
          <?php endif; ?>
          <?php if ($bulutSuccess): ?>
            <div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($bulutSuccess) ?></div>
          <?php endif; ?>

          <?php if ($bulutBagliMi): ?>
            <div style="display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;background:var(--green-lt);border-radius:var(--r);border:1px solid rgba(22,163,74,.15)">
              <svg width="18" height="18" fill="none" stroke="#16a34a" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
              <div>
                <div style="font-size:.86rem;font-weight:700;color:#15803d">Buluta bağlı</div>
                <?php if ($bulutEmail): ?>
                  <div style="font-size:.76rem;color:#16a34a"><?= htmlspecialchars($bulutEmail) ?></div>
                <?php endif; ?>
              </div>
            </div>
            <div style="font-size:.76rem;color:var(--ink3);margin-top:.75rem">Yaptığınız işlemler otomatik olarak buluta ve mobil uygulamaya yansıtılır.</div>
          <?php else: ?>
            <p style="font-size:.84rem;color:var(--ink2);margin-bottom:1rem">Hesabınızı buluta bağlayarak <strong>Veresiyem mobil uygulamasından</strong> da aynı verilere erişebilirsiniz.</p>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= csrf() ?>">
              <input type="hidden" name="form" value="bulut-kayit">
              <div class="form-group">
                <label class="form-label">E-posta Adresi</label>
                <input type="email" name="email" class="form-input" placeholder="ornek@mail.com"
                  value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
                <div class="form-hint">Mobil uygulamaya bu e-posta ile giriş yapacaksınız</div>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group">
                  <label class="form-label">Şifre <span style="font-weight:400;color:var(--ink3)">(en az 6 karakter)</span></label>
                  <input type="password" name="sifre" class="form-input" placeholder="Şifre" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Şifre Tekrar</label>
                  <input type="password" name="sifre2" class="form-input" placeholder="Tekrar girin" required>
                </div>
              </div>
              <button type="submit" class="btn btn-primary">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
                Buluta Bağla
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <!-- Panel Kartları -->
      <div class="panel" style="margin-bottom:1.25rem">
        <div class="panel-head">
          <div class="panel-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--green)"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Panel Kartları
          </div>
        </div>
        <div style="padding:1.25rem">
          <?php if ($panelKartError):   ?><div class="alert alert-error"   style="margin-bottom:1rem"><?= htmlspecialchars($panelKartError) ?></div><?php endif; ?>
          <?php if ($panelKartSuccess): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($panelKartSuccess) ?></div><?php endif; ?>

          <div style="font-size:.8rem;color:var(--ink3);margin-bottom:1rem">Panel ana sayfasında hangi istatistik kartlarının görüneceğini ve içeriğini ayarlayın.</div>

          <form method="post" action="/profil">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="form" value="panel-kartlar">

            <?php
            // Her kart için olası veri seçenekleri
            $veriSecenekleri = [
                'nakit_satis'  => ['toplam_alacak' => 'Toplam Nakit Satış', 'bu_ay_alacak' => 'Bu Ay Nakit'],
                'vadeli_satis' => ['toplam_borc' => 'Toplam Vadeli Satış', 'bu_ay_tahsilat' => 'Bu Ay Vadeli'],
                'toplam_satis' => ['toplam_satis' => 'Toplam Satış (Nakit+Vadeli)'],
                'net_bakiye'   => ['net_bakiye' => 'Net Bakiye (Fark)'],
                'bu_ay_nakit'  => ['bu_ay_alacak' => 'Bu Ay Nakit Satış'],
                'bu_ay_vadeli' => ['bu_ay_tahsilat' => 'Bu Ay Vadeli Satış'],
                'cari_sayisi'  => ['cari_sayisi' => 'Aktif Cari Sayısı'],
            ];
            $kartRenkler = [
                'nakit_satis'  => 'var(--green)',
                'vadeli_satis' => 'var(--blue)',
                'toplam_satis' => 'var(--gold)',
                'net_bakiye'   => 'var(--ink2)',
                'bu_ay_nakit'  => 'var(--green)',
                'bu_ay_vadeli' => 'var(--blue)',
                'cari_sayisi'  => 'var(--purple,#7C3AED)',
            ];
            ?>
            <div style="display:flex;flex-direction:column;gap:.6rem">
              <?php foreach ($panelKartlar as $key => $kart):
                $renk = $kartRenkler[$key] ?? 'var(--ink2)';
                $secenekler = $veriSecenekleri[$key] ?? [];
                $aktif = $kart['aktif'];
              ?>
              <div class="pkart-row <?= $aktif ? 'pkart-aktif' : '' ?>" id="pkart-<?= $key ?>">
                <div class="pkart-sol">
                  <label class="pkart-toggle" title="<?= $aktif ? 'Gizle' : 'Göster' ?>">
                    <input type="checkbox" name="kart_aktif[<?= $key ?>]" value="1"
                      <?= $aktif ? 'checked' : '' ?>
                      onchange="pkartToggle('<?= $key ?>', this.checked)">
                    <span class="pkart-sw"></span>
                  </label>
                  <div class="pkart-ico" style="color:<?= $renk ?>">
                    <div class="pkart-color-dot" style="background:<?= $renk ?>"></div>
                  </div>
                  <div class="pkart-bilgi">
                    <div class="pkart-ad"><?= htmlspecialchars($kart['etiket']) ?></div>
                    <div class="pkart-durum"><?= $aktif ? 'Panelde görünüyor' : 'Gizli' ?></div>
                  </div>
                </div>
                <?php if (count($secenekler) > 1): ?>
                <div class="pkart-sag">
                  <select name="kart_veri[<?= $key ?>]" class="form-input" style="font-size:.78rem;padding:.3rem .6rem;height:auto">
                    <?php foreach ($secenekler as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= $kart['veri']===$val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <?php else: ?>
                <input type="hidden" name="kart_veri[<?= $key ?>]" value="<?= array_key_first($secenekler) ?>">
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>

            <div style="margin-top:1.1rem;display:flex;align-items:center;gap:.75rem">
              <button type="submit" class="btn btn-primary">Kaydet</button>
              <span style="font-size:.78rem;color:var(--ink3)">Değişiklikler panele hemen yansır.</span>
            </div>
          </form>
        </div>
      </div>

      <!-- Hatırlatıcı Panel Kartı -->
      <div class="panel" style="margin-bottom:1.25rem">
        <div class="panel-head">
          <div class="panel-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            Hatırlatıcı Panel Kartı
          </div>
        </div>
        <div style="padding:1.25rem">
          <?php if ($panelHatirlaticiSuccess): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($panelHatirlaticiSuccess) ?></div><?php endif; ?>
          <div style="font-size:.8rem;color:var(--ink3);margin-bottom:1rem">Panelde bugünün hatırlatıcılarını gösteren kartın görünüp görünmeyeceğini ayarlayın.</div>
          <form method="post" action="/profil">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="form" value="panel-hatirlatici">
            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;user-select:none">
              <input type="checkbox" name="panel_hatirlatici_aktif" value="1" <?= $panelHatirlaticiAktif ? 'checked' : '' ?> style="width:16px;height:16px;cursor:pointer">
              <span style="font-size:.88rem">Panelde "Bugünün Hatırlatıcıları" kartını göster</span>
            </label>
            <div style="margin-top:1rem">
              <button type="submit" class="btn btn-primary">Kaydet</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Lisans Durumu -->
      <div class="panel" style="margin-bottom:1.25rem">
        <div class="panel-head">
          <div class="panel-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--green)"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Lisans Durumu
          </div>
        </div>
        <div style="padding:1.25rem">
          <?php if ($user['demo_mod']): ?>
            <div class="alert alert-info" style="margin-bottom:1rem">Demo modunda çalışıyorsunuz.</div>
            <a href="/lisans" class="btn btn-primary">Lisans Gir &amp; Aktifleştir</a>
          <?php elseif ($user['lisans_aktif']): ?>
            <div class="alert alert-success" style="margin-bottom:1rem">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
              Lisansınız aktif ve geçerli.
            </div>
            <?php if ($user['lisans_kodu']): ?>
              <div style="display:flex;align-items:center;gap:.75rem;background:var(--surface2);border:1px solid var(--border);border-radius:var(--r);padding:.65rem .85rem">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                <span style="font-family:monospace;font-size:.88rem;color:var(--ink2);letter-spacing:.06em"><?= htmlspecialchars($user['lisans_kodu']) ?></span>
                <span class="badge badge-green ms-auto">Aktif</span>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="alert alert-error" style="margin-bottom:1rem">Lisans aktif değil.</div>
            <a href="/lisans" class="btn btn-secondary">Lisans Gir</a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Firma Adı (Fiş Başlığı) -->
      <div class="panel" style="margin-bottom:1.25rem">
        <div class="panel-head">
          <div class="panel-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--green)"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Firma Adı (Fiş Başlığı)
          </div>
        </div>
        <div style="padding:1.25rem">
          <?php if ($firmaError):   ?><div class="alert alert-error"   style="margin-bottom:1rem"><?= htmlspecialchars($firmaError) ?></div><?php endif; ?>
          <?php if ($firmaSuccess): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($firmaSuccess) ?></div><?php endif; ?>
          <form method="post" action="/profil">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="form" value="firma">
            <div class="form-group">
              <label class="form-label">Firma / İşletme Adı</label>
              <input type="text" name="firma_adi" class="form-input" value="<?= htmlspecialchars($firmaAdi) ?>" placeholder="Fişte görünecek işletme adı">
              <div style="font-size:.78rem;color:var(--ink3);margin-top:.3rem">Boş bırakılırsa fişte "veresiyem" yazacak.</div>
            </div>
            <button type="submit" class="btn btn-primary">Kaydet</button>
          </form>
        </div>
      </div>

      <!-- Fiş Ayarları -->
      <div class="panel" style="margin-bottom:1.25rem">
        <div class="panel-head">
          <div class="panel-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--blue)"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Fiş Ayarları
          </div>
        </div>
        <div style="padding:1.25rem">
          <?php if ($fisError):   ?><div class="alert alert-error"   style="margin-bottom:1rem"><?= htmlspecialchars($fisError) ?></div><?php endif; ?>
          <?php if ($fisSuccess): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($fisSuccess) ?></div><?php endif; ?>
          <form method="post" action="/profil">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="form" value="fis-ayar">

            <!-- Fiş Boyutu -->
            <div class="form-group" style="margin-bottom:1.1rem">
              <label class="form-label">Fiş Boyutu</label>
              <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:.35rem">
                <?php foreach (['80mm' => '80mm — Standart termal', '58mm' => '58mm — Dar termal'] as $val => $lbl): ?>
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;padding:.55rem .85rem;border:1.5px solid <?= $fisAyarlar['boyut']===$val ? 'var(--blue)' : 'var(--border)' ?>;border-radius:var(--r);background:<?= $fisAyarlar['boyut']===$val ? 'var(--blue-lt)' : 'var(--surface2)' ?>;font-size:.85rem;font-weight:600;transition:.12s" onclick="secBoyut('<?= $val ?>')">
                  <input type="radio" name="fis_boyut" value="<?= $val ?>" <?= $fisAyarlar['boyut']===$val ? 'checked' : '' ?> style="accent-color:var(--blue)">
                  <?= $lbl ?>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Fiş Şablonu -->
            <div class="form-group" style="margin-bottom:1.25rem">
              <label class="form-label">Fiş Şablonu</label>
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.65rem;margin-top:.35rem">

                <?php
                $sablonlar = [
                  'standart' => ['ad' => 'Standart', 'ac' => 'Firma adı, satış türü rozeti, kalem listesi, toplam ve teşekkür notu.'],
                  'detayli'  => ['ad' => 'Detaylı',  'ac' => 'Tarih, saat, birim fiyat, kalem toplamları ve alt bilgi satırı.'],
                  'minimal'  => ['ad' => 'Minimal',  'ac' => 'Yalnızca müşteri, tarih, satış türü ve genel toplam.'],
                ];
                foreach ($sablonlar as $val => $s): $aktif = $fisAyarlar['sablon'] === $val; ?>
                <label style="display:flex;flex-direction:column;gap:.3rem;cursor:pointer;padding:.75rem;border:1.5px solid <?= $aktif ? 'var(--blue)' : 'var(--border)' ?>;border-radius:var(--r);background:<?= $aktif ? 'var(--blue-lt)' : 'var(--surface2)' ?>;transition:.12s" onclick="secSablon('<?= $val ?>')">
                  <div style="display:flex;align-items:center;gap:.4rem">
                    <input type="radio" name="fis_sablon" value="<?= $val ?>" <?= $aktif ? 'checked' : '' ?> style="accent-color:var(--blue)">
                    <span style="font-size:.85rem;font-weight:700;color:var(--ink)"><?= $s['ad'] ?></span>
                  </div>
                  <span style="font-size:.75rem;color:var(--ink3);line-height:1.4"><?= $s['ac'] ?></span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Önizleme -->
            <div style="margin-bottom:1rem">
              <label class="form-label">Önizleme</label>
              <div style="margin-top:.4rem;display:flex;align-items:flex-start;gap:.75rem;flex-wrap:wrap">
                <div style="background:#fff;border:1px solid var(--border);border-radius:var(--r);overflow:hidden">
                  <iframe id="fisOnizlemeFrame" src="about:blank" style="display:block;border:none;width:230px;height:380px"></iframe>
                </div>
                <button type="button" onclick="onizlemeGoster()" class="btn btn-ghost btn-sm" style="margin-top:.25rem">Önizle</button>
              </div>
            </div>

            <button type="submit" class="btn btn-primary">Kaydet</button>
          </form>
        </div>
      </div>

      <!-- Kullanıcı Adı -->
      <div class="panel" style="margin-bottom:1.25rem">
        <div class="panel-head">
          <div class="panel-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--blue)"><circle cx="12" cy="8" r="4"/><path d="M6 20v-1a6 6 0 0 1 12 0v1"/></svg>
            Kullanıcı Adı
          </div>
        </div>
        <div style="padding:1.25rem">
          <?php if ($adError):   ?><div class="alert alert-error"   style="margin-bottom:1rem"><?= htmlspecialchars($adError) ?></div><?php endif; ?>
          <?php if ($adSuccess): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($adSuccess) ?></div><?php endif; ?>
          <form method="post" action="/profil">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="form" value="ad">
            <div class="form-group">
              <label class="form-label">Ad Soyad / İşletme Adı</label>
              <input type="text" name="ad" class="form-input" value="<?= htmlspecialchars($user['ad']) ?>" required>
            </div>
            <button type="submit" class="btn btn-primary">Güncelle</button>
          </form>
        </div>
      </div>

      <!-- Şifre -->
      <div class="panel">
        <div class="panel-head">
          <div class="panel-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--gold)"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Şifre Değiştir
          </div>
        </div>
        <div style="padding:1.25rem">
          <?php if ($sifreError):   ?><div class="alert alert-error"   style="margin-bottom:1rem"><?= htmlspecialchars($sifreError) ?></div><?php endif; ?>
          <?php if ($sifreSuccess): ?><div class="alert alert-success" style="margin-bottom:1rem"><?= htmlspecialchars($sifreSuccess) ?></div><?php endif; ?>
          <form method="post" action="/profil">
            <input type="hidden" name="csrf" value="<?= csrf() ?>">
            <input type="hidden" name="form" value="sifre">
            <div class="form-group">
              <label class="form-label">Mevcut Şifre</label>
              <input type="password" name="mevcut_sifre" class="form-input" required autocomplete="current-password">
            </div>
            <div class="form-group">
              <label class="form-label">Yeni Şifre</label>
              <input type="password" name="yeni_sifre" class="form-input" required autocomplete="new-password" minlength="6">
            </div>
            <div class="form-group">
              <label class="form-label">Yeni Şifre Tekrar</label>
              <input type="password" name="yeni_sifre_tekrar" class="form-input" required autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary">Şifreyi Değiştir</button>
          </form>
        </div>
      </div>

      <!-- Yedek & Geri Yükleme -->
      <div class="panel" style="margin-top:1.25rem">
        <div class="panel-head">
          <div class="panel-title">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--blue)"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            Veri Yedekleme
          </div>
        </div>
        <div style="padding:1.25rem">
          <div style="font-size:.82rem;color:var(--ink2);margin-bottom:1rem">
            Tüm cari, hareket ve stok verilerinizi tek bir dosyaya yedekleyin. Yedek dosyasını güvenli bir yere kaydedin.
          </div>
          <div style="display:flex;gap:.75rem;flex-wrap:wrap">
            <button id="btnYedekAl" class="btn btn-primary" onclick="yedekAl()">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
              Yedek Al
            </button>
            <button id="btnGeriYukle" class="btn btn-secondary" onclick="geriYukle()" style="border-color:var(--gold);color:var(--gold)">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 16 12 21 17 16"/><line x1="12" y1="21" x2="12" y2="9"/></svg>
              Yedekten Geri Yükle
            </button>
          </div>
          <div id="yedekMesaj" style="margin-top:.75rem;font-size:.82rem;display:none"></div>
        </div>
      </div>

    </div>
  </main>
</div>
<script src="/assets/js/fis.js"></script>
<script>
function pkartToggle(key, aktif) {
  var row = document.getElementById('pkart-' + key);
  if (!row) return;
  var durum = row.querySelector('.pkart-durum');
  if (aktif) {
    row.classList.add('pkart-aktif');
    if (durum) durum.textContent = 'Panelde görünüyor';
  } else {
    row.classList.remove('pkart-aktif');
    if (durum) durum.textContent = 'Gizli';
  }
}

function secBoyut(val) {
  document.querySelector('input[name=fis_boyut][value="'+val+'"]').checked = true;
  document.querySelectorAll('label[onclick^="secBoyut"]').forEach(function(l) {
    var v = l.querySelector('input').value;
    var aktif = v === val;
    l.style.borderColor = aktif ? 'var(--blue)' : 'var(--border)';
    l.style.background  = aktif ? 'var(--blue-lt)' : 'var(--surface2)';
  });
}
function secSablon(val) {
  document.querySelector('input[name=fis_sablon][value="'+val+'"]').checked = true;
  document.querySelectorAll('label[onclick^="secSablon"]').forEach(function(l) {
    var v = l.querySelector('input').value;
    var aktif = v === val;
    l.style.borderColor = aktif ? 'var(--blue)' : 'var(--border)';
    l.style.background  = aktif ? 'var(--blue-lt)' : 'var(--surface2)';
  });
}
function onizlemeGoster() {
  var boyut  = document.querySelector('input[name=fis_boyut]:checked')?.value  || '80mm';
  var sablon = document.querySelector('input[name=fis_sablon]:checked')?.value || 'standart';

  window._fisAyar  = { boyut: boyut, sablon: sablon };
  window._firmaAdi = <?= json_encode($firmaAdi ?: 'Veresiyem', JSON_UNESCAPED_UNICODE) ?>;

  var ornek = {
    tip:        'borc',
    cari_ad:    'Örnek Müşteri',
    tarih:      '2026-09-20',
    vade:       '2026-10-20',
    kalan_borc: 3250,
    kalemler: [
      { aciklama: 'Ürün A', tutar: 100, miktar: 2 },
      { aciklama: 'Ürün B', tutar: 50,  miktar: 1 }
    ]
  };

  var html = veresiyemFisHtml(ornek);
  var frame = document.getElementById('fisOnizlemeFrame');
  try {
    frame.contentDocument.open();
    frame.contentDocument.write(html);
    frame.contentDocument.close();
  } catch(e) {
    frame.srcdoc = html;
  }
}
// Sayfa açılınca otomatik önizle
document.addEventListener('DOMContentLoaded', onizlemeGoster);

function yedekAl() {
  var btn = document.getElementById('btnYedekAl');
  var msg = document.getElementById('yedekMesaj');
  if (!window.electronAPI) { msg.style.display='block'; msg.style.color='var(--red)'; msg.textContent='Bu özellik sadece masaüstü uygulamada çalışır.'; return; }
  btn.disabled = true; btn.textContent = 'Kaydediliyor…';
  window.electronAPI.backupDb().then(function(res) {
    btn.disabled = false;
    btn.innerHTML = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Yedek Al';
    msg.style.display = 'block';
    if (res.ok) {
      msg.style.color = 'var(--green)';
      msg.textContent = '✓ Yedek kaydedildi: ' + res.filePath;
    } else {
      msg.style.color = 'var(--ink3)';
      msg.textContent = 'İptal edildi.';
    }
  });
}
function geriYukle() {
  var btn = document.getElementById('btnGeriYukle');
  var msg = document.getElementById('yedekMesaj');
  if (!window.electronAPI) { msg.style.display='block'; msg.style.color='var(--red)'; msg.textContent='Bu özellik sadece masaüstü uygulamada çalışır.'; return; }
  window.veresiyemConfirm({
    title: 'Geri Yükle',
    msg: 'Mevcut verilerinizin üzerine yazılacak! Devam etmek istiyor musunuz?',
    okLabel: 'Evet, Geri Yükle',
    type: 'gold'
  }).then(function(onay) {
    if (!onay) return;
    btn.disabled = true; btn.textContent = 'Yükleniyor…';
    window.electronAPI.restoreDb().then(function(res) {
      btn.disabled = false;
      btn.innerHTML = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 16 12 21 17 16"/><line x1="12" y1="21" x2="12" y2="9"/></svg> Yedekten Geri Yükle';
      msg.style.display = 'block';
      if (res.ok) {
        msg.style.color = 'var(--green)';
        msg.textContent = '✓ Geri yüklendi. Sayfa yenileniyor…';
        setTimeout(function(){ location.reload(); }, 1500);
      } else if (res.error) {
        msg.style.color = 'var(--red)';
        msg.textContent = 'Hata: ' + res.error;
      } else {
        msg.style.color = 'var(--ink3)';
        msg.textContent = 'İptal edildi.';
      }
    });
  });
}
</script>
</body>
</html>
