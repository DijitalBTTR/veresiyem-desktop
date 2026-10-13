<?php
/**
 * veresiyem.com.tr/app-ping
 * Electron uygulamasından gelen install/open ping'lerini kayıt eder.
 * Bu dosya sunucuya yüklenir, yerel PHP sunucusunda çalışmaz.
 */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// --- Veritabanı bağlantısı ---
// Sunucudaki .env veya doğrudan yazın
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'veresiyem_stats';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'err' => 'db']);
    exit;
}

// --- Tablo oluştur (ilk çalıştırmada) ---
$pdo->exec("CREATE TABLE IF NOT EXISTS app_pings (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event       ENUM('install','open') NOT NULL,
    uuid        VARCHAR(64) NOT NULL,
    version     VARCHAR(20) DEFAULT NULL,
    platform    VARCHAR(20) DEFAULT NULL,
    arch        VARCHAR(20) DEFAULT NULL,
    country     VARCHAR(8)  DEFAULT NULL,
    ip_hash     VARCHAR(64) DEFAULT NULL,
    created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event),
    INDEX idx_uuid  (uuid),
    INDEX idx_date  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// --- İstek verisini oku ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $d   = json_decode($raw, true) ?: [];
} else {
    $d = $_GET;
}

$event    = in_array($d['event'] ?? '', ['install', 'open']) ? $d['event'] : 'open';
$uuid     = preg_replace('/[^a-f0-9\-]/', '', substr($d['uuid']    ?? '', 0, 64));
$version  = preg_replace('/[^0-9\.]/',   '', substr($d['version']  ?? '', 0, 20));
$platform = preg_replace('/[^a-z0-9_]/', '', substr($d['platform'] ?? '', 0, 20));
$arch     = preg_replace('/[^a-z0-9_]/', '', substr($d['arch']     ?? '', 0, 20));

if (!$uuid) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'err' => 'uuid_missing']);
    exit;
}

// IP'yi hash'le (KVKK — ham IP saklamıyoruz)
$ip_hash = hash('sha256', ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '') . 'vrm_salt_2024');

// Ülke kodu (isteğe bağlı, Cloudflare header'ı kullanıyorsa)
$country = preg_replace('/[^A-Z]/', '', substr($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '', 0, 8));

// install event: aynı UUID'den daha önce install kaydı varsa tekrar ekleme
if ($event === 'install') {
    $chk = $pdo->prepare("SELECT id FROM app_pings WHERE event='install' AND uuid=? LIMIT 1");
    $chk->execute([$uuid]);
    if ($chk->fetchColumn()) {
        echo json_encode(['ok' => true, 'dup' => true]);
        exit;
    }
}

$stmt = $pdo->prepare("INSERT INTO app_pings (event, uuid, version, platform, arch, country, ip_hash) VALUES (?,?,?,?,?,?,?)");
$stmt->execute([$event, $uuid, $version ?: null, $platform ?: null, $arch ?: null, $country ?: null, $ip_hash]);

echo json_encode(['ok' => true]);
