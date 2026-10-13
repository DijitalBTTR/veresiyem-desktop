<?php
require_once 'includes/auth.php';
require_once 'includes/demo.php';

if (girisYapildi()) { header('Location: /'); exit; }

// Bu sürüm tamamen offline çalışır
$appMod    = 'offline';
$apiBase   = '';
$onlineMod = false;

// Kalıcı DB — sadece gerçek kullanıcılar için
$realDbPath = getenv('VERESIYEM_DB_PATH') ?: __DIR__ . '/../data/veresiyem.db';
$pdo = new PDO('sqlite:' . $realDbPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys=ON');
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ad TEXT NOT NULL, sifre TEXT NOT NULL,
    lisans_kodu TEXT DEFAULT NULL, lisans_aktif INTEGER DEFAULT 0,
    demo_mod INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now','localtime'))
)");

define('LISANS_SECRET', 'VRM_2024_x9kQpL#mN!wZr7@tYjK');
function lisansGecerliMiGiris(string $kod): bool {
    $kod = strtoupper(trim($kod));
    // Format kontrolü: VRM-XXXX-XXXX-XXXX-XXXX (alfanumerik)
    return (bool)preg_match('/^VRM-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $kod);
}

// Device UUID oku veya oluştur
function deviceUuid(): string {
    $dataDir  = getenv('VERESIYEM_DATA_DIR') ?: sys_get_temp_dir();
    $uuidFile = $dataDir . '/device_id.txt';
    if (file_exists($uuidFile)) {
        $uuid = trim(file_get_contents($uuidFile));
        if ($uuid) return $uuid;
    }
    $uuid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff));
    @file_put_contents($uuidFile, $uuid);
    return $uuid;
}

// Online modda API ile giriş
function apiGiris(string $apiBase, string $email, string $sifre): array {
    $uuid     = deviceUuid();
    $cihazAdi = php_uname('n');
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nAccept: application/json",
        'content' => json_encode(['email' => $email, 'sifre' => $sifre, 'device_uuid' => $uuid, 'cihaz_adi' => $cihazAdi]),
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($apiBase . '/giris', false, $ctx);
    if ($body === false) return ['ok' => false, 'mesaj' => 'Sunucuya bağlanılamadı'];
    return json_decode($body, true) ?: ['ok' => false, 'mesaj' => 'Sunucu yanıtı geçersiz'];
}

// Lisans eklemek için device_uuid olmadan token al (lisans henüz bağlı değil)
function apiGirisSizUuid(string $apiBase, string $email, string $sifre): array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\nAccept: application/json",
        'content' => json_encode(['email' => $email, 'sifre' => $sifre]),
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($apiBase . '/giris', false, $ctx);
    if ($body === false) return ['ok' => false, 'mesaj' => 'Sunucuya bağlanılamadı'];
    return json_decode($body, true) ?: ['ok' => false, 'mesaj' => 'Sunucu yanıtı geçersiz'];
}

$hata         = '';
$lisansModal  = false; // LISANS_GEREKLI hatasında modal aç
$lisansEmail  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksiyon = $_POST['aksiyon'] ?? '';

    if ($aksiyon === 'demo') {
        // Temp DB oluştur, örnek verileri doldur
        $tempDbPath = demoTempDbOlustur();
        $demoPdo = new PDO('sqlite:' . $tempDbPath, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $demoPdo->exec('PRAGMA foreign_keys=ON');
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, ad TEXT NOT NULL, sifre TEXT NOT NULL, lisans_kodu TEXT DEFAULT NULL, lisans_aktif INTEGER DEFAULT 0, demo_mod INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime('now','localtime')))");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS cariler (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, ad TEXT NOT NULL, telefon TEXT DEFAULT NULL, not_ TEXT DEFAULT NULL, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS hareketler (id INTEGER PRIMARY KEY AUTOINCREMENT, cari_id INTEGER NOT NULL, tip TEXT NOT NULL CHECK(tip IN ('borc','alacak')), tutar REAL NOT NULL, aciklama TEXT DEFAULT NULL, tarih TEXT NOT NULL, vade_tarihi TEXT DEFAULT NULL, stok_id INTEGER DEFAULT NULL, stok_miktar REAL DEFAULT NULL, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (cari_id) REFERENCES cariler(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS stoklar (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, ad TEXT NOT NULL, birim TEXT NOT NULL DEFAULT 'adet', miktar REAL NOT NULL DEFAULT 0, fiyat REAL DEFAULT NULL, aciklama TEXT DEFAULT NULL, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS ayarlar (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, anahtar TEXT NOT NULL, deger TEXT DEFAULT NULL, UNIQUE(user_id, anahtar), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS kasa_hesaplari (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, ad TEXT NOT NULL, aciklama TEXT DEFAULT NULL, renk TEXT DEFAULT '#10B981', aktif INTEGER DEFAULT 1, varsayilan INTEGER DEFAULT 0, tip TEXT DEFAULT 'nakit', bakiye REAL DEFAULT 0, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS kasa (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, hesap_id INTEGER DEFAULT NULL, tip TEXT NOT NULL DEFAULT 'giris', tutar REAL NOT NULL DEFAULT 0, aciklama TEXT DEFAULT NULL, kaynak TEXT DEFAULT NULL, kaynak_id INTEGER DEFAULT NULL, tarih TEXT NOT NULL DEFAULT (date('now','localtime')), created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS tahsilatlar (id INTEGER PRIMARY KEY AUTOINCREMENT, cari_id INTEGER NOT NULL, hareket_id INTEGER DEFAULT NULL, tutar REAL NOT NULL, tarih TEXT NOT NULL, aciklama TEXT DEFAULT NULL, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (cari_id) REFERENCES cariler(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS hatirlaticilar (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, baslik TEXT NOT NULL, aciklama TEXT DEFAULT NULL, tarih TEXT NOT NULL, saat TEXT NOT NULL DEFAULT '09:00', durum TEXT NOT NULL DEFAULT 'aktif', created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS projeler (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, ad TEXT NOT NULL, musteri TEXT DEFAULT NULL, aciklama TEXT DEFAULT NULL, baslangic TEXT DEFAULT NULL, bitis TEXT DEFAULT NULL, durum TEXT DEFAULT 'devam' CHECK(durum IN ('devam','tamamlandi','beklemede')), butce REAL DEFAULT NULL, harcanan REAL DEFAULT 0, api_id INTEGER DEFAULT NULL, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS proje_gorevler (id INTEGER PRIMARY KEY AUTOINCREMENT, proje_id INTEGER NOT NULL, baslik TEXT NOT NULL, aciklama TEXT DEFAULT NULL, durum TEXT DEFAULT 'beklemede' CHECK(durum IN ('beklemede','devam','tamamlandi')), sira INTEGER DEFAULT 0, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (proje_id) REFERENCES projeler(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS gorevler (id INTEGER PRIMARY KEY AUTOINCREMENT, proje_id INTEGER NOT NULL, user_id INTEGER NOT NULL, baslik TEXT NOT NULL, aciklama TEXT DEFAULT NULL, durum TEXT DEFAULT 'yapilacak' CHECK(durum IN ('yapilacak','devam','tamamlandi')), oncelik TEXT DEFAULT 'normal', bitis TEXT DEFAULT NULL, api_id INTEGER DEFAULT NULL, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (proje_id) REFERENCES projeler(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS not_klasorler (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, ad TEXT NOT NULL, renk TEXT DEFAULT '#6366F1', not_bilgi TEXT DEFAULT NULL, api_id INTEGER DEFAULT NULL, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS not_satirlar (id INTEGER PRIMARY KEY AUTOINCREMENT, klasor_id INTEGER NOT NULL, user_id INTEGER NOT NULL DEFAULT 0, urun_adi TEXT NOT NULL DEFAULT '', birim TEXT NOT NULL DEFAULT 'adet', fiyat REAL DEFAULT 0, adet REAL DEFAULT 1, not_ TEXT DEFAULT NULL, api_id INTEGER DEFAULT NULL, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (klasor_id) REFERENCES not_klasorler(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS odemeler (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, hesap_id INTEGER DEFAULT NULL, baslik TEXT NOT NULL DEFAULT '', aciklama TEXT DEFAULT NULL, tutar REAL NOT NULL DEFAULT 0, tarih TEXT NOT NULL DEFAULT (date('now','localtime')), tekrar TEXT DEFAULT 'yok', durum TEXT DEFAULT 'odendi', created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS tedarikciler (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, ad TEXT NOT NULL, yetkili TEXT DEFAULT NULL, telefon TEXT DEFAULT NULL, email TEXT DEFAULT NULL, adres TEXT DEFAULT NULL, not_ TEXT DEFAULT NULL, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS faturalar (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, tedarikci_id INTEGER NOT NULL, fatura_no TEXT NOT NULL DEFAULT '', tarih TEXT NOT NULL DEFAULT (date('now','localtime')), vade_tarihi TEXT DEFAULT NULL, toplam_tutar REAL NOT NULL DEFAULT 0, kdv_toplam REAL NOT NULL DEFAULT 0, genel_toplam REAL NOT NULL DEFAULT 0, durum TEXT NOT NULL DEFAULT 'odenmedi', aciklama TEXT DEFAULT NULL, odeme_hesap_id INTEGER DEFAULT NULL, odeme_kasa_id INTEGER DEFAULT NULL, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY (tedarikci_id) REFERENCES tedarikciler(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS fatura_satirlar (id INTEGER PRIMARY KEY AUTOINCREMENT, fatura_id INTEGER NOT NULL, urun_adi TEXT NOT NULL, miktar REAL NOT NULL DEFAULT 1, birim TEXT NOT NULL DEFAULT 'adet', birim_fiyat REAL NOT NULL DEFAULT 0, kdv_oran REAL NOT NULL DEFAULT 0, toplam REAL NOT NULL DEFAULT 0, FOREIGN KEY (fatura_id) REFERENCES faturalar(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS gelir_gider_kategoriler (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, ad TEXT NOT NULL, tip TEXT NOT NULL CHECK(tip IN ('gelir','gider')), renk TEXT DEFAULT '#3B82F6', created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");
        $demoPdo->exec("CREATE TABLE IF NOT EXISTS gelir_gider (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, hesap_id INTEGER DEFAULT NULL, kategori_id INTEGER DEFAULT NULL, cari_id INTEGER DEFAULT NULL, tip TEXT NOT NULL CHECK(tip IN ('gelir','gider')), tutar REAL NOT NULL, aciklama TEXT DEFAULT NULL, tarih TEXT NOT NULL DEFAULT (date('now','localtime')), kasa_id INTEGER DEFAULT NULL, created_at TEXT DEFAULT (datetime('now','localtime')), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE)");

        $uid = demoKur($demoPdo);
        $_SESSION['user_id']      = $uid;
        $_SESSION['user_ad']      = 'Demo Kullanıcı';
        $_SESSION['demo_mod']     = true;
        $_SESSION['demo_db_path'] = $tempDbPath;
        header('Location: /'); exit;

    } elseif ($aksiyon === 'api-giris') {
        // Online mod — API ile giriş
        $email = trim($_POST['email'] ?? '');
        $sifre = $_POST['sifre'] ?? '';
        $sonuc = apiGiris($apiBase, $email, $sifre);
        if (!empty($sonuc['ok']) && !empty($sonuc['token'])) {
            if (empty($sonuc['lisans_aktif'])) {
                $hata = 'Lisansınız iptal edilmiştir. Lütfen yetkili ile iletişime geçin.';
            } else {
                $localUser = $pdo->query("SELECT id FROM users WHERE ad=" . $pdo->quote($sonuc['user']['ad'] ?? '') . " AND demo_mod=0")->fetch();
                if (!$localUser) {
                    $pdo->prepare("INSERT INTO users (ad, sifre, lisans_kodu, lisans_aktif) VALUES (?,?,?,1)")
                        ->execute([$sonuc['user']['ad'], password_hash($sifre, PASSWORD_DEFAULT), $email]);
                    $localId = (int)$pdo->lastInsertId();
                } else {
                    $localId = (int)$localUser['id'];
                }
                $_SESSION['user_id']       = $localId;
                $_SESSION['user_ad']       = $sonuc['user']['ad'];
                $_SESSION['demo_mod']      = false;
                $_SESSION['api_token']     = $sonuc['token'];
                $_SESSION['api_user_id']   = $sonuc['user']['id'];
                $_SESSION['user_rol']      = $sonuc['user']['rol'] ?? 'patron';
                $_SESSION['patron_uid']    = $sonuc['user']['patron_user_id'] ?? null;
                $_SESSION['yetkiler']      = $sonuc['user']['yetkiler'] ?? null;
                header('Location: /?savetoken=' . urlencode($sonuc['token']));
                exit;
            }
        } else {
            $kod = $sonuc['kod'] ?? '';
            if ($kod === 'LISANS_GEREKLI') {
                $lisansModal = true;
                $lisansEmail = $email;
                $hata = '';
            } elseif ($kod === 'LISANS_BASKA_CIHAZ') {
                $cihazAdi = htmlspecialchars($sonuc['cihaz'] ?? 'başka bir bilgisayar');
                $hata = "Bu lisans anahtarı \"$cihazAdi\" adlı bilgisayara kayıtlıdır. Her bilgisayar için ayrı lisans gereklidir.";
            } else {
                $hata = $sonuc['mesaj'] ?? 'Giriş başarısız.';
            }
        }

    } elseif ($aksiyon === 'lisans-ekle') {
        // Mevcut hesaba lisans kodu ekle
        $email  = trim($_POST['email'] ?? '');
        $sifre  = $_POST['sifre'] ?? '';
        $lisans = strtoupper(trim($_POST['lisans_kodu'] ?? ''));
        if (!$lisans) {
            $hata = 'Lisans kodu zorunludur.';
            $lisansModal = true; $lisansEmail = $email;
        } else {
            // Lisans henüz bağlı olmadığı için device_uuid olmadan token al
            $girisRes = apiGirisSizUuid($apiBase, $email, $sifre);
            if (!empty($girisRes['token'])) {
                $token = $girisRes['token'];
                $ctxL = stream_context_create(['http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer $token",
                    'content' => json_encode(['lisans_kodu' => $lisans]),
                    'timeout' => 10,
                    'ignore_errors' => true,
                ]]);
                $lBody = @file_get_contents($apiBase . '/profil/lisans', false, $ctxL);
                $lRes  = $lBody ? (json_decode($lBody, true) ?: []) : [];
                if (!empty($lRes['ok'])) {
                    // Lisans eklendi, tekrar giriş yap
                    $sonuc2 = apiGiris($apiBase, $email, $sifre);
                    if (!empty($sonuc2['ok']) && !empty($sonuc2['token'])) {
                        $localUser = $pdo->query("SELECT id FROM users WHERE ad=" . $pdo->quote($sonuc2['user']['ad'] ?? '') . " AND demo_mod=0")->fetch();
                        if (!$localUser) {
                            $pdo->prepare("INSERT INTO users (ad, sifre, lisans_kodu, lisans_aktif) VALUES (?,?,?,1)")
                                ->execute([$sonuc2['user']['ad'], password_hash($sifre, PASSWORD_DEFAULT), $email]);
                            $localId = (int)$pdo->lastInsertId();
                        } else {
                            $localId = (int)$localUser['id'];
                        }
                        $_SESSION['user_id']     = $localId;
                        $_SESSION['user_ad']     = $sonuc2['user']['ad'];
                        $_SESSION['demo_mod']    = false;
                        $_SESSION['api_token']   = $sonuc2['token'];
                        $_SESSION['api_user_id'] = $sonuc2['user']['id'];
                        $_SESSION['user_rol']    = $sonuc2['user']['rol'] ?? 'patron';
                        $_SESSION['yetkiler']    = $sonuc2['user']['yetkiler'] ?? null;
                        header('Location: /?savetoken=' . urlencode($sonuc2['token']));
                        exit;
                    }
                }
                $hata = 'Lisans kodu eklenemedi. Kodu kontrol edin.';
                $lisansModal = true; $lisansEmail = $email;
            } else {
                $hata = $girisRes['mesaj'] ?? 'Şifre yanlış veya sunucuya bağlanılamadı.';
                $lisansModal = true; $lisansEmail = $email;
            }
        }

    } elseif ($aksiyon === 'api-kayit') {
        // Online mod — API ile kayıt (lisans kodu zorunlu)
        $ad     = trim($_POST['ad'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $sifre  = $_POST['sifre'] ?? '';
        $tekrar = $_POST['sifre2'] ?? '';
        $lisans = strtoupper(trim($_POST['lisans_kodu'] ?? ''));
        if (!$ad || !$email || strlen($sifre) < 6) {
            $hata = 'Ad, e-posta ve en az 6 karakterli şifre zorunludur.';
        } elseif ($sifre !== $tekrar) {
            $hata = 'Şifreler eşleşmiyor.';
        } elseif (!$lisans) {
            $hata = 'Lisans kodu zorunludur.';
        } elseif (!lisansGecerliMiGiris($lisans)) {
            $hata = 'Geçersiz lisans kodu.';
        } else {
            // Önce API'de kayıt
            $ctx = stream_context_create(['http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\nAccept: application/json",
                'content' => json_encode(['ad' => $ad, 'email' => $email, 'sifre' => $sifre]),
                'timeout' => 10,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($apiBase . '/kayit', false, $ctx);
            $sonuc = $body ? (json_decode($body, true) ?: []) : [];
            if (!empty($sonuc['ok']) && !empty($sonuc['token'])) {
                // Lisans kodunu API'de kaydet
                $ctxL = stream_context_create(['http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer " . $sonuc['token'],
                    'content' => json_encode(['lisans_kodu' => $lisans]),
                    'timeout' => 10,
                    'ignore_errors' => true,
                ]]);
                @file_get_contents($apiBase . '/profil/lisans', false, $ctxL);
                // Yerel kayıt
                $pdo->prepare("INSERT OR IGNORE INTO users (ad, sifre, lisans_kodu, lisans_aktif) VALUES (?,?,?,1)")
                    ->execute([$ad, password_hash($sifre, PASSWORD_DEFAULT), $email]);
                $localId = (int)$pdo->lastInsertId() ?: (int)$pdo->query("SELECT id FROM users WHERE ad=" . $pdo->quote($ad))->fetchColumn();
                $_SESSION['user_id']     = $localId;
                $_SESSION['user_ad']     = $ad;
                $_SESSION['demo_mod']    = false;
                $_SESSION['api_token']   = $sonuc['token'];
                $_SESSION['api_user_id'] = $sonuc['user']['id'];
                header('Location: /?savetoken=' . urlencode($sonuc['token']));
                exit;
            } else {
                $hata = $sonuc['message'] ?? ($sonuc['mesaj'] ?? 'Kayıt başarısız. Bu e-posta zaten kullanılıyor olabilir.');
            }
        }

    } elseif ($aksiyon === 'giris') {
        $sifre  = $_POST['sifre'] ?? '';
        $userId = (int)($_POST['user_id'] ?? 0);
        $stmt   = $pdo->prepare("SELECT * FROM users WHERE demo_mod=0 AND id=?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user && password_verify($sifre, $user['sifre'])) {
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['user_ad']  = $user['ad'];
            $_SESSION['demo_mod'] = false;
            header('Location: /'); exit;
        } else {
            $hata = 'Şifre hatalı. Lütfen tekrar deneyin.';
        }

    } elseif ($aksiyon === 'kurulum') {
        $ad     = trim($_POST['ad'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $sifre  = $_POST['sifre'] ?? '';
        $tekrar = $_POST['sifre2'] ?? '';
        $lisans = strtoupper(trim($_POST['lisans_kodu'] ?? ''));
        if (!$ad || strlen($sifre) < 4) {
            $hata = 'Ad ve en az 4 karakterli şifre girin.';
        } elseif (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $hata = 'Geçerli bir e-posta adresi girin.';
        } elseif ($sifre !== $tekrar) {
            $hata = 'Şifreler eşleşmiyor.';
        } elseif (!$lisans) {
            $hata = 'Lisans kodu zorunludur.';
        } elseif (!lisansGecerliMiGiris($lisans)) {
            $hata = 'Geçersiz lisans kodu.';
        } else {
                // Yerel kayıt
            $pdo->prepare("INSERT INTO users (ad, sifre, lisans_kodu, lisans_aktif) VALUES (?,?,?,1)")
                ->execute([$ad, password_hash($sifre, PASSWORD_DEFAULT), $lisans]);
            $uid = (int)$pdo->lastInsertId();
            $_SESSION['user_id']  = $uid;
            $_SESSION['user_ad']  = $ad;
            $_SESSION['demo_mod'] = false;
            header('Location: /');
            exit;
        }
    }
}

$tumKullanicilar = $pdo->query("SELECT id, ad FROM users WHERE demo_mod=0 ORDER BY id ASC")->fetchAll();
$mevcutKullanici = $tumKullanicilar[0] ?? null;
$kullanicVar     = (bool)$mevcutKullanici;
// ?yeni=1 ile giriş ekranında kayıt formu göster
$yeniHesap = isset($_GET['yeni']) || ($aksiyon ?? '') === 'kurulum' && !empty($hata);
$pageTitle = 'Giriş';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
  body { overflow:auto; -webkit-app-region:no-drag; }
  .auth-shell {
    min-height:calc(100vh - var(--titlebar-h));
    display:grid;
    grid-template-columns:1fr 1fr;
  }
  /* Sol: marka tarafı */
  .auth-brand {
    background:#0F1117;
    display:flex; flex-direction:column; justify-content:center;
    padding:3rem 3.5rem;
    position:relative; overflow:hidden;
  }
  .auth-brand::before {
    content:'';
    position:absolute; inset:0;
    background:radial-gradient(ellipse at 20% 50%, rgba(22,163,74,.12) 0%, transparent 60%),
               radial-gradient(ellipse at 80% 20%, rgba(37,99,235,.08) 0%, transparent 50%);
  }
  .auth-brand-inner { position:relative; z-index:1; }
  .brand-logo {
    font-family:var(--font-display); font-weight:900; font-size:2rem;
    color:#fff; letter-spacing:-.03em; margin-bottom:.5rem;
  }
  .brand-logo em { color:#4ade80; font-style:normal; }
  .brand-tag { font-size:.82rem; color:rgba(255,255,255,.4); margin-bottom:3rem; }
  .brand-feature {
    display:flex; align-items:flex-start; gap:.9rem; margin-bottom:1.5rem;
  }
  .brand-feature-ico {
    width:36px; height:36px; border-radius:9px; flex-shrink:0;
    display:flex; align-items:center; justify-content:center;
    background:rgba(22,163,74,.15); color:#4ade80; margin-top:.1rem;
  }
  .brand-feature-title { font-size:.88rem; font-weight:600; color:#f1f5f9; margin-bottom:.15rem; }
  .brand-feature-sub { font-size:.77rem; color:rgba(255,255,255,.35); line-height:1.5; }
  .brand-bottom {
    margin-top:3rem; padding-top:1.5rem;
    border-top:1px solid rgba(255,255,255,.06);
    font-size:.72rem; color:rgba(255,255,255,.2);
  }

  /* Sağ: form tarafı */
  .auth-form-side {
    background:var(--bg);
    display:flex; align-items:center; justify-content:center;
    padding:2.5rem;
  }
  .auth-form-wrap { width:100%; max-width:340px; }
  .auth-form-title {
    font-family:var(--font-display); font-weight:900;
    font-size:1.4rem; color:var(--ink); margin-bottom:.35rem; letter-spacing:-.02em;
  }
  .auth-form-sub { font-size:.82rem; color:var(--ink3); margin-bottom:1.75rem; }

  .demo-cta {
    width:100%; padding:.85rem 1rem;
    border-radius:var(--r); cursor:pointer; transition:.15s; border:none;
    background:var(--gold-lt);
    color:#92400e; font-family:inherit; font-size:.88rem; font-weight:700;
    display:flex; align-items:center; justify-content:center; gap:.55rem;
    border:1.5px solid rgba(217,119,6,.2);
    margin-bottom:1.5rem;
  }
  .demo-cta:hover { background:#fde68a; border-color:rgba(217,119,6,.4); }

  .auth-divider {
    display:flex; align-items:center; gap:.75rem; margin-bottom:1.5rem;
  }
  .auth-divider::before,.auth-divider::after { content:''; flex:1; height:1px; background:var(--border); }
  .auth-divider span { font-size:.7rem; color:var(--ink3); }

  /* Online mod tab sistemi */
  .auth-tabs {
    display:flex; gap:0; margin-bottom:1.75rem;
    border:1.5px solid var(--border); border-radius:var(--r); overflow:hidden;
  }
  .auth-tab {
    flex:1; padding:.6rem 1rem; font-size:.82rem; font-weight:600;
    text-align:center; cursor:pointer; transition:.15s;
    background:transparent; border:none; color:var(--ink3);
    font-family:inherit;
  }
  .auth-tab.active {
    background:var(--primary); color:#fff;
  }
  .auth-tab:not(.active):hover { background:var(--bg2); color:var(--ink); }
  .auth-panel { display:none; }
  .auth-panel.active { display:block; }

  @media (max-width: 680px) {
    .auth-shell { grid-template-columns:1fr; }
    .auth-brand { display:none; }
  }
</style>
</head>
<body>
<?php require 'includes/titlebar.php'; ?>

<div class="auth-shell">
  <!-- Sol: Marka -->
  <div class="auth-brand">
    <div class="auth-brand-inner">
      <div class="brand-logo">veresi<em>yem</em></div>
      <div class="brand-tag">Dijital Veresiye Defteri</div>

      <div class="brand-feature">
        <div class="brand-feature-ico">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        </div>
        <div>
          <div class="brand-feature-title">Cari Takibi</div>
          <div class="brand-feature-sub">Müşteri borç ve alacaklarını tek ekranda görün</div>
        </div>
      </div>

      <div class="brand-feature">
        <div class="brand-feature-ico">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        </div>
        <div>
          <div class="brand-feature-title">Stok Yönetimi</div>
          <div class="brand-feature-sub">Ürün stoklarınızı takip edin, kritik seviyeleri görün</div>
        </div>
      </div>

      <div class="brand-feature">
        <div class="brand-feature-ico">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div>
          <div class="brand-feature-title">Excel Dışa Aktarma</div>
          <div class="brand-feature-sub">Verilerinizi Excel formatında indirin ve paylaşın</div>
        </div>
      </div>

      <div class="brand-feature">
        <div class="brand-feature-ico" style="background:rgba(22,163,74,.15);color:#4ade80">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
        </div>
        <div>
          <div class="brand-feature-title">%100 Offline</div>
          <div class="brand-feature-sub">İnternet olmadan çalışır, verileriniz bilgisayarınızda</div>
        </div>
      </div>

      <div class="brand-bottom">© 2024 Veresiyem · v1.0.0</div>
    </div>
  </div>

  <!-- Sağ: Form -->
  <div class="auth-form-side">
    <div class="auth-form-wrap">

      <?php if ($hata): ?>
      <div class="alert alert-error" style="margin-bottom:1.25rem">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= htmlspecialchars($hata) ?>
      </div>
      <?php endif; ?>

      <?php if ($lisansModal): ?>
      <!-- Lisans Ekleme Modal (otomatik açılır) -->
      <style>
        .ls-overlay { position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;display:flex;align-items:center;justify-content:center; }
        .ls-box { background:var(--bg,#fff);border:1px solid var(--border,#e5e7eb);border-radius:14px;padding:1.75rem;width:100%;max-width:380px;box-shadow:0 20px 60px rgba(0,0,0,.2); }
        .ls-title { font-size:1rem;font-weight:700;color:var(--ink,#111);margin-bottom:.4rem; }
        .ls-sub { font-size:.8rem;color:var(--ink3,#6b7280);margin-bottom:1.25rem;line-height:1.5; }
      </style>
      <div class="ls-overlay" id="lisansOverlay">
        <div class="ls-box">
          <div class="ls-title">
            <svg width="16" height="16" fill="none" stroke="#f59e0b" stroke-width="2.5" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:.3rem"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Lisans Kodu Gerekli
          </div>
          <div class="ls-sub">
            <strong><?= htmlspecialchars($lisansEmail) ?></strong> hesabına henüz lisans kodu eklenmemiş.<br>
            Lisans kodunuzu girerek hesabınızı aktifleştirin.
          </div>
          <form method="post">
            <input type="hidden" name="aksiyon" value="lisans-ekle">
            <input type="hidden" name="email" value="<?= htmlspecialchars($lisansEmail) ?>">
            <input type="hidden" name="sifre" id="ls-sifre-hidden">
            <div class="form-group">
              <label class="form-label">Lisans Kodu</label>
              <input type="text" name="lisans_kodu" class="form-input"
                placeholder="VRM-XXXX-XXXX-XXXX-XXXX"
                style="font-family:monospace;letter-spacing:.08em;text-transform:uppercase"
                maxlength="24" autofocus required
                oninput="this.value=this.value.toUpperCase();lisansFormatla(this)">
            </div>
            <div class="form-group">
              <label class="form-label">Şifrenizi Onaylayın</label>
              <input type="password" name="sifre" class="form-input" placeholder="Hesap şifreniz" required>
            </div>
            <div style="display:flex;gap:.6rem;margin-top:.25rem">
              <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:.7rem">
                Aktifleştir →
              </button>
              <button type="button" class="btn btn-outline" onclick="document.getElementById('lisansOverlay').remove()">
                İptal
              </button>
            </div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($onlineMod): ?>
      <!-- Online mod — Tab sistemi -->
      <?php
        // Hangi tab aktif? Kayıt POST hatası varsa kayıt tabı açık kalsın
        $aktifTab = (($aksiyon ?? '') === 'api-kayit' && $hata) ? 'kayit' : 'giris';
      ?>
      <div class="auth-form-title">Veresiyem</div>
      <div class="auth-form-sub">Bulut hesabınızla giriş yapın veya yeni hesap oluşturun.</div>

      <div class="auth-tabs">
        <button type="button" class="auth-tab <?= $aktifTab==='giris'?'active':'' ?>" onclick="sekmeAc('giris')">Giriş Yap</button>
        <button type="button" class="auth-tab <?= $aktifTab==='kayit'?'active':'' ?>" onclick="sekmeAc('kayit')">Hesap Oluştur</button>
      </div>

      <!-- Giriş Paneli -->
      <div class="auth-panel <?= $aktifTab==='giris'?'active':'' ?>" id="panel-giris">
        <form method="post">
          <input type="hidden" name="aksiyon" value="api-giris">
          <div class="form-group">
            <label class="form-label">E-posta</label>
            <input type="email" name="email" class="form-input" placeholder="ornek@mail.com"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
              <?= $aktifTab==='giris'?'autofocus':'' ?> required>
          </div>
          <div class="form-group">
            <label class="form-label">Şifre</label>
            <input type="password" name="sifre" class="form-input" placeholder="Şifreniz" required>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:.75rem;font-size:.9rem">
            Giriş Yap →
          </button>
        </form>
      </div>

      <!-- Hesap Oluştur Paneli -->
      <div class="auth-panel <?= $aktifTab==='kayit'?'active':'' ?>" id="panel-kayit">
        <form method="post">
          <input type="hidden" name="aksiyon" value="api-kayit">
          <div class="form-group">
            <label class="form-label">Ad / İşletme Adı</label>
            <input type="text" name="ad" class="form-input" placeholder="Ahmet Usta"
              value="<?= htmlspecialchars($_POST['ad'] ?? '') ?>"
              <?= $aktifTab==='kayit'?'autofocus':'' ?> required>
          </div>
          <div class="form-group">
            <label class="form-label">E-posta</label>
            <input type="email" name="email" class="form-input" placeholder="ornek@mail.com"
              value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Şifre <span style="font-weight:400;color:var(--ink3)">(en az 6 karakter)</span></label>
            <input type="password" name="sifre" class="form-input" placeholder="Şifreniz" required>
          </div>
          <div class="form-group">
            <label class="form-label">Şifre Tekrar</label>
            <input type="password" name="sifre2" class="form-input" placeholder="Şifrenizi tekrar girin" required>
          </div>
          <div class="form-group">
            <label class="form-label">
              Lisans Kodu
              <span style="color:var(--danger);margin-left:.2rem">*</span>
            </label>
            <input type="text" name="lisans_kodu" class="form-input"
              placeholder="VRM-XXXX-XXXX-XXXX-XXXX"
              style="font-family:monospace;letter-spacing:.08em;text-transform:uppercase"
              value="<?= htmlspecialchars(strtoupper($_POST['lisans_kodu'] ?? '')) ?>"
              maxlength="24" oninput="this.value=this.value.toUpperCase();lisansFormatla(this)" required>
            <div style="font-size:.72rem;color:var(--ink3);margin-top:.3rem">
              Lisans kodu olmadan hesap oluşturulamaz.
            </div>
          </div>
          <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:.75rem;font-size:.9rem">
            Hesabı Oluştur →
          </button>
        </form>
      </div>

      <div class="auth-divider" style="margin-top:1.25rem"><span>veya</span></div>

      <script>
      function sekmeAc(id) {
        document.querySelectorAll('.auth-tab').forEach(t => t.classList.toggle('active', t.onclick.toString().includes("'"+id+"'")));
        document.querySelectorAll('.auth-panel').forEach(p => p.classList.toggle('active', p.id === 'panel-'+id));
        const first = document.querySelector('#panel-'+id+' input:not([type=hidden])');
        if (first) first.focus();
      }
      function lisansFormatla(el) {
        // VRM-XXXX-XXXX-XXXX-XXXX formatı — tire otomatik ekle
        let v = el.value.replace(/[^A-Z0-9]/g, '');
        if (v.startsWith('VRM')) {
          let parts = [v.slice(0,3)];
          if (v.length > 3) parts.push(v.slice(3,7));
          if (v.length > 7) parts.push(v.slice(7,11));
          if (v.length > 11) parts.push(v.slice(11,15));
          if (v.length > 15) parts.push(v.slice(15,19));
          el.value = parts.join('-');
        }
      }
      </script>

      <?php elseif (!$kullanicVar || $yeniHesap): ?>
      <!-- İlk kurulum / Yeni hesap -->
      <div class="auth-form-title">Hoş geldiniz!</div>
      <div class="auth-form-sub">Lisans kodunuzla hesabınızı oluşturun.</div>

      <form method="post">
        <input type="hidden" name="aksiyon" value="kurulum">
        <div class="form-group">
          <label class="form-label">Adınız / İşletme Adı</label>
          <input type="text" name="ad" class="form-input" placeholder="Ahmet Usta" required autofocus
            value="<?= htmlspecialchars($_POST['ad'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">E-posta <span style="font-size:.75rem;color:var(--ink3)">(mobil giriş için gerekli)</span></label>
          <input type="email" name="email" class="form-input" placeholder="ornek@mail.com" required
            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Lisans Kodu</label>
          <input type="text" name="lisans_kodu" class="form-input" placeholder="VRM-XXXX-XXXX-XXXX-XXXX"
            style="font-family:monospace;letter-spacing:.08em;text-transform:uppercase"
            maxlength="24" required oninput="this.value=this.value.toUpperCase()"
            value="<?= htmlspecialchars(strtoupper($_POST['lisans_kodu'] ?? '')) ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Şifre</label>
          <input type="password" name="sifre" class="form-input" placeholder="En az 4 karakter" required>
        </div>
        <div class="form-group">
          <label class="form-label">Şifre Tekrar</label>
          <input type="password" name="sifre2" class="form-input" placeholder="Şifrenizi tekrar girin" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:.75rem;font-size:.9rem">
          Hesabı Oluştur →
        </button>
      </form>

      <div class="auth-divider" style="margin-top:1.5rem"><span>veya önce deneyin</span></div>

      <?php else: ?>
      <!-- Şifre ile giriş -->
      <div class="auth-form-title">Tekrar hoş geldiniz<?= count($tumKullanicilar) === 1 ? ', ' . htmlspecialchars($tumKullanicilar[0]['ad']) : '' ?>!</div>
      <div class="auth-form-sub">Hesabınızı seçin ve şifrenizle giriş yapın.</div>

      <form method="post">
        <input type="hidden" name="aksiyon" value="giris">
        <div class="form-group">
          <label class="form-label">Hesap</label>
          <?php if (count($tumKullanicilar) === 1): ?>
          <input type="hidden" name="user_id" value="<?= $tumKullanicilar[0]['id'] ?>">
          <input type="text" class="form-input" value="<?= htmlspecialchars($tumKullanicilar[0]['ad']) ?>" readonly style="opacity:.6;cursor:default">
          <?php else: ?>
          <select name="user_id" class="form-input" style="cursor:pointer">
            <?php foreach ($tumKullanicilar as $ku): ?>
            <option value="<?= $ku['id'] ?>"><?= htmlspecialchars($ku['ad']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label class="form-label">Şifre</label>
          <input type="password" name="sifre" class="form-input" placeholder="Şifreniz" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:.75rem;font-size:.9rem">
          Giriş Yap →
        </button>
      </form>
      <div style="text-align:center;margin-top:.75rem">
        <a href="/giris?yeni=1" style="font-size:.75rem;color:var(--ink3)">Farklı bir lisansla yeni hesap oluştur</a>
      </div>

      <div class="auth-divider" style="margin-top:1.25rem"><span>veya</span></div>

      <?php endif; // online/offline mod ?>

      <!-- Demo -->
      <form method="post">
        <input type="hidden" name="aksiyon" value="demo">
        <button type="submit" class="demo-cta">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
          Demo Olarak Keşfet
          <span style="margin-left:auto;font-size:.72rem;font-weight:500;opacity:.65">Kayıt gerekmez</span>
        </button>
      </form>

    </div>
  </div>
</div>
</body>
</html>
