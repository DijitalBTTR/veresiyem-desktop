<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
girisGerekli();

$uid = userId();

// Carileri VPS'ten çek (panel açılışında)
require_once 'includes/sync.php';
syncCariler($pdo, $uid);

// Online girişten sonra token kaydet
if (!empty($_GET['savetoken'])) {
    $token = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['savetoken']);
    if ($token) {
        $_SESSION['api_token'] = $token;
        // userData dosyasına kaydet — PHP tarafından yazamıyoruz, JS çağrısı yapacak
        $redirect = preg_replace('/[^a-zA-Z0-9\/?=&_-]/', '', $_GET['redirect'] ?? '/');
        if (!$redirect || $redirect[0] !== '/') $redirect = '/';
        echo '<script>
        (async function(){
          if(window.electronAPI && window.electronAPI.saveApiToken){
            await window.electronAPI.saveApiToken(' . json_encode($token) . ');
          }
          window.location.replace(' . json_encode($redirect) . ');
        })();
        </script>';
        exit;
    }
}

// VPS'ten özet çek (tahsilatlar dahil güncel bakiye)
$vpsOzet = vpsCek('/cariler/ozet');

// Stat kartları
$stats = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN h.tip='alacak' THEN h.tutar ELSE 0 END),0) AS toplam_alacak,
        COALESCE(SUM(CASE WHEN h.tip='borc'   THEN h.tutar ELSE 0 END),0) AS toplam_borc,
        COUNT(DISTINCT c.id) AS cari_sayisi
    FROM cariler c
    LEFT JOIN hareketler h ON h.cari_id = c.id
    WHERE c.user_id = ?
");
$stats->execute([$uid]);
$stats = $stats->fetch();

// VPS verisi varsa kartları VPS'ten al (tahsilat dahil doğru net)
if ($vpsOzet && isset($vpsOzet['toplam_borc'])) {
    $stats['toplam_borc']   = $vpsOzet['toplam_borc'];
    $stats['toplam_alacak'] = $vpsOzet['toplam_alacak'];
    $stats['net_bakiye']    = $vpsOzet['net'] ?? ($vpsOzet['toplam_alacak'] - ($vpsOzet['toplam_borc'] - ($vpsOzet['toplam_tahsilat'] ?? 0)));
    $net = $stats['net_bakiye'];
} else {
    $net = $stats['toplam_alacak'] - $stats['toplam_borc'];
}
$toplamSatis = $stats['toplam_alacak'] + $stats['toplam_borc'];
$nakitYuzde  = $toplamSatis > 0 ? round($stats['toplam_alacak'] / $toplamSatis * 100, 1) : 0;
$vadeliYuzde = $toplamSatis > 0 ? round($stats['toplam_borc']   / $toplamSatis * 100, 1) : 0;

// Bu ay
$buAy = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN h.tip='borc'   THEN h.tutar ELSE 0 END),0) AS tahsilat,
        COALESCE(SUM(CASE WHEN h.tip='alacak' THEN h.tutar ELSE 0 END),0) AS alacak
    FROM hareketler h
    JOIN cariler c ON c.id = h.cari_id
    WHERE c.user_id = ?
      AND strftime('%Y-%m', h.tarih) = strftime('%Y-%m', 'now', 'localtime')
");
$buAy->execute([$uid]);
$buAy = $buAy->fetch();

// Geçen ay
$gecenAy = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN h.tip='alacak' THEN h.tutar ELSE 0 END),0) AS alacak,
        COALESCE(SUM(CASE WHEN h.tip='borc'   THEN h.tutar ELSE 0 END),0) AS tahsilat
    FROM hareketler h
    JOIN cariler c ON c.id = h.cari_id
    WHERE c.user_id = ?
      AND strftime('%Y-%m', h.tarih) = strftime('%Y-%m', 'now', 'localtime', '-1 month')
");
$gecenAy->execute([$uid]);
$gecenAy = $gecenAy->fetch();

// Son 12 hareket
$sonHareketler = $pdo->prepare("
    SELECT h.id, h.tip, h.tutar, h.tarih, h.aciklama, h.created_at,
           c.ad AS cari_ad, c.id AS cari_id
    FROM hareketler h
    JOIN cariler c ON c.id = h.cari_id
    WHERE c.user_id = ?
    ORDER BY h.created_at DESC, h.id DESC
    LIMIT 12
");
$sonHareketler->execute([$uid]);
$sonHareketler = $sonHareketler->fetchAll();

// Kritik stok (miktar <= 5)
$kritikStok = $pdo->prepare("SELECT id, ad, miktar, birim FROM stoklar WHERE user_id=? AND miktar <= 5 ORDER BY miktar ASC, ad LIMIT 5");
$kritikStok->execute([$uid]);
$kritikStok = $kritikStok->fetchAll();

// Vadesi geçmiş hareketler
$vadesiGecen = $pdo->prepare("
    SELECT h.id, h.tutar, h.vade_tarihi, h.aciklama, c.id AS cari_id, c.ad AS cari_ad, c.telefon
    FROM hareketler h
    JOIN cariler c ON c.id = h.cari_id
    WHERE c.user_id = ? AND h.tip = 'alacak'
      AND h.vade_tarihi IS NOT NULL AND h.vade_tarihi < date('now','localtime')
    ORDER BY h.vade_tarihi ASC
    LIMIT 6
");
$vadesiGecen->execute([$uid]);
$vadesiGecen = $vadesiGecen->fetchAll();

// Yüksek bakiyeli
$yuksekBakiye = $pdo->prepare("
    SELECT c.id, c.ad,
      COALESCE(SUM(CASE WHEN h.tip='alacak' THEN h.tutar ELSE 0 END),0) -
      COALESCE(SUM(CASE WHEN h.tip='borc'   THEN h.tutar ELSE 0 END),0) AS bakiye
    FROM cariler c
    LEFT JOIN hareketler h ON h.cari_id = c.id
    WHERE c.user_id = ?
    GROUP BY c.id
    HAVING bakiye > 0
    ORDER BY bakiye DESC
    LIMIT 5
");
$yuksekBakiye->execute([$uid]);
$yuksekBakiye = $yuksekBakiye->fetchAll();

// Sparkline için son 7 gün günlük alacak verisi
$sparkData = $pdo->prepare("
    SELECT strftime('%Y-%m-%d', h.tarih) AS gun,
           COALESCE(SUM(CASE WHEN h.tip='alacak' THEN h.tutar ELSE 0 END),0) AS toplam
    FROM hareketler h
    JOIN cariler c ON c.id = h.cari_id
    WHERE c.user_id = ?
      AND h.tarih >= date('now','localtime','-6 days')
    GROUP BY gun ORDER BY gun ASC
");
$sparkData->execute([$uid]);
$sparkRows = $sparkData->fetchAll();
$sparkMap = [];
foreach ($sparkRows as $sr) $sparkMap[$sr['gun']] = (float)$sr['toplam'];
$sparkValues = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $sparkValues[] = $sparkMap[$d] ?? 0;
}
$sparkJson = json_encode($sparkValues);

// Bugünün hatırlatıcıları
$bugunHatirlaticilar = $pdo->prepare("
    SELECT id, baslik, aciklama, tarih, saat, durum
    FROM hatirlaticilar
    WHERE user_id = ? AND tarih = date('now','localtime') AND durum = 'aktif'
    ORDER BY saat ASC
");
$bugunHatirlaticilar->execute([$uid]);
$bugunHatirlaticilar = $bugunHatirlaticilar->fetchAll();

// Panel hatırlatıcı kartı ayarı
$phRow = $pdo->prepare("SELECT deger FROM ayarlar WHERE user_id=? AND anahtar='panel_hatirlatici'");
$phRow->execute([$uid]);
$phRow = $phRow->fetch();
$panelHatirlaticiAktif = $phRow ? ($phRow['deger'] !== '0') : true;

// Panel kartları ayarı oku
$pkRow = $pdo->prepare("SELECT deger FROM ayarlar WHERE user_id=? AND anahtar='panel_kartlar'");
$pkRow->execute([$uid]);
$pkRow = $pkRow->fetch();
$pkJsonStr = $pkRow ? ($pkRow['deger'] ?? '') : '';
$pkVarsayilan = [
    'nakit_satis'  => ['aktif' => true,  'veri' => 'toplam_alacak'],
    'vadeli_satis' => ['aktif' => true,  'veri' => 'toplam_borc'],
    'toplam_satis' => ['aktif' => true,  'veri' => 'toplam_satis'],
    'net_bakiye'   => ['aktif' => true,  'veri' => 'net_bakiye'],
    'bu_ay_nakit'  => ['aktif' => false, 'veri' => 'bu_ay_alacak'],
    'bu_ay_vadeli' => ['aktif' => false, 'veri' => 'bu_ay_tahsilat'],
    'cari_sayisi'  => ['aktif' => false, 'veri' => 'cari_sayisi'],
];
$pkAyar = $pkVarsayilan;
if ($pkJsonStr) {
    $pkDecoded = json_decode($pkJsonStr, true);
    if (is_array($pkDecoded)) {
        foreach ($pkDecoded as $key => $val) {
            if (isset($pkAyar[$key])) {
                $pkAyar[$key]['aktif'] = (bool)($val['aktif'] ?? false);
                if (isset($val['veri'])) $pkAyar[$key]['veri'] = $val['veri'];
            }
        }
    }
}
// Veri havuzu — her veri tipi için değer
function pkVeri(string $key, array $stats, array $buAy): float {
    return match($key) {
        'toplam_alacak'   => (float)$stats['toplam_alacak'],
        'toplam_borc'     => (float)$stats['toplam_borc'],
        'toplam_satis'    => (float)$stats['toplam_alacak'] + (float)$stats['toplam_borc'],
        'net_bakiye'      => (float)($stats['net_bakiye'] ?? ((float)$stats['toplam_alacak'] - (float)$stats['toplam_borc'])),
        'bu_ay_alacak'    => (float)$buAy['alacak'],
        'bu_ay_tahsilat'  => (float)$buAy['tahsilat'],
        'cari_sayisi'     => (float)$stats['cari_sayisi'],
        default           => 0.0,
    };
}

$activePage = 'panel';
$pageTitle  = 'Panel';

$avatarRenkler = ['#DCFCE7:#16A34A','#DBEAFE:#2563EB','#FEF3C7:#D97706','#FEE2E2:#DC2626','#F3E8FF:#7C3AED','#FFEDD5:#EA580C'];
function avatarRenk(int $id, array $renkler): array {
    $r = explode(':', $renkler[$id % count($renkler)]);
    return ['bg'=>$r[0], 'color'=>$r[1]];
}
function harfler(string $ad): string {
    $words = explode(' ', trim($ad));
    $h = mb_strtoupper(mb_substr($words[0], 0, 1, 'UTF-8'), 'UTF-8');
    if (count($words) > 1) $h .= mb_strtoupper(mb_substr($words[1], 0, 1, 'UTF-8'), 'UTF-8');
    return $h;
}
function degisimYuzde(float $yeni, float $eski): string {
    if ($eski == 0) return $yeni > 0 ? '+100%' : '0%';
    $p = (($yeni - $eski) / $eski) * 100;
    return ($p >= 0 ? '+' : '') . number_format($p, 1) . '%';
}
function degisimSinif(float $yeni, float $eski): string {
    if ($eski == 0) return $yeni >= 0 ? 'up' : 'down';
    return $yeni >= $eski ? 'up' : 'down';
}

$alacakDeg = degisimYuzde($stats['toplam_alacak'], $gecenAy['alacak']);
$alacakSinif = degisimSinif($stats['toplam_alacak'], $gecenAy['alacak']);
$tahsilatDeg = degisimYuzde($buAy['tahsilat'], $gecenAy['tahsilat']);
$tahsilatSinif = degisimSinif($buAy['tahsilat'], $gecenAy['tahsilat']);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require 'includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require 'includes/sidebar.php'; ?>
  <main class="main-content">

    <!-- Page Header -->
    <div class="page-header">
      <div class="page-header-left">
        <div class="page-title">Panel</div>
        <div class="page-sub"><?= date('d F Y, l') ?></div>
      </div>
      <div class="page-header-center">
        <div class="search-bar" id="searchWrap" style="position:relative">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
          <input type="text" placeholder="Cari, işlem veya stok ara..." class="search-input" id="searchInput" autocomplete="off">
          <span class="search-shortcut" id="searchShortcut">Ctrl+F</span>
          <div id="searchDropdown" class="search-dropdown" style="display:none"></div>
        </div>
      </div>
      <div class="page-header-right">
        <button class="header-icon-btn" title="Bildirimler">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
          <?php if (!empty($vadesiGecen)): ?>
          <span class="notif-dot"></span>
          <?php endif; ?>
        </button>
        <a href="/cari-ekle" class="btn btn-primary btn-sm">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Yeni Cari
        </a>
      </div>
    </div>

    <div class="page-body">

      <?php if (isset($_GET['lisans']) && $_GET['lisans'] === 'ok'): ?>
      <div class="alert alert-success" style="margin-bottom:1rem">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        Lisansınız başarıyla aktifleştirildi. Hoş geldiniz!
      </div>
      <?php endif; ?>

      <?php if (demoMod()): ?>
      <div class="demo-banner">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
        <span><strong>Demo Modu</strong> — Veriler örnek içeriktir. <a href="/lisans">Lisans girerek</a> kendi verilerinizle başlayın.</span>
      </div>
      <?php endif; ?>

      <!-- Stat kartları -->
      <?php
      // Aktif kart sayısına göre grid sınıfı
      $aktifKartSayisi = count(array_filter($pkAyar, fn($k) => $k['aktif']));
      ?>
      <div class="stat-grid" style="<?= $aktifKartSayisi > 0 ? 'grid-template-columns:repeat(auto-fill,minmax(200px,1fr))' : '' ?>">

      <?php foreach ($pkAyar as $pkKey => $pk):
        if (!$pk['aktif']) continue;
        $pkVeriKey = $pk['veri'];
        $pkDeger = pkVeri($pkVeriKey, $stats, $buAy);

        // Kart rengi ve içeriği
        $pkRenk = match($pkKey) {
          'nakit_satis'  => 'green',
          'vadeli_satis' => 'blue',
          'toplam_satis' => 'gold',
          'net_bakiye'   => ($pkDeger >= 0 ? 'green' : 'red'),
          'bu_ay_nakit'  => 'green',
          'bu_ay_vadeli' => 'blue',
          'cari_sayisi'  => 'blue',
          default        => 'green',
        };
        $pkRenkHex = match($pkRenk) {
          'green' => '#16A34A', 'blue' => '#2563EB', 'gold' => '#D97706',
          'red'   => '#DC2626', default => '#16A34A',
        };
        $pkEtiket = match($pkKey) {
          'nakit_satis'  => 'Nakit Satış',
          'vadeli_satis' => 'Vadeli Satış',
          'toplam_satis' => 'Toplam Satış',
          'net_bakiye'   => 'Net Bakiye',
          'bu_ay_nakit'  => 'Bu Ay Nakit',
          'bu_ay_vadeli' => 'Bu Ay Vadeli',
          'cari_sayisi'  => 'Cari Sayısı',
          default        => 'Kart',
        };
        $pkIkon = match($pkKey) {
          'nakit_satis'  => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>',
          'vadeli_satis' => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>',
          'toplam_satis' => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
          'net_bakiye'   => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>',
          'bu_ay_nakit'  => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
          'bu_ay_vadeli' => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
          'cari_sayisi'  => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
          default        => '',
        };
      ?>
        <div class="stat-card <?= $pkRenk ?>">
          <div class="stat-card-inner">
            <div class="stat-body">
              <div class="stat-label"><?= $pkEtiket ?></div>
              <?php if ($pkKey === 'cari_sayisi'): ?>
              <div class="stat-val <?= $pkRenk ?>"><?= number_format($pkDeger, 0, ',', '.') ?></div>
              <div class="stat-meta">
                <span class="stat-sub">kayıtlı müşteri</span>
              </div>
              <?php elseif ($pkKey === 'toplam_satis'): ?>
              <div class="stat-val <?= $pkRenk ?>">₺<?= number_format($pkDeger, 2, ',', '.') ?></div>
              <div class="stat-meta" style="gap:.4rem;flex-direction:column;align-items:flex-start">
                <div style="display:flex;gap:.75rem;font-size:.72rem">
                  <span style="color:var(--green);font-weight:600">%<?= $nakitYuzde ?> Nakit</span>
                  <span style="color:var(--blue);font-weight:600">%<?= $vadeliYuzde ?> Vadeli</span>
                </div>
                <div style="width:100%;height:5px;border-radius:3px;background:var(--surface2);overflow:hidden">
                  <div style="height:100%;width:<?= $nakitYuzde ?>%;background:var(--green);border-radius:3px;transition:.4s"></div>
                </div>
              </div>
              <?php elseif ($pkKey === 'net_bakiye'): ?>
              <div class="stat-val <?= $pkRenk ?>">₺<?= number_format(abs($pkDeger), 2, ',', '.') ?></div>
              <div class="stat-meta">
                <span class="stat-sub"><?= $pkDeger >= 0 ? 'Nakit Satış fazlası' : 'Vadeli Satış fazlası' ?></span>
              </div>
              <?php elseif ($pkKey === 'nakit_satis'): ?>
              <div class="stat-val <?= $pkRenk ?>">₺<?= number_format($pkDeger, 2, ',', '.') ?></div>
              <div class="stat-meta">
                <span class="stat-sub"><?= $pkVeriKey === 'bu_ay_alacak' ? 'bu ay' : ($stats['cari_sayisi'].' aktif cari') ?></span>
                <span class="stat-change <?= $alacakSinif ?>">
                  <?= $alacakSinif === 'up' ? '↑' : '↓' ?> <?= $alacakDeg ?>
                </span>
              </div>
              <?php elseif ($pkKey === 'vadeli_satis'): ?>
              <div class="stat-val <?= $pkRenk ?>">₺<?= number_format($pkDeger, 2, ',', '.') ?></div>
              <div class="stat-meta">
                <span class="stat-sub"><?= $pkVeriKey === 'bu_ay_tahsilat' ? 'bu ay' : 'veresiye toplam' ?></span>
                <span class="stat-change <?= $tahsilatSinif ?>">
                  <?= $tahsilatSinif === 'up' ? '↑' : '↓' ?> <?= $tahsilatDeg ?>
                </span>
              </div>
              <?php else: ?>
              <div class="stat-val <?= $pkRenk ?>">₺<?= number_format($pkDeger, 2, ',', '.') ?></div>
              <div class="stat-meta">
                <span class="stat-sub"><?= $pkEtiket ?></span>
              </div>
              <?php endif; ?>
            </div>
            <div class="stat-ico <?= $pkRenk ?>"><?= $pkIkon ?></div>
          </div>
          <canvas class="sparkline" data-values='<?= $sparkJson ?>' data-color="<?= $pkRenkHex ?>"></canvas>
        </div>
      <?php endforeach; ?>

      <?php if ($aktifKartSayisi === 0): ?>
        <div style="padding:1.5rem;color:var(--ink3);font-size:.85rem;grid-column:1/-1">
          Hiç kart aktif değil. <a href="/profil" style="color:var(--green)">Profil &amp; Ayarlar</a> sayfasından kartları açabilirsiniz.
        </div>
      <?php endif; ?>

      </div>

      <!-- Ana grid -->
      <div class="dash-grid">
        <!-- Sol: Son hareketler -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">
              <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--green)"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              Son Hareketler
            </div>
            <a href="/cariler" class="btn btn-sm btn-secondary">Tümünü Gör</a>
          </div>
          <?php if (empty($sonHareketler)): ?>
          <div class="empty-state">
            <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <h3>Henüz hareket yok</h3>
            <p>İlk carinizi ekleyin ve kayıt tutmaya başlayın.</p>
            <a href="/cari-ekle" class="btn btn-primary btn-sm">Cari Ekle</a>
          </div>
          <?php else: ?>
          <?php foreach ($sonHareketler as $h): ?>
          <div class="sh-row">
            <div class="sh-icon-circle <?= $h['tip'] ?>">
              <?php if ($h['tip'] === 'alacak'): ?>
              <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
              <?php else: ?>
              <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
              <?php endif; ?>
            </div>
            <div class="sh-info">
              <div class="sh-name"><a href="/cari?id=<?= $h['cari_id'] ?>"><?= htmlspecialchars($h['cari_ad']) ?></a></div>
              <div class="sh-desc"><?= htmlspecialchars($h['aciklama'] ?: ($h['tip'] === 'alacak' ? 'Veresiye kaydı' : 'Ödeme alındı')) ?></div>
            </div>
            <div class="sh-right">
              <div class="sh-amount <?= $h['tip'] ?>"><?= $h['tip']==='alacak' ? '+' : '−' ?>₺<?= number_format($h['tutar'], 2, ',', '.') ?></div>
              <div style="display:flex;align-items:center;gap:.35rem;justify-content:flex-end;margin-top:.2rem">
                <div class="sh-date"><?= date('d M H:i', strtotime($h['created_at'] ?: $h['tarih'])) ?></div>
                <span class="sh-badge <?= $h['tip'] ?>"><?= $h['tip'] === 'alacak' ? 'Nakit Satış' : 'Vadeli Satış' ?></span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Sağ kolon -->
        <div class="dash-right">

          <!-- Yüksek bakiye -->
          <div class="panel">
            <div class="panel-head">
              <div class="panel-title">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:var(--gold)"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                Yüksek Bakiye
              </div>
              <a href="/cariler" class="btn btn-sm btn-ghost">Hepsi</a>
            </div>
            <?php if (empty($yuksekBakiye)): ?>
            <div class="empty-state" style="padding:1.5rem">
              <p style="max-width:none">Henüz alacak yok.</p>
            </div>
            <?php else: ?>
            <?php foreach ($yuksekBakiye as $i => $yb):
              $rk = avatarRenk($i, $avatarRenkler); ?>
            <div class="sh-row">
              <div class="sh-avatar-initials" style="background:<?= $rk['bg'] ?>;color:<?= $rk['color'] ?>">
                <?= harfler($yb['ad']) ?>
              </div>
              <div class="sh-info">
                <div class="sh-name"><a href="/cari?id=<?= $yb['id'] ?>"><?= htmlspecialchars($yb['ad']) ?></a></div>
              </div>
              <div class="sh-amount alacak">₺<?= number_format($yb['bakiye'], 2, ',', '.') ?></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Vadesi Geçenler -->
          <?php if (!empty($vadesiGecen)): ?>
          <div class="panel">
            <div class="panel-head">
              <div class="panel-title" style="color:var(--red);display:flex;align-items:center;gap:.4rem">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Vadesi Geçenler
              </div>
              <span class="badge badge-red"><?= count($vadesiGecen) ?></span>
            </div>
            <?php foreach ($vadesiGecen as $vg):
              $gecenGun = (int)((time() - strtotime($vg['vade_tarihi'])) / 86400);
            ?>
            <div class="sh-row">
              <div class="sh-icon-circle" style="background:var(--red-lt);color:var(--red)">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              </div>
              <div class="sh-info">
                <div class="sh-name"><a href="/cari?id=<?= $vg['cari_id'] ?>"><?= htmlspecialchars($vg['cari_ad']) ?></a></div>
                <div class="sh-desc"><?= $gecenGun ?> gün geçti · <?= date('d.m.Y', strtotime($vg['vade_tarihi'])) ?></div>
              </div>
              <div class="sh-right">
                <div class="sh-amount" style="color:var(--red)">₺<?= number_format($vg['tutar'], 2, ',', '.') ?></div>
                <?php if ($vg['telefon']): ?>
                <a href="https://wa.me/90<?= preg_replace('/[^0-9]/', '', ltrim($vg['telefon'], '0')) ?>?text=<?= urlencode('Sayın ' . $vg['cari_ad'] . ', vadesi geçmiş ₺' . number_format($vg['tutar'],2,',','.') . ' tutarında borcunuz bulunmaktadır.') ?>"
                   target="_blank" class="btn btn-sm" style="background:#25D366;color:#fff;padding:.2rem .5rem;font-size:.65rem;margin-top:.2rem">WA</a>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Kritik stok -->
          <?php if (!empty($kritikStok)): ?>
          <div class="panel">
            <div class="panel-head">
              <div class="panel-title" style="color:var(--gold);display:flex;align-items:center;gap:.4rem">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                Kritik Stok
              </div>
              <a href="/stok" class="btn btn-sm btn-ghost">Stok</a>
            </div>
            <?php foreach ($kritikStok as $ks): ?>
            <div class="sh-row">
              <div class="sh-icon-circle" style="background:var(--gold-lt);color:var(--gold)">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8"/></svg>
              </div>
              <div class="sh-info">
                <div class="sh-name"><?= htmlspecialchars($ks['ad']) ?></div>
                <div class="sh-desc"><?= number_format($ks['miktar'],2,',','.') ?> <?= htmlspecialchars($ks['birim']) ?> kaldı</div>
              </div>
              <a href="/stok-duzenle?id=<?= $ks['id'] ?>" class="btn btn-sm btn-secondary" style="flex-shrink:0">Güncelle</a>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- Bugünün Hatırlatıcıları -->
          <?php if ($panelHatirlaticiAktif): ?>
          <div class="panel" id="panelHatirlatici">
            <div class="panel-head">
              <div class="panel-title" style="display:flex;align-items:center;gap:.4rem">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                Bugünün Hatırlatıcıları
              </div>
              <a href="/hatirlaticilar" class="btn btn-sm btn-ghost">Hepsi</a>
            </div>
            <?php if (empty($bugunHatirlaticilar)): ?>
            <div class="empty-state" style="padding:1.5rem">
              <p style="max-width:none">Bugün için hatırlatıcı yok.</p>
            </div>
            <?php else: ?>
            <?php foreach ($bugunHatirlaticilar as $hr): ?>
            <div class="sh-row">
              <div class="sh-icon-circle" style="background:#EFF6FF;color:#2563EB">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
              </div>
              <div class="sh-info">
                <div class="sh-name"><?= htmlspecialchars($hr['baslik']) ?></div>
                <div class="sh-desc"><?= htmlspecialchars($hr['saat']) ?><?= $hr['aciklama'] ? ' · ' . htmlspecialchars(mb_substr($hr['aciklama'],0,40)) : '' ?></div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>

        </div><!-- /sağ kolon -->
      </div><!-- /dash-grid -->

    </div><!-- /page-body -->
  </main>
</div>

<!-- Hatırlatıcı Pop-up Modal (index'te artık sidebar'dan geliyor, bu blok kaldırıldı) -->
<?php /* modal sidebar.php'de */ ?>

<script>
// Sparkline canvas çizimi
document.querySelectorAll('.sparkline').forEach(canvas => {
  const values = JSON.parse(canvas.dataset.values || '[]');
  const color  = canvas.dataset.color || '#16A34A';
  if (!values.length) return;

  const dpr = window.devicePixelRatio || 1;
  const w = canvas.offsetWidth  || 120;
  const h = canvas.offsetHeight || 40;
  canvas.width  = w * dpr;
  canvas.height = h * dpr;
  const ctx = canvas.getContext('2d');
  ctx.scale(dpr, dpr);

  const max = Math.max(...values, 1);
  const min = Math.min(...values, 0);
  const range = max - min || 1;
  const pad = 4;

  const pts = values.map((v, i) => ({
    x: pad + (i / (values.length - 1 || 1)) * (w - pad * 2),
    y: h - pad - ((v - min) / range) * (h - pad * 2)
  }));

  // Fill gradient
  const grad = ctx.createLinearGradient(0, 0, 0, h);
  grad.addColorStop(0, color + '30');
  grad.addColorStop(1, color + '00');
  ctx.beginPath();
  ctx.moveTo(pts[0].x, h);
  pts.forEach(p => ctx.lineTo(p.x, p.y));
  ctx.lineTo(pts[pts.length - 1].x, h);
  ctx.closePath();
  ctx.fillStyle = grad;
  ctx.fill();

  // Line
  ctx.beginPath();
  pts.forEach((p, i) => i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y));
  ctx.strokeStyle = color;
  ctx.lineWidth = 1.5;
  ctx.lineJoin = 'round';
  ctx.stroke();

  // Last dot
  const last = pts[pts.length - 1];
  ctx.beginPath();
  ctx.arc(last.x, last.y, 3, 0, Math.PI * 2);
  ctx.fillStyle = color;
  ctx.fill();
});

// Arama
(function() {
  const input    = document.getElementById('searchInput');
  const dropdown = document.getElementById('searchDropdown');
  const shortcut = document.getElementById('searchShortcut');
  let timer = null;
  let activeIdx = -1;

  function kapat() {
    dropdown.style.display = 'none';
    dropdown.innerHTML = '';
    activeIdx = -1;
  }

  function ikonHtml(tip, htip) {
    if (tip === 'cari')   return '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>';
    if (tip === 'stok')   return '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8"/></svg>';
    if (tip === 'hareket') return htip === 'alacak'
      ? '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>'
      : '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>';
    return '';
  }

  function ikonRenk(tip, htip) {
    if (tip === 'cari')    return '#2563EB';
    if (tip === 'stok')    return '#D97706';
    if (tip === 'hareket') return htip === 'alacak' ? '#16A34A' : '#DC2626';
    return '#94A3B8';
  }

  function tipEtiket(tip) {
    if (tip === 'cari')    return 'Cari';
    if (tip === 'stok')    return 'Stok';
    if (tip === 'hareket') return 'Hareket';
    return '';
  }

  function ara(q) {
    if (q.length < 1) { kapat(); return; }
    fetch('/arama-json?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(sonuclar => {
        if (!sonuclar.length) {
          dropdown.innerHTML = '<div class="sd-empty">Sonuç bulunamadı</div>';
          dropdown.style.display = 'block';
          return;
        }
        let html = '';
        sonuclar.forEach((s, i) => {
          const renk = ikonRenk(s.tip, s.htip);
          html += `<a href="${s.url}" class="sd-item" data-idx="${i}">
            <div class="sd-ico" style="background:${renk}18;color:${renk}">${ikonHtml(s.tip, s.htip)}</div>
            <div class="sd-info">
              <div class="sd-name">${s.ad}</div>
              <div class="sd-alt">${s.alt || ''}</div>
            </div>
            <span class="sd-tag">${tipEtiket(s.tip)}</span>
          </a>`;
        });
        dropdown.innerHTML = html;
        dropdown.style.display = 'block';
        activeIdx = -1;
      })
      .catch(() => kapat());
  }

  input.addEventListener('input', function() {
    clearTimeout(timer);
    shortcut.style.display = this.value ? 'none' : '';
    timer = setTimeout(() => ara(this.value.trim()), 220);
  });

  input.addEventListener('keydown', function(e) {
    const items = dropdown.querySelectorAll('.sd-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      activeIdx = Math.min(activeIdx + 1, items.length - 1);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      activeIdx = Math.max(activeIdx - 1, 0);
    } else if (e.key === 'Enter' && activeIdx >= 0) {
      e.preventDefault();
      items[activeIdx].click();
      return;
    } else if (e.key === 'Escape') {
      kapat(); input.blur(); return;
    }
    items.forEach((el, i) => el.classList.toggle('active', i === activeIdx));
    if (activeIdx >= 0) items[activeIdx].scrollIntoView({ block: 'nearest' });
  });

  document.addEventListener('click', function(e) {
    if (!document.getElementById('searchWrap').contains(e.target)) kapat();
  });

  document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
      e.preventDefault();
      input.focus();
      input.select();
    }
  });
})();

// Toast ve pop-up bildirimleri sidebar.php'den geliyor
</script>
</body>
</html>
