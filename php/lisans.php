<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
girisGerekli();

// Demo modda lisans sayfasını da göster — kullanıcı satın alıp aktifleştirebilir

$hata   = '';
$basari = '';

define('LISANS_SECRET', 'VRM_2024_x9kQpL#mN!wZr7@tYjK');

function lisansPingAt(string $lisansKodu): void {
    $uuidFile = (getenv('VERESIYEM_DATA_DIR') ?: sys_get_temp_dir()) . '/device_id.txt';
    $uuid     = file_exists($uuidFile) ? trim(file_get_contents($uuidFile)) : 'unknown';
    $payload  = json_encode([
        'event'   => 'lisans',
        'uuid'    => $uuid,
        'version' => '1.0.1',
        'platform'=> PHP_OS_FAMILY,
        'lisans'  => substr($lisansKodu, 0, 7), // sadece VRM-XXX prefix'i gönder, tam key değil
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
        'content' => $payload,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    @file_get_contents('https://veresiyem.com.tr/app-ping', false, $ctx);
}

function lisansGecerliMi(string $kod): bool {
    $kod = strtoupper(trim($kod));
    if (!preg_match('/^VRM-([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z0-9]{4})$/', $kod, $m)) return false;
    $payload  = $m[1] . $m[2] . $m[3];
    $beklenen = strtoupper(substr(hash_hmac('sha256', $payload, LISANS_SECRET), 0, 4));
    return hash_equals($beklenen, $m[4]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksiyon = $_POST['aksiyon'] ?? 'lisans';

    if ($aksiyon === 'demo_kayit') {
        // Demo modda: ad + şifre + lisans kodu ile gerçek hesap oluştur
        $ad     = trim($_POST['ad'] ?? '');
        $sifre  = $_POST['sifre'] ?? '';
        $tekrar = $_POST['sifre2'] ?? '';
        $kod    = strtoupper(trim($_POST['lisans_kodu'] ?? ''));
        if (!$ad || strlen($sifre) < 4) {
            $hata = 'Ad ve en az 4 karakterli şifre girin.';
        } elseif ($sifre !== $tekrar) {
            $hata = 'Şifreler eşleşmiyor.';
        } elseif (!$kod) {
            $hata = 'Lisans kodu girin.';
        } elseif (!lisansGecerliMi($kod)) {
            $hata = 'Geçersiz lisans kodu.';
        } else {
            // Gerçek DB'ye kaydet
            $realDbPath = getenv('VERESIYEM_DB_PATH') ?: __DIR__ . '/../data/veresiyem.db';
            $realPdo = new PDO('sqlite:' . $realDbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $realPdo->exec('PRAGMA foreign_keys=ON');
            $realPdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, ad TEXT NOT NULL, sifre TEXT NOT NULL, lisans_kodu TEXT DEFAULT NULL, lisans_aktif INTEGER DEFAULT 0, demo_mod INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime('now','localtime')))");
            $realPdo->prepare("INSERT INTO users (ad, sifre, lisans_kodu, lisans_aktif) VALUES (?,?,?,1)")
                ->execute([$ad, password_hash($sifre, PASSWORD_DEFAULT), $kod]);
            $uid = (int)$realPdo->lastInsertId();
            // Temp DB'yi temizle
            if (!empty($_SESSION['demo_db_path']) && file_exists($_SESSION['demo_db_path'])) {
                @unlink($_SESSION['demo_db_path']);
                @unlink($_SESSION['demo_db_path'] . '-wal');
                @unlink($_SESSION['demo_db_path'] . '-shm');
            }
            $_SESSION['user_id']      = $uid;
            $_SESSION['user_ad']      = $ad;
            $_SESSION['demo_mod']     = false;
            unset($_SESSION['demo_db_path']);
            lisansPingAt($kod);
            header('Location: /?lisans=ok');
            exit;
        }
    } else {
        // Normal kullanıcı — sadece lisans kodu güncelle
        $kod = strtoupper(trim($_POST['lisans_kodu'] ?? ''));
        if (!$kod) {
            $hata = 'Lisans kodu girin.';
        } elseif (!lisansGecerliMi($kod)) {
            $hata = 'Geçersiz lisans kodu. Örnek: VRM-A1B2-C3D4-E5F6-XX00';
        } else {
            $pdo->prepare("UPDATE users SET lisans_kodu=?, lisans_aktif=1 WHERE id=?")
                ->execute([$kod, userId()]);
            lisansPingAt($kod);
            header('Location: /?lisans=ok');
            exit;
        }
    }
}

$pageTitle = 'Lisans Aktivasyonu';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lisans — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
body { display:flex; align-items:center; justify-content:center; min-height:calc(100vh - var(--titlebar-h)); background:var(--bg); padding-top:var(--titlebar-h); overflow:auto; -webkit-app-region:no-drag; }
.lis-wrap { width:100%; max-width:420px; padding:1.5rem; }
.lis-logo { font-family:var(--font-display); font-weight:900; font-size:1.85rem; color:var(--ink); text-align:center; margin-bottom:.25rem; letter-spacing:-.025em; }
.lis-logo em { color:var(--green); font-style:normal; }
.lis-sub { text-align:center; font-size:.8rem; color:var(--ink3); margin-bottom:2rem; }
.lis-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:2rem; box-shadow:var(--shadow); }
.lis-icon { width:56px; height:56px; border-radius:14px; background:var(--green-lt); color:var(--green); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem; }
.lis-title { font-family:var(--font-display); font-weight:900; font-size:1.15rem; color:var(--ink); text-align:center; margin-bottom:.4rem; }
.lis-desc { font-size:.82rem; color:var(--ink3); text-align:center; margin-bottom:1.5rem; line-height:1.65; }
.lis-features { list-style:none; margin-bottom:1.5rem; }
.lis-features li { display:flex; align-items:center; gap:.55rem; font-size:.82rem; color:var(--ink2); padding:.3rem 0; }
.lis-features li svg { color:var(--green); flex-shrink:0; }
.lis-divider { height:1px; background:var(--border); margin:1.5rem 0; }
</style>
</head>
<body>
<?php require 'includes/titlebar.php'; ?>

<div class="lis-wrap">
  <div class="lis-logo">veresi<em>yem</em></div>
  <div class="lis-sub">Dijital Veresiye Defteri</div>

  <div class="lis-card">

    <?php if ($hata): ?>
    <div class="alert alert-error" style="margin-bottom:1rem">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?= htmlspecialchars($hata) ?>
    </div>
    <?php endif; ?>

    <div class="lis-icon">
      <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </div>
    <div class="lis-title">Lisansınızı Aktifleştirin</div>
    <div class="lis-desc">Lisans kodunuzu girerek <?= demoMod() ? 'hesabınızı oluşturun ve ' : '' ?>tüm özellikleri açın.</div>

    <?php if (demoMod()): ?>
    <!-- Demo modda: ad + şifre + lisans kodu -->
    <form method="post">
      <input type="hidden" name="aksiyon" value="demo_kayit">
      <div class="form-group">
        <label class="form-label">Adınız / İşletme Adı</label>
        <input type="text" name="ad" class="form-input" placeholder="Ahmet Usta" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">Lisans Kodu</label>
        <input type="text" name="lisans_kodu" class="form-input" placeholder="VRM-XXXX-XXXX-XXXX-XXXX"
          style="font-family:monospace;letter-spacing:.08em;text-transform:uppercase"
          maxlength="24" required oninput="this.value=this.value.toUpperCase()">
      </div>
      <div class="form-group">
        <label class="form-label">Şifre</label>
        <input type="password" name="sifre" class="form-input" placeholder="En az 4 karakter" required>
      </div>
      <div class="form-group">
        <label class="form-label">Şifre Tekrar</label>
        <input type="password" name="sifre2" class="form-input" placeholder="Şifrenizi tekrar girin" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:.75rem">
        Hesap Oluştur & Aktifleştir →
      </button>
    </form>
    <div class="lis-divider"></div>
    <div style="text-align:center">
      <a href="/" style="font-size:.78rem;color:var(--ink3)">Demo'ya devam et</a>
      &nbsp;·&nbsp;
      <a href="https://wa.me/905300301498?text=Veresiyem%20lisans%20almak%20istiyorum" target="_blank" style="font-size:.78rem;color:var(--green)">Satın Al</a>
    </div>

    <?php else: ?>
    <!-- Normal kullanıcı: sadece lisans kodu -->
    <form method="post">
      <input type="hidden" name="aksiyon" value="lisans">
      <div class="form-group">
        <label class="form-label">Lisans Kodu</label>
        <input type="text" name="lisans_kodu" class="form-input" placeholder="VRM-XXXX-XXXX-XXXX-XXXX"
          style="font-family:monospace;letter-spacing:.1em;text-transform:uppercase"
          maxlength="24" autofocus oninput="this.value=this.value.toUpperCase()">
        <div class="form-hint">Satın aldığınızda e-posta ile gönderilmiştir.</div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:.75rem">
        Aktifleştir →
      </button>
    </form>
    <div class="lis-divider"></div>
    <div style="text-align:center">
      <a href="/" style="font-size:.78rem;color:var(--ink3)">Daha sonra</a>
      &nbsp;·&nbsp;
      <a href="https://wa.me/905300301498?text=Veresiyem%20lisans%20almak%20istiyorum" target="_blank" style="font-size:.78rem;color:var(--green)">Satın Al</a>
    </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>
