<?php
/**
 * Veresiyem Lisans Key Üretici
 * Kullanım: php lisans-uret.php [adet]
 * Örnek:    php lisans-uret.php 5
 */

// !! BU ANAHTARI GİZLİ TUTUN — Değiştirirseniz eski keyler geçersiz olur !!
define('SECRET', 'VRM_2024_x9kQpL#mN!wZr7@tYjK');

function keyUret(): string {
    // 12 rastgele alfanumerik karakter (3 grup x 4)
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Karışıklık yaratacak karakterler çıkarıldı (0,O,1,I)
    $part1 = '';
    $part2 = '';
    $part3 = '';
    for ($i = 0; $i < 4; $i++) $part1 .= $chars[random_int(0, strlen($chars)-1)];
    for ($i = 0; $i < 4; $i++) $part2 .= $chars[random_int(0, strlen($chars)-1)];
    for ($i = 0; $i < 4; $i++) $part3 .= $chars[random_int(0, strlen($chars)-1)];

    $payload = $part1 . $part2 . $part3;

    // HMAC-SHA256 ile imzala, ilk 4 karakteri al
    $hmac = strtoupper(substr(hash_hmac('sha256', $payload, SECRET), 0, 4));

    return "VRM-{$part1}-{$part2}-{$part3}-{$hmac}";
}

function keyDogrula(string $key): bool {
    $key = strtoupper(trim($key));
    // Format: VRM-XXXX-XXXX-XXXX-XXXX
    if (!preg_match('/^VRM-([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z2-9]{4})-([A-Z0-9]{4})$/', $key, $m)) {
        return false;
    }
    $payload  = $m[1] . $m[2] . $m[3];
    $beklenen = strtoupper(substr(hash_hmac('sha256', $payload, SECRET), 0, 4));
    return hash_equals($beklenen, $m[4]);
}

// CLI kullanımı
$adet = (int)($argv[1] ?? 1);
if ($adet < 1) $adet = 1;
if ($adet > 100) $adet = 100;

echo "\n=== Veresiyem Lisans Key Üretici ===\n\n";
for ($i = 0; $i < $adet; $i++) {
    $key = keyUret();
    $gecerli = keyDogrula($key) ? '✓ Geçerli' : '✗ HATA';
    echo "  {$key}   [{$gecerli}]\n";
}
echo "\nToplamda {$adet} key üretildi.\n\n";
