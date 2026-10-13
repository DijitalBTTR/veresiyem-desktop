<?php
function demoTempDbOlustur(): string {
    $sessId  = session_id() ?: bin2hex(random_bytes(8));
    $tmpDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'veresiyem_demo';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
    return $tmpDir . DIRECTORY_SEPARATOR . 'demo_' . $sessId . '.db';
}

function demoKur(PDO $pdo): int {
    $pdo->prepare("INSERT INTO users (ad, sifre, demo_mod, lisans_aktif) VALUES (?, ?, 1, 1)")
        ->execute(['Demo Kullanıcı', password_hash('demo', PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();

    $cariler = [
        ['Ahmet Usta',   '0532 111 22 33'],
        ['Fatma Hanım',  '0533 444 55 66'],
        ['Mehmet Bey',   '0541 777 88 99'],
        ['Zeynep Çelik', '0505 321 00 11'],
        ['Kadir Demir',  '0546 888 77 66'],
        ['Selin Yıldız', '0553 222 33 44'],
    ];
    $cariIds = [];
    $stmt = $pdo->prepare("INSERT INTO cariler (user_id, ad, telefon) VALUES (?,?,?)");
    foreach ($cariler as $c) {
        $stmt->execute([$uid, $c[0], $c[1]]);
        $cariIds[] = (int)$pdo->lastInsertId();
    }

    $stoklar = [
        ['Ekmek',  'adet',  120, 2.50],
        ['Süt',    'litre',  40, 18.00],
        ['Peynir', 'kg',     15, 120.00],
        ['Un',     'kg',     80, 25.00],
        ['Yağ',    'lt',      0, 65.00],
    ];
    $ss = $pdo->prepare("INSERT INTO stoklar (user_id, ad, birim, miktar, fiyat) VALUES (?,?,?,?,?)");
    foreach ($stoklar as $s) {
        $ss->execute([$uid, $s[0], $s[1], $s[2], $s[3]]);
    }

    $hareketler = [
        [$cariIds[0], 'alacak', 1400.00, 'Haftalık alışveriş',  date('Y-m-d', strtotime('-2 days'))],
        [$cariIds[0], 'borc',    300.00, 'Kısmi ödeme',         date('Y-m-d', strtotime('-1 day'))],
        [$cariIds[1], 'alacak',  200.00, 'Süt ve peynir',       date('Y-m-d', strtotime('-3 days'))],
        [$cariIds[1], 'borc',    200.00, 'Ödeme alındı',        date('Y-m-d')],
        [$cariIds[2], 'alacak',  750.00, 'Market alışverişi',   date('Y-m-d', strtotime('-5 days'))],
        [$cariIds[3], 'alacak',  900.00, 'Kasap siparişi',      date('Y-m-d', strtotime('-1 day'))],
        [$cariIds[3], 'borc',    450.00, 'Yarısını ödedi',      date('Y-m-d')],
        [$cariIds[4], 'alacak',  680.00, 'Çeşitli ürünler',     date('Y-m-d', strtotime('-4 days'))],
        [$cariIds[5], 'alacak',  120.00, 'Ekmek × 10',          date('Y-m-d')],
    ];
    $hs = $pdo->prepare("INSERT INTO hareketler (cari_id, tip, tutar, aciklama, tarih) VALUES (?,?,?,?,?)");
    foreach ($hareketler as $h) {
        $hs->execute($h);
    }

    // Kasa hesabı
    $pdo->prepare("INSERT INTO kasa_hesaplari (user_id, ad, renk, aktif, varsayilan) VALUES (?,?,?,1,1)")
        ->execute([$uid, 'Ana Kasa', '#10B981']);
    $kasaId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, kaynak, tarih) VALUES (?,?,?,?,?,?,?)")
        ->execute([$uid, $kasaId, 'giris', 25000.00, 'Açılış bakiyesi', 'acilis', date('Y-m-d', strtotime('-30 days'))]);

    // Tedarikçiler
    $tedarikciler = [
        ['Renkçi Boya Toptan Ltd.',     '0212 544 11 22', 'info@renkciboya.com',    'İstanbul, İkitelli OSB, Boya Blok No:12'],
        ['Demirci Hırdavat A.Ş.',       '0216 388 77 55', 'satis@demircihirdavat.com', 'İstanbul, Ümraniye, Sanayi Mah.'],
        ['Teknik Yapı Malzemeleri',     '0312 422 66 33', 'teknikyapi@example.com', 'Ankara, Ostim OSB'],
        ['Atlas Zımpara ve Aşındırıcı', '0232 711 44 88', 'atlas@zimpara.com',      'İzmir, Kemalpaşa OSB'],
    ];
    $tIds = [];
    $ts = $pdo->prepare("INSERT INTO tedarikciler (user_id, ad, telefon, email, adres) VALUES (?,?,?,?,?)");
    foreach ($tedarikciler as $td) {
        $ts->execute([$uid, $td[0], $td[1], $td[2], $td[3]]);
        $tIds[] = (int)$pdo->lastInsertId();
    }

    // Faturalar ve satırları
    $faturalar = [
        // [tedarikci_idx, fatura_no, tarih_offset, vade_offset, durum, satirlar]
        [
            0, 'FTR-2025-001', -50, -20, 'odendi',
            [
                ['Dış Cephe Boyası 15lt (Beyaz)',        20, 'adet', 485.00, 20],
                ['Dış Cephe Boyası 15lt (Krem)',         12, 'adet', 485.00, 20],
                ['İç Cephe Boyası 15lt (Beyaz)',         18, 'adet', 320.00, 20],
                ['İç Cephe Boyası 15lt (Kırık Beyaz)',   10, 'adet', 320.00, 20],
                ['Astar Boya Dış Cephe 10lt',            10, 'adet', 195.00, 20],
                ['Astar Boya İç Cephe 10lt',              8, 'adet', 175.00, 20],
                ['Boya Solvanı 1lt',                     24, 'adet',  38.00, 20],
                ['Boya Kasesi 5lt',                      15, 'adet',  22.00, 20],
                ['Rulo Teli Kısa 23cm',                  30, 'adet',  18.50, 20],
                ['Fırça Seti 3\'lü (25-50-75mm)',        12, 'adet',  85.00, 20],
            ]
        ],
        [
            0, 'FTR-2025-002', -18, 12, 'odenmedi',
            [
                ['Dış Cephe Boyası 15lt (Bej)',          25, 'adet', 495.00, 20],
                ['Dış Cephe Boyası 15lt (Gri)',          15, 'adet', 495.00, 20],
                ['Vernik Mat 5lt',                       10, 'adet', 280.00, 20],
                ['Vernik Parlak 5lt',                     8, 'adet', 280.00, 20],
                ['Tahta Koruyucu 3lt',                   12, 'adet', 215.00, 20],
                ['Antipas Boya 2.5lt (Kırmızı)',          6, 'adet', 195.00, 20],
                ['Antipas Boya 2.5lt (Gri)',              6, 'adet', 195.00, 20],
                ['Tiner 1lt',                            40, 'adet',  28.00, 20],
                ['Boya Karıştırıcı (El)',                10, 'adet',  45.00, 20],
                ['Maskeleme Bandı 48mm×50m',             50, 'adet',  24.00, 20],
                ['Plastik Örtü 4×5m',                   20, 'adet',  35.00, 20],
            ]
        ],
        [
            1, 'FTR-2025-003', -35, -5, 'odenmedi',
            [
                ['Çivi Kutusu 1kg (60mm)',               50, 'kutu',  18.50, 20],
                ['Çivi Kutusu 1kg (90mm)',               30, 'kutu',  19.50, 20],
                ['Çivi Kutusu 1kg (40mm)',               40, 'kutu',  17.50, 20],
                ['Vida Seti M6×40 (100\'lü)',            30, 'kutu',  24.00, 20],
                ['Vida Seti M5×30 (100\'lü)',            25, 'kutu',  22.00, 20],
                ['Cıvata + Somun M8×50 (50\'li)',        20, 'kutu',  32.00, 20],
                ['Somun M8 Uzun (50\'li)',               20, 'kutu',  15.00, 20],
                ['Pul M6 (100\'lü)',                     30, 'kutu',   8.50, 20],
                ['Çekiç 500g Ahşap Sap',                15, 'adet',  85.00, 20],
                ['Tornavida Seti 6\'lı PH/PZ',          10, 'adet', 145.00, 20],
                ['L Anahtar Takımı 9\'lu',               8, 'adet', 185.00, 20],
                ['Kerpeten 200mm',                       6, 'adet',  92.00, 20],
            ]
        ],
        [
            1, 'FTR-2025-004', -8, 22, 'odenmedi',
            [
                ['Mengene Masa Tipi 150mm',               5, 'adet', 420.00, 20],
                ['Pense Takımı 3\'lü',                   10, 'adet', 185.00, 20],
                ['Kombine Anahtar Seti 12\'li',           8, 'adet', 345.00, 20],
                ['Şerit Metre 5m Manyetik',              20, 'adet',  62.00, 20],
                ['Su Terazisi 60cm',                     10, 'adet', 155.00, 20],
                ['Vidalı Mastar 150mm',                   6, 'adet', 210.00, 20],
                ['Keski Seti 4\'lü',                     10, 'adet', 120.00, 20],
                ['Testere Bıçağı 10\'lu',                15, 'paket',  48.00, 20],
            ]
        ],
        [
            2, 'FTR-2025-005', -60, -30, 'odendi',
            [
                ['Alçı 25kg Çuval',                      40, 'adet',  85.00, 20],
                ['Yapı Alçısı 25kg',                     20, 'adet',  92.00, 20],
                ['Çimento 25kg CEM I',                   30, 'adet',  95.00, 20],
                ['Hazır Harç 25kg',                      25, 'adet', 110.00, 20],
                ['Kum İnce Yıkanmış 50kg',               20, 'adet',  45.00, 20],
                ['Çimento Şap 25kg',                     15, 'adet',  88.00, 20],
            ]
        ],
        [
            2, 'FTR-2025-006', -12, 18, 'odenmedi',
            [
                ['Dübel + Vida Takımı 200\'lü (6mm)',    25, 'paket',  32.00, 20],
                ['Dübel + Vida Takımı 100\'lü (8mm)',    20, 'paket',  28.00, 20],
                ['Kimyasal Dübel 300ml',                 10, 'adet', 145.00, 20],
                ['PU Köpük Dolgu 750ml',                 30, 'adet',  58.00, 20],
                ['Silikon Şeffaf 280ml',                 40, 'adet',  28.00, 20],
                ['Silikon Beyaz 280ml',                  35, 'adet',  28.00, 20],
                ['Akrilik Macun 280ml',                  25, 'adet',  32.00, 20],
                ['Çift Taraflı Bant 19mm×10m',          50, 'adet',  14.50, 20],
                ['Köpük Bant 9mm×10m',                  40, 'adet',  12.00, 20],
            ]
        ],
        [
            3, 'FTR-2025-007', -25, 5, 'odenmedi',
            [
                ['Zımpara Kağıdı P80 (25\'li)',          10, 'paket',  42.00, 20],
                ['Zımpara Kağıdı P120 (25\'li)',         10, 'paket',  42.00, 20],
                ['Zımpara Kağıdı P180 (25\'li)',          8, 'paket',  42.00, 20],
                ['Zımpara Kağıdı P240 (25\'li)',          8, 'paket',  42.00, 20],
                ['Zımpara Kağıdı P400 (25\'li)',          6, 'paket',  48.00, 20],
                ['Disk Zımpara 125mm P80 (10\'lu)',      15, 'paket',  65.00, 20],
                ['Disk Zımpara 125mm P120 (10\'lu)',     15, 'paket',  65.00, 20],
                ['Disk Zımpara 125mm P240 (10\'lu)',     10, 'paket',  65.00, 20],
                ['Kemer Zımpara 75×533 P80 (10\'lu)',    10, 'paket',  78.00, 20],
                ['Kemer Zımpara 75×533 P120 (10\'lu)',   10, 'paket',  78.00, 20],
            ]
        ],
        [
            3, 'FTR-2025-008', -55, -25, 'odendi',
            [
                ['Rulo Zımpara 93×5m P80',               20, 'adet',  38.00, 20],
                ['Rulo Zımpara 93×5m P120',              15, 'adet',  38.00, 20],
                ['Rulo Zımpara 93×5m P240',              10, 'adet',  38.00, 20],
                ['Kaba Zımpara Sünger P60 (10\'lu)',     15, 'paket',  55.00, 20],
                ['İnce Zımpara Sünger P180 (10\'lu)',    12, 'paket',  55.00, 20],
            ]
        ],
    ];

    $fIns  = $pdo->prepare("INSERT INTO faturalar (user_id, tedarikci_id, fatura_no, tarih, vade_tarihi, toplam_tutar, kdv_toplam, genel_toplam, durum) VALUES (?,?,?,?,?,?,?,?,?)");
    $fsIns = $pdo->prepare("INSERT INTO fatura_satirlar (fatura_id, urun_adi, miktar, birim, birim_fiyat, kdv_oran, toplam) VALUES (?,?,?,?,?,?,?)");

    foreach ($faturalar as $f) {
        [$tIdx, $fNo, $tarihOfs, $vadeOfs, $durum, $satirlar] = $f;
        $tarih = date('Y-m-d', strtotime($tarihOfs . ' days'));
        $vade  = date('Y-m-d', strtotime($vadeOfs . ' days'));

        $araToplam = 0;
        $kdvToplam = 0;
        foreach ($satirlar as $s) {
            $satTop = $s[1] * $s[3];
            $araToplam += $satTop;
            $kdvToplam += $satTop * $s[4] / 100;
        }
        $genelToplam = round($araToplam + $kdvToplam, 2);

        $fIns->execute([$uid, $tIds[$tIdx], $fNo, $tarih, $vade, round($araToplam, 2), round($kdvToplam, 2), $genelToplam, $durum]);
        $faturaId = (int)$pdo->lastInsertId();

        foreach ($satirlar as $s) {
            $satTop = round($s[1] * $s[3] * (1 + $s[4] / 100), 2);
            $fsIns->execute([$faturaId, $s[0], $s[1], $s[2], $s[3], $s[4], $satTop]);
        }

        // Ödendi ise kasadan çıkış yap
        if ($durum === 'odendi') {
            $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, kaynak, kaynak_id, tarih) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$uid, $kasaId, 'cikis', $genelToplam, $tedarikciler[$tIdx][0] . ' ödemesi — ' . $fNo, 'tedarikci_odeme', $tIds[$tIdx], $tarih]);
        }
    }

    return $uid;
}
