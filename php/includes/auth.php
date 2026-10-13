<?php
if (session_status() === PHP_SESSION_NONE) {
    $sessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veresiyem_sess';
    if (!is_dir($sessDir)) @mkdir($sessDir, 0777, true);
    session_save_path($sessDir);
    session_start();
}

function girisYapildi(): bool {
    return isset($_SESSION['user_id']);
}

function girisGerekli(): void {
    if (!girisYapildi()) {
        header('Location: /giris');
        exit;
    }
}

function userId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function demoMod(): bool {
    return !empty($_SESSION['demo_mod']);
}

define('LISANS_API_URL', 'https://veresiyem.com.tr/lisans-panel/?action=kontrol');
define('LISANS_CACHE_SURE', 3600); // 1 saat

function lisansOnlineKontrol(string $kod): bool {
    $uuidFile = (getenv('VERESIYEM_DATA_DIR') ?: sys_get_temp_dir()) . '/device_id.txt';
    $uuid = file_exists($uuidFile) ? trim(file_get_contents($uuidFile)) : '';
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['kod' => $kod, 'uuid' => $uuid]),
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents(LISANS_API_URL, false, $ctx);
    if ($resp === false) return true; // sunucuya ulaşılamazsa izin ver (offline)
    $data = json_decode($resp, true);
    return !empty($data['ok']);
}

function lisansAktif(): bool {
    global $pdo;
    if (!isset($pdo) || !girisYapildi() || demoMod()) return false;
    $r = $pdo->prepare("SELECT lisans_kodu FROM users WHERE id=? AND lisans_kodu IS NOT NULL AND lisans_kodu != ''");
    $r->execute([userId()]);
    $row = $r->fetch();
    if (!$row) return false;

    $kod       = $row['lisans_kodu'];
    $cacheKey  = 'lisans_ok_' . md5($kod);
    $cacheTime = 'lisans_ts_' . md5($kod);

    // Cache geçerliyse tekrar kontrol etme
    if (!empty($_SESSION[$cacheKey]) && !empty($_SESSION[$cacheTime])) {
        if ((time() - $_SESSION[$cacheTime]) < LISANS_CACHE_SURE) {
            return (bool)$_SESSION[$cacheKey];
        }
    }

    // Online kontrol yap
    $sonuc = lisansOnlineKontrol($kod);
    $_SESSION[$cacheKey]  = $sonuc;
    $_SESSION[$cacheTime] = time();

    return $sonuc;
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfKontrol(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Geçersiz istek.');
    }
}
