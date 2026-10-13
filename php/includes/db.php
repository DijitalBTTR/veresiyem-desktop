<?php
// ── Uygulama modu ───────────────────────────────────────────────────────────
$appMod      = getenv('VERESIYEM_APP_MODE') ?: 'offline'; // 'online' | 'offline'
$apiBase     = getenv('VERESIYEM_API_BASE') ?: 'https://api.veresiyem.com.tr/api/v1';

// API token: önce session'dan (online girişten sonra), yoksa env'den
$apiToken    = $_SESSION['api_token'] ?? getenv('VERESIYEM_API_TOKEN') ?: '';
// Env'den geldiyse session'a da yaz — program yeniden açılışta bağlantı kaybolmasın
if (!isset($_SESSION['api_token']) && $apiToken !== '') {
    $_SESSION['api_token'] = $apiToken;
}
$onlineMod   = ($apiToken !== '');
$apiClient   = null;

if ($onlineMod) {
    require_once __DIR__ . '/api_client.php';
    $apiClient = new ApiClient($apiBase, $apiToken);
}

/**
 * Her çağrıda session'dan token okur — bağlantı sonrası anında çalışır.
 */
function _getApiClient(): ?object {
    if (demoMod()) return null;
    $token = $_SESSION['api_token'] ?? getenv('VERESIYEM_API_TOKEN') ?: '';
    if (!$token) return null;
    static $clients = [];
    if (!isset($clients[$token])) {
        require_once __DIR__ . '/api_client.php';
        $base = getenv('VERESIYEM_API_BASE') ?: 'https://api.veresiyem.com.tr/api/v1';
        $clients[$token] = new ApiClient($base, $token);
    }
    return $clients[$token];
}

/**
 * API'ye senkron yazar — hata olursa sessizce geçer (yerel kayıt zaten yapılmış).
 */
function apiSync(string $method, string $path, array $data = []): ?array {
    $client = _getApiClient();
    if (!$client) return null;
    try {
        return match(strtolower($method)) {
            'post'   => $client->post($path, $data),
            'put'    => $client->put($path, $data),
            'delete' => $client->delete($path),
            default  => $client->get($path, $data),
        };
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * API'den veri okur — online modda kullan, offline'da null döner.
 */
function apiGet(string $path, array $query = []): ?array {
    $client = _getApiClient();
    if (!$client) return null;
    try {
        return $client->get($path, $query);
    } catch (Throwable $e) {
        return null;
    }
}

// ── Demo modda temp DB, normal modda kalıcı DB ──────────────────────────────
if (!empty($_SESSION['demo_mod']) && !empty($_SESSION['demo_db_path'])) {
    $dbPath = $_SESSION['demo_db_path'];
} else {
    $dbPath = getenv('VERESIYEM_DB_PATH') ?: __DIR__ . '/../../data/veresiyem.db';
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_PERSISTENT         => false,
]);

$pdo->exec('PRAGMA journal_mode=WAL');
$pdo->exec('PRAGMA synchronous=NORMAL');
$pdo->exec('PRAGMA cache_size=-8000');   // ~8MB sayfa önbelleği
$pdo->exec('PRAGMA temp_store=MEMORY');
$pdo->exec('PRAGMA foreign_keys=ON');

// DDL — CREATE IF NOT EXISTS zaten idempotent, her request'te çalışması güvenli
if (true):

$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ad TEXT NOT NULL,
    sifre TEXT NOT NULL,
    lisans_kodu TEXT DEFAULT NULL,
    lisans_aktif INTEGER DEFAULT 0,
    demo_mod INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now','localtime'))
)");

$pdo->exec("
CREATE TABLE IF NOT EXISTS cariler (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    ad TEXT NOT NULL,
    telefon TEXT DEFAULT NULL,
    not_ TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$pdo->exec("
CREATE TABLE IF NOT EXISTS hareketler (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cari_id INTEGER NOT NULL,
    tip TEXT NOT NULL CHECK(tip IN ('borc','alacak')),
    tutar REAL NOT NULL,
    aciklama TEXT DEFAULT NULL,
    tarih TEXT NOT NULL,
    vade_tarihi TEXT DEFAULT NULL,
    stok_id INTEGER DEFAULT NULL,
    stok_miktar REAL DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (cari_id) REFERENCES cariler(id) ON DELETE CASCADE
)");

try { $pdo->exec("ALTER TABLE hareketler ADD COLUMN vade_tarihi TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE hareketler ADD COLUMN grup_id TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE hareketler ADD COLUMN api_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}

$pdo->exec("
CREATE TABLE IF NOT EXISTS tahsilatlar (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cari_id INTEGER NOT NULL,
    hareket_id INTEGER NOT NULL,
    tutar REAL NOT NULL,
    tarih TEXT NOT NULL,
    aciklama TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (cari_id) REFERENCES cariler(id) ON DELETE CASCADE,
    FOREIGN KEY (hareket_id) REFERENCES hareketler(id) ON DELETE CASCADE
)");

$pdo->exec("
CREATE TABLE IF NOT EXISTS stoklar (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    ad TEXT NOT NULL,
    birim TEXT NOT NULL DEFAULT 'adet',
    miktar REAL NOT NULL DEFAULT 0,
    fiyat REAL DEFAULT NULL,
    alis_fiyat REAL DEFAULT NULL,
    nakit_fiyat REAL DEFAULT NULL,
    vadeli_fiyat REAL DEFAULT NULL,
    aciklama TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");
try { $pdo->exec("ALTER TABLE stoklar ADD COLUMN alis_fiyat REAL DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE stoklar ADD COLUMN nakit_fiyat REAL DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE stoklar ADD COLUMN vadeli_fiyat REAL DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE stoklar ADD COLUMN barkod TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE stoklar ADD COLUMN api_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}

$pdo->exec("
CREATE TABLE IF NOT EXISTS ayarlar (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    anahtar TEXT NOT NULL,
    deger TEXT DEFAULT NULL,
    UNIQUE(user_id, anahtar),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$pdo->exec("
CREATE TABLE IF NOT EXISTS hatirlaticilar (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    baslik TEXT NOT NULL,
    aciklama TEXT DEFAULT NULL,
    tarih TEXT NOT NULL,
    saat TEXT NOT NULL,
    hatirlatma_dk INTEGER DEFAULT 0,
    durum TEXT DEFAULT 'aktif' CHECK(durum IN ('aktif','tamamlandi','sessiz')),
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");
try { $pdo->exec("ALTER TABLE hatirlaticilar ADD COLUMN tutar REAL DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE hatirlaticilar ADD COLUMN odeme_tip TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE hatirlaticilar ADD COLUMN api_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_hatirlaticilar_user ON hatirlaticilar(user_id, tarih)");

// Not Defteri — firma klasörleri ve ürün satırları
$pdo->exec("
CREATE TABLE IF NOT EXISTS not_klasorler (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    ad TEXT NOT NULL,
    renk TEXT DEFAULT '#3B82F6',
    sira INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$pdo->exec("
CREATE TABLE IF NOT EXISTS not_satirlar (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    klasor_id INTEGER NOT NULL,
    urun_adi TEXT NOT NULL,
    fiyat REAL DEFAULT 0,
    adet REAL DEFAULT 1,
    not_ TEXT DEFAULT NULL,
    sira INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (klasor_id) REFERENCES not_klasorler(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_not_klasorler_user ON not_klasorler(user_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_not_satirlar_klasor ON not_satirlar(klasor_id)");
try { $pdo->exec("ALTER TABLE not_klasorler ADD COLUMN not_bilgi TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE not_klasorler ADD COLUMN odeme_toplam REAL DEFAULT 0"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE cariler ADD COLUMN varsayilan INTEGER DEFAULT 0"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE not_satirlar ADD COLUMN birim TEXT DEFAULT 'adet'"); } catch(Exception $e) {}

// Projeler
$pdo->exec("
CREATE TABLE IF NOT EXISTS projeler (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    ad TEXT NOT NULL,
    musteri TEXT DEFAULT NULL,
    aciklama TEXT DEFAULT NULL,
    baslangic TEXT DEFAULT NULL,
    bitis TEXT DEFAULT NULL,
    durum TEXT DEFAULT 'devam' CHECK(durum IN ('devam','tamamlandi','beklemede')),
    butce REAL DEFAULT NULL,
    harcanan REAL DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");

$pdo->exec("
CREATE TABLE IF NOT EXISTS proje_gorevler (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    proje_id INTEGER NOT NULL,
    baslik TEXT NOT NULL,
    aciklama TEXT DEFAULT NULL,
    durum TEXT DEFAULT 'beklemede' CHECK(durum IN ('beklemede','devam','tamamlandi')),
    sira INTEGER DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (proje_id) REFERENCES projeler(id) ON DELETE CASCADE
)");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_projeler_user ON projeler(user_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_proje_gorevler ON proje_gorevler(proje_id)");

// Cari ek alanlar
try { $pdo->exec("ALTER TABLE cariler ADD COLUMN api_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE cariler ADD COLUMN musteri_kodu TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE cariler ADD COLUMN il TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE cariler ADD COLUMN ilce TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE cariler ADD COLUMN adres TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE cariler ADD COLUMN vergi_tc TEXT DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE cariler ADD COLUMN vergi_dairesi TEXT DEFAULT NULL"); } catch(Exception $e) {}

// Kasa hesapları (Ana Kasa, Banka, Pos vb.)
$pdo->exec("
CREATE TABLE IF NOT EXISTS kasa_hesaplari (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    ad TEXT NOT NULL,
    aciklama TEXT DEFAULT NULL,
    renk TEXT DEFAULT '#3B82F6',
    aktif INTEGER DEFAULT 1,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_kasa_hesap_user ON kasa_hesaplari(user_id)");
try { $pdo->exec("ALTER TABLE kasa_hesaplari ADD COLUMN varsayilan INTEGER DEFAULT 0"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE kasa_hesaplari ADD COLUMN api_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE kasa_hesaplari ADD COLUMN tip TEXT DEFAULT 'nakit' CHECK(tip IN ('nakit','banka'))"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE kasa_hesaplari ADD COLUMN bakiye REAL DEFAULT 0"); } catch(Exception $e) {}

// Kasa hareketleri
$pdo->exec("
CREATE TABLE IF NOT EXISTS kasa (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    hesap_id INTEGER DEFAULT NULL,
    tip TEXT NOT NULL CHECK(tip IN ('giris','cikis')),
    tutar REAL NOT NULL,
    aciklama TEXT DEFAULT NULL,
    kaynak TEXT DEFAULT NULL,
    kaynak_id INTEGER DEFAULT NULL,
    tarih TEXT NOT NULL DEFAULT (date('now','localtime')),
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (hesap_id) REFERENCES kasa_hesaplari(id) ON DELETE NO ACTION
)");
try { $pdo->exec("ALTER TABLE kasa ADD COLUMN hesap_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE kasa ADD COLUMN user_id INTEGER DEFAULT 1"); } catch(Exception $e) {}
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_kasa_user  ON kasa(user_id, tarih)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_kasa_hesap ON kasa(hesap_id)");

// Ödemeler — eski şemayla uyumsuzsa yeniden oluştur
$_odSql = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='odemeler'")->fetchColumn();
if ($_odSql && strpos($_odSql, "durum IN ('tamamlandi'") !== false) {
    // Eski şema — drop & recreate (eski kayıtlar farklı yapıda, yeniden başla)
    $pdo->exec("DROP TABLE IF EXISTS odemeler");
}
$pdo->exec("
CREATE TABLE IF NOT EXISTS odemeler (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    hesap_id INTEGER DEFAULT NULL,
    baslik TEXT NOT NULL DEFAULT '',
    aciklama TEXT DEFAULT NULL,
    tutar REAL NOT NULL DEFAULT 0,
    tarih TEXT NOT NULL DEFAULT (date('now','localtime')),
    tekrar TEXT DEFAULT 'yok',
    durum TEXT DEFAULT 'odendi',
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_odemeler_user  ON odemeler(user_id, tarih)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_odemeler_hesap ON odemeler(hesap_id)");
try { $pdo->exec("ALTER TABLE odemeler ADD COLUMN api_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}

// İndeksler — sık sorgulanan sütunlar
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_hareketler_cari   ON hareketler(cari_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_hareketler_tarih  ON hareketler(tarih)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_hareketler_grup   ON hareketler(grup_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_tahsilatlar_hareket ON tahsilatlar(hareket_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_stoklar_user      ON stoklar(user_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_cariler_user      ON cariler(user_id)");

// Gelir-Gider modülü
$pdo->exec("CREATE TABLE IF NOT EXISTS gelir_gider_kategoriler (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    ad TEXT NOT NULL,
    tip TEXT NOT NULL CHECK(tip IN ('gelir','gider')),
    renk TEXT DEFAULT '#3B82F6',
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS gelir_gider (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    hesap_id INTEGER DEFAULT NULL,
    kategori_id INTEGER DEFAULT NULL,
    tip TEXT NOT NULL CHECK(tip IN ('gelir','gider')),
    tutar REAL NOT NULL,
    aciklama TEXT DEFAULT NULL,
    tarih TEXT NOT NULL DEFAULT (date('now','localtime')),
    kasa_id INTEGER DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (hesap_id) REFERENCES kasa_hesaplari(id) ON DELETE SET NULL,
    FOREIGN KEY (kategori_id) REFERENCES gelir_gider_kategoriler(id) ON DELETE SET NULL,
    FOREIGN KEY (kasa_id) REFERENCES kasa(id) ON DELETE SET NULL
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_gelir_gider_user ON gelir_gider(user_id, tarih)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_gelir_gider_hesap ON gelir_gider(hesap_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_gg_kategoriler_user ON gelir_gider_kategoriler(user_id)");
try { $pdo->exec("ALTER TABLE gelir_gider ADD COLUMN cari_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}

// Varsayılan GG kategorileri (sadece ilk kullanıcı kaydında yoksa ekle)
$_ggKatCheck = $pdo->query("SELECT COUNT(*) FROM gelir_gider_kategoriler")->fetchColumn();
if ($_ggKatCheck == 0 && isset($_SESSION['user_id'])) {
    $_ggUid = (int)$_SESSION['user_id'];
    $ggDefaults = [
        ['Satış Geliri',    'gelir', '#16a34a'],
        ['Diğer Gelir',     'gelir', '#0891b2'],
        ['Kira',            'gider', '#dc2626'],
        ['Fatura/Abonelik', 'gider', '#ea580c'],
        ['Maaş/Personel',  'gider', '#7c3aed'],
        ['Malzeme/Stok',   'gider', '#f59e0b'],
        ['Diğer Gider',    'gider', '#64748b'],
    ];
    $ggIns = $pdo->prepare("INSERT OR IGNORE INTO gelir_gider_kategoriler (user_id, ad, tip, renk) VALUES (?,?,?,?)");
    foreach ($ggDefaults as $d) { $ggIns->execute([$_ggUid, $d[0], $d[1], $d[2]]); }
}

// Tedarikçiler modülü
$pdo->exec("CREATE TABLE IF NOT EXISTS tedarikciler (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    ad TEXT NOT NULL,
    yetkili TEXT DEFAULT NULL,
    telefon TEXT DEFAULT NULL,
    email TEXT DEFAULT NULL,
    adres TEXT DEFAULT NULL,
    not_ TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)");
// Eski şemadan migration: firma -> ad rename (SQLite 3.35+)
try { $pdo->exec("ALTER TABLE tedarikciler RENAME COLUMN firma TO ad"); } catch(Exception $e) {}
// email sütunu eski şemada eposta olarak olabilir
try { $pdo->exec("ALTER TABLE tedarikciler ADD COLUMN email TEXT DEFAULT NULL"); } catch(Exception $e) {}
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_tedarikciler_user ON tedarikciler(user_id)");

$pdo->exec("CREATE TABLE IF NOT EXISTS faturalar (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    tedarikci_id INTEGER NOT NULL,
    fatura_no TEXT NOT NULL DEFAULT '',
    tarih TEXT NOT NULL DEFAULT (date('now','localtime')),
    vade_tarihi TEXT DEFAULT NULL,
    toplam_tutar REAL NOT NULL DEFAULT 0,
    kdv_toplam REAL NOT NULL DEFAULT 0,
    genel_toplam REAL NOT NULL DEFAULT 0,
    durum TEXT NOT NULL DEFAULT 'odenmedi',
    aciklama TEXT DEFAULT NULL,
    created_at TEXT DEFAULT (datetime('now','localtime')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (tedarikci_id) REFERENCES tedarikciler(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_faturalar_user       ON faturalar(user_id, tarih)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_faturalar_tedarikci  ON faturalar(tedarikci_id)");
try { $pdo->exec("ALTER TABLE faturalar ADD COLUMN odeme_hesap_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}
try { $pdo->exec("ALTER TABLE faturalar ADD COLUMN odeme_kasa_id INTEGER DEFAULT NULL"); } catch(Exception $e) {}

$pdo->exec("CREATE TABLE IF NOT EXISTS fatura_satirlar (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fatura_id INTEGER NOT NULL,
    urun_adi TEXT NOT NULL,
    miktar REAL NOT NULL DEFAULT 1,
    birim TEXT NOT NULL DEFAULT 'adet',
    birim_fiyat REAL NOT NULL DEFAULT 0,
    kdv_oran REAL NOT NULL DEFAULT 0,
    toplam REAL NOT NULL DEFAULT 0,
    FOREIGN KEY (fatura_id) REFERENCES faturalar(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_fatura_satirlar_fatura ON fatura_satirlar(fatura_id)");

endif;
