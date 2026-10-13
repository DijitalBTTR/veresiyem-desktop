<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// Statik dosyalar için built-in server'ın kendi handler'ını kullan
$static = __DIR__ . $path;
if ($path !== '/' && file_exists($static) && !is_dir($static)) {
    return false;
}

$routes = [
    '/'             => 'index.php',
    '/giris'        => 'giris.php',
    '/cikis'        => 'cikis.php',
    '/lisans'       => 'lisans.php',
    '/cariler'      => 'cariler.php',
    '/cari'         => 'cari.php',
    '/cari-ekle'    => 'cari-ekle.php',
    '/cari-duzenle' => 'cari-duzenle.php',
    '/cari-sil'     => 'cari-sil.php',
    '/cari-ekstre'  => 'cari-ekstre.php',
    '/cari-ozet'    => 'cari-ozet.php',
    '/stok'         => 'stok.php',
    '/stok-ekle'    => 'stok-ekle.php',
    '/stok-duzenle' => 'stok-duzenle.php',
    '/stok-sil'     => 'stok-sil.php',
    '/stok-json'    => 'stok-json.php',
    '/arama-json'   => 'arama-json.php',
    '/export-cari'  => 'export-cari.php',
    '/export-tumu'  => 'export-tumu.php',
    '/profil'       => 'profil.php',
    '/alt-hesaplar' => 'alt-hesaplar.php',
    '/raporlar'     => 'raporlar.php',
    '/barkod'       => 'barkod.php',
    '/fis-test'     => 'fis-test.php',
    '/hizli-satis'  => 'hizli-satis.php',
    '/tahsilat-al'      => 'tahsilat-al.php',
    '/takvim'             => 'takvim.php',
    '/not-defteri'        => 'not-defteri.php',
    '/hatirlaticilar'     => 'hatirlaticilar.php',
    '/hatirlatici-sil'    => 'hatirlatici-sil.php',
    '/hatirlatici-durum'  => 'hatirlatici-durum.php',
    '/hatirlatici-duzenle'   => 'hatirlatici-duzenle.php',
    '/hatirlatici-kontrol'   => 'hatirlatici-kontrol.php',
    '/hatirlatici-popup'     => 'hatirlatici-popup.php',
    '/hatirlatici-toast'     => 'hatirlatici-toast.php',
    '/projeler'              => 'projeler.php',
    '/proje'                 => 'proje.php',
    '/kasa'                  => 'kasa.php',
    '/kasa-duzenle'          => 'kasa-duzenle.php',
    '/odemeler'              => 'odemeler.php',
    '/gelir-gider'           => 'gelir-gider.php',
    '/gelir-gider-ekle'      => 'gelir-gider-ekle.php',
    '/gelir-gider-duzenle'   => 'gelir-gider-duzenle.php',
    '/gelir-gider-sil'       => 'gelir-gider-sil.php',
    '/gg-kategori-ekle'      => 'gg-kategori-ekle.php',
    '/gg-kategori-sil'       => 'gg-kategori-sil.php',
    '/tedarikciler'          => 'tedarikciler.php',
    '/tedarikci'             => 'tedarikci.php',
    '/tedarikci-ekle'        => 'tedarikci-ekle.php',
    '/tedarikci-duzenle'     => 'tedarikci-duzenle.php',
    '/tedarikci-sil'         => 'tedarikci-sil.php',
    '/tedarikci-odeme'       => 'tedarikci-odeme.php',
    '/faturalar'             => 'faturalar.php',
    '/fatura'                => 'fatura.php',
    '/fatura-ekle'           => 'fatura-ekle.php',
    '/fatura-durum'          => 'fatura-durum.php',
    '/fatura-sil'            => 'fatura-sil.php',
];

if (isset($routes[$path])) {
    require __DIR__ . '/' . $routes[$path];
} else {
    http_response_code(404);
    require __DIR__ . '/404.php';
}
