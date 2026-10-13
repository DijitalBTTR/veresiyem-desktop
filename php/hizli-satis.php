<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'hizli-satis';
$uid = userId();

$firmaRow = $pdo->prepare("SELECT deger FROM ayarlar WHERE user_id=? AND anahtar='firma_adi'");
$firmaRow->execute([$uid]);
$firmaRow = $firmaRow->fetch();
$firmaAdi = ($firmaRow && $firmaRow['deger']) ? $firmaRow['deger'] : 'Veresiyem';

$kasaHesaplari = $pdo->prepare("SELECT id, ad, varsayilan FROM kasa_hesaplari WHERE user_id=? AND aktif=1 ORDER BY id ASC");
$kasaHesaplari->execute([$uid]);
$kasaHesaplari = $kasaHesaplari->fetchAll();
$varsayilanKasaId = 0;
foreach ($kasaHesaplari as $kh) { if (!empty($kh['varsayilan'])) { $varsayilanKasaId = $kh['id']; break; } }

$error = $success = '';
$yeniCariId = null;

// Yeni cari ekle (AJAX-style POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'yeni-cari') {
    csrfKontrol();
    $ad = trim($_POST['ad'] ?? '');
    $tel = trim($_POST['telefon'] ?? '');
    if ($ad) {
        $pdo->prepare("INSERT INTO cariler (user_id,ad,telefon) VALUES (?,?,?)")
            ->execute([$uid, $ad, $tel ?: null]);
        $yeniCariId = $pdo->lastInsertId();
        apiSync('post', '/cariler', ['ad' => $ad, 'telefon' => $tel ?: null]);
        header('Content-Type: application/json');
        echo json_encode(['id' => $yeniCariId, 'ad' => $ad]);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Ad zorunlu']);
    exit;
}

// Satış kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'satis-kaydet') {
    csrfKontrol();
    $cariId   = (int)($_POST['cari_id'] ?? 0);
    $tip      = $_POST['tip'] ?? 'alacak';
    $tarih    = trim($_POST['tarih'] ?? date('Y-m-d'));
    $vade     = trim($_POST['vade_tarihi'] ?? '') ?: null;
    $kalemler = $_POST['kalemler'] ?? [];
    $indirim  = max(0, (float)str_replace(',', '.', $_POST['indirim'] ?? '0'));
    $kasaHesapId = (int)($_POST['kasa_hesap_id'] ?? 0) ?: null;

    $chkCari = $pdo->prepare("SELECT id FROM cariler WHERE id=? AND user_id=?");
    $chkCari->execute([$cariId, $uid]);

    if (!$cariId || !$chkCari->fetch()) {
        $error = 'Geçerli bir cari seçin.';
    } elseif (!in_array($tip, ['borc', 'alacak'])) {
        $error = 'Geçersiz işlem tipi.';
    } elseif (empty($kalemler)) {
        $error = 'Sepete en az 1 ürün ekleyin.';
    } else {
        $pdo->beginTransaction();
        try {
            $grupId      = 'g_' . uniqid();
            $toplamTutar = 0;
            $fisKalemler = [];

            foreach ($kalemler as $k) {
                $stok_id     = (int)($k['stok_id'] ?? 0) ?: null;
                $miktar      = (float)str_replace(',', '.', $k['miktar'] ?? '1');
                $birimFiyat  = (float)str_replace(',', '.', $k['birim_fiyat'] ?? '0');
                $kTutar      = (float)str_replace(',', '.', $k['tutar'] ?? '0');
                $aciklama    = trim($k['aciklama'] ?? '');
                $stok_miktar = $stok_id ? $miktar : null;

                if ($kTutar <= 0) continue;

                $pdo->prepare("INSERT INTO hareketler (cari_id,tip,tutar,aciklama,tarih,vade_tarihi,stok_id,stok_miktar,grup_id) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$cariId, $tip, $kTutar, $aciklama ?: null, $tarih, $vade, $stok_id, $stok_miktar, $grupId]);

                if ($stok_id && $miktar > 0) {
                    $pdo->prepare("UPDATE stoklar SET miktar = miktar - ? WHERE id = ? AND user_id = ?")
                        ->execute([$miktar, $stok_id, $uid]);
                }
                $toplamTutar += $kTutar;
                $fisKalemler[] = ['aciklama' => $aciklama, 'tutar' => $kTutar, 'miktar' => $miktar, 'stok_id' => $stok_id];
            }

            // İndirim kalemi
            $indirimTutar = 0;
            if ($indirim > 0 && $toplamTutar > 0) {
                $indirimTutar = min($indirim, $toplamTutar);
                $pdo->prepare("INSERT INTO hareketler (cari_id,tip,tutar,aciklama,tarih,vade_tarihi,stok_id,stok_miktar,grup_id) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$cariId, $tip, -$indirimTutar, 'İndirim', $tarih, $vade, null, null, $grupId]);
                $toplamTutar -= $indirimTutar;
                $fisKalemler[] = ['aciklama' => 'İndirim', 'tutar' => -$indirimTutar, 'miktar' => null, 'stok_id' => null];
            }

            // Nakit satış ise kasaya giriş kaydı
            if ($tip === 'alacak' && $toplamTutar > 0) {
                $cariAdRow = $pdo->prepare("SELECT ad FROM cariler WHERE id=?");
                $cariAdRow->execute([$cariId]);
                $cariAdRow = $cariAdRow->fetch();
                $kasaAciklama = 'Nakit Satış — ' . ($cariAdRow['ad'] ?? 'Cari #'.$cariId);
                $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, kaynak, kaynak_id, tarih) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$uid, $kasaHesapId, 'giris', $toplamTutar, $kasaAciklama, 'hizli_satis', $cariId, $tarih]);
            }

            $pdo->commit();

            // API'ye hızlı satış gönder (VPS 'satirlar' + nakit/vadeli tip bekliyor)
            $vpsKalemTip = ($tip === 'alacak') ? 'nakit' : 'vadeli';
            $vpsSatirlar = [];
            foreach ($fisKalemler as $fk) {
                if ($fk['aciklama'] === 'İndirim') continue; // indirim ayrı gidiyor
                // Stok api_id'sini bul
                $stokApiId = null;
                if (!empty($fk['stok_id'])) {
                    $sapiRow = $pdo->prepare("SELECT api_id FROM stoklar WHERE id=? AND user_id=?");
                    $sapiRow->execute([$fk['stok_id'], $uid]);
                    $sapiRow = $sapiRow->fetch();
                    $stokApiId = $sapiRow['api_id'] ?? null;
                }
                $vpsSatirlar[] = [
                    'stok_id' => $stokApiId,
                    'ad'      => $fk['aciklama'],
                    'miktar'  => $fk['miktar'] ?? 1,
                    'fiyat'   => $fk['tutar'],
                ];
            }
            // Kasa hesabının api_id'sini bul
            $kasaApiId = null;
            if ($kasaHesapId) {
                $khRow = $pdo->prepare("SELECT api_id FROM kasa_hesaplari WHERE id=? AND user_id=?");
                $khRow->execute([$kasaHesapId, $uid]);
                $khRow = $khRow->fetch();
                $kasaApiId = $khRow['api_id'] ?? null;
            }
            // Cari api_id bul
            $cariApiId = null;
            $capiRow = $pdo->prepare("SELECT api_id FROM cariler WHERE id=? AND user_id=?");
            $capiRow->execute([$cariId, $uid]);
            $capiRow = $capiRow->fetch();
            $cariApiId = $capiRow['api_id'] ?? null;

            apiSync('post', '/hizli-satis', [
                'cari_id'    => $cariApiId,
                'tip'        => $vpsKalemTip,
                'tarih'      => $tarih,
                'vade_tarihi'=> $vade,
                'indirim'    => $indirimTutar,
                'hesap_id'   => $kasaApiId,
                'satirlar'   => $vpsSatirlar,
            ]);

            // Cari toplam bakiyesini hesapla
            $bRow = $pdo->prepare("
                SELECT
                  COALESCE(SUM(CASE WHEN tip='borc' THEN tutar ELSE -tutar END), 0) AS toplam_borc,
                  COALESCE((SELECT SUM(tutar) FROM tahsilatlar WHERE cari_id=?), 0) AS toplam_tahsilat
                FROM hareketler WHERE cari_id=?
            ");
            $bRow->execute([$cariId, $cariId]);
            $bRow = $bRow->fetch();
            $kalanBorc = max(0, (float)$bRow['toplam_borc'] - (float)$bRow['toplam_tahsilat']);

            // Fatura verisini session'a yaz, JS okusun
            $_SESSION['son_satis'] = [
                'cari_id'    => $cariId,
                'tip'        => $tip,
                'tarih'      => $tarih,
                'vade'       => $vade,
                'toplam'     => $toplamTutar,
                'indirim'    => $indirimTutar,
                'kalemler'   => $fisKalemler,
                'grup_id'    => $grupId,
                'kalan_borc' => $kalanBorc,
            ];
            header('Location: /hizli-satis?msg=ok');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Kayıt hatası: ' . $e->getMessage();
        }
    }
}

// Stokları çek
$stoklar = $pdo->prepare("SELECT id,ad,birim,miktar,nakit_fiyat,vadeli_fiyat,barkod FROM stoklar WHERE user_id=? ORDER BY ad ASC");
$stoklar->execute([$uid]);
$stoklar = $stoklar->fetchAll();

// Carileri çek
$carilerStmt = $pdo->prepare("SELECT id,ad,telefon,varsayilan FROM cariler WHERE user_id=? ORDER BY ad ASC");
$carilerStmt->execute([$uid]);
$carilerJson = $carilerStmt->fetchAll();
$varsayilanCari = null;
foreach ($carilerJson as $c) {
    if (!empty($c['varsayilan'])) { $varsayilanCari = $c; break; }
}

// Son satış fiş verisi
$sonSatis = null;
if (isset($_GET['msg']) && $_GET['msg'] === 'ok' && !empty($_SESSION['son_satis'])) {
    $sonSatis = $_SESSION['son_satis'];
    // Cari adını ekle
    $cRow = $pdo->prepare("SELECT ad FROM cariler WHERE id=?");
    $cRow->execute([$sonSatis['cari_id']]);
    $cRow = $cRow->fetch();
    $sonSatis['cari_ad'] = $cRow ? $cRow['ad'] : '';
    unset($_SESSION['son_satis']);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Hızlı Satış — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
.tip-radio-lbl { display:flex;align-items:center;gap:.45rem;padding:.5rem .75rem;border:1.5px solid var(--border);border-radius:var(--r);cursor:pointer;font-size:.84rem;font-weight:600;transition:.12s;color:var(--ink2); }
.tip-radio-lbl input { display:none; }
.tip-radio-lbl.active-alacak { border-color:var(--green);background:var(--green-lt);color:var(--green); }
.tip-radio-lbl.active-borc   { border-color:var(--red);background:var(--red-lt);color:var(--red); }
.stok-ara-item:hover { background:var(--surface2); }
.stok-ara-item:last-child { border-bottom:none; }
.hs-layout { display:grid;grid-template-columns:1fr 320px;gap:1.25rem;align-items:start; }
.hs-sepet-row { display:flex;align-items:center;gap:.4rem;padding:.45rem .6rem;background:var(--surface2);border-radius:6px;font-size:.83rem;margin-bottom:.3rem; }
.hs-sepet-row .ad { flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap; }
.hs-sepet-row .tutar { font-weight:700;color:var(--accent);white-space:nowrap;margin-right:.25rem; }
.hs-sepet-row .sil-btn { background:none;border:none;color:var(--red);cursor:pointer;font-size:1rem;font-weight:bold;padding:0 .2rem;line-height:1; }
.hs-toplam { font-size:1.7rem;font-weight:800;color:var(--accent);text-align:center;margin:.5rem 0; }
.cari-ara-item { padding:.45rem .75rem;cursor:pointer;font-size:.84rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center; }
.cari-ara-item:hover { background:var(--surface2); }
.cari-ara-item:last-child { border-bottom:none; }
@media (max-width:700px) { .hs-layout { grid-template-columns:1fr; } }
</style>
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <h1 class="page-title">Hızlı Satış</h1>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:1rem"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="hs-layout">

      <!-- SOL: Ürün Arama + Sepet -->
      <div>
        <div class="panel" style="margin-bottom:1rem;overflow:visible">
          <div class="panel-head"><div class="panel-title">Ürün Ekle</div></div>
          <div style="padding:1rem">
            <!-- Barkod / ad arama -->
            <div style="position:relative;margin-bottom:.75rem">
              <input type="text" id="urun_ara" class="form-input" placeholder="Ürün adı veya barkod yazın / okutun..."
                autocomplete="off"
                oninput="urunAra(this.value)"
                onfocus="urunAra(this.value)">
              <div id="urun_liste" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:300;background:var(--surface);border:1px solid var(--border2);border-radius:var(--r);box-shadow:var(--shadow);max-height:220px;overflow-y:auto;margin-top:2px"></div>
            </div>

            <!-- Seçilen ürün bilgisi + miktar -->
            <div id="urun_bilgi" style="display:none;background:var(--surface2);border-radius:var(--r);padding:.65rem .85rem;margin-bottom:.75rem">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.45rem">
                <div style="font-weight:700;font-size:.95rem" id="ub_ad">—</div>
                <button type="button" onclick="urunSifirla()" style="background:none;border:none;color:var(--ink3);cursor:pointer;font-size:.85rem;padding:0">✕</button>
              </div>
              <div style="display:flex;gap:1rem;font-size:.8rem;color:var(--ink2);margin-bottom:.55rem;flex-wrap:wrap">
                <span>Stok: <strong id="ub_stok" style="color:var(--green)">—</strong></span>
                <span>Nakit: <strong id="ub_nakit">—</strong></span>
                <span>Vadeli: <strong id="ub_vadeli">—</strong></span>
              </div>
              <div style="display:grid;grid-template-columns:80px 1fr 1fr;gap:.4rem;align-items:end">
                <div>
                  <label class="form-label" style="font-size:.72rem">Miktar</label>
                  <input type="number" id="ub_miktar" class="form-input" value="1" min="0.01" step="0.01" style="font-size:.85rem" oninput="ubFiyatHesapla()">
                </div>
                <div>
                  <label class="form-label" style="font-size:.72rem">Birim Fiyat (₺)</label>
                  <input type="number" id="ub_birim_fiyat" class="form-input" step="0.01" min="0" placeholder="0,00" style="font-size:.85rem" oninput="ubToplamHesapla()">
                </div>
                <div>
                  <label class="form-label" style="font-size:.72rem">Toplam (₺)</label>
                  <input type="number" id="ub_toplam" class="form-input" step="0.01" min="0.01" placeholder="0,00" style="font-size:.85rem;font-weight:700;color:var(--accent)" oninput="ubBirimHesapla()">
                </div>
              </div>
              <input type="hidden" id="ub_stok_id">
              <input type="hidden" id="ub_birim">
            </div>

            <!-- Açıklama (stoksuz kalem için) -->
            <div id="aciklama_wrap" style="margin-bottom:.75rem;display:none">
              <label class="form-label" style="font-size:.72rem">Açıklama / Ürün Adı</label>
              <input type="text" id="ub_aciklama" class="form-input" placeholder="Ürün adı veya not...">
            </div>

            <button type="button" class="btn btn-secondary" style="width:100%;justify-content:center" onclick="hsKalemEkle()">
              <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Sepete Ekle
            </button>
          </div>
        </div>

        <!-- Sepet -->
        <div class="panel">
          <div class="panel-head">
            <div class="panel-title">Sepet</div>
            <span id="hs_kalem_sayisi" style="font-size:.8rem;color:var(--ink3)">0 kalem</span>
          </div>
          <div style="padding:.75rem 1rem">
            <div style="display:grid;grid-template-columns:1fr 70px 90px 28px;gap:.35rem;padding:0 .55rem;margin-bottom:.25rem">
              <span style="font-size:.7rem;font-weight:700;color:var(--ink3);text-transform:uppercase">Ürün</span>
              <span style="font-size:.7rem;font-weight:700;color:var(--ink3);text-transform:uppercase;text-align:center">Miktar</span>
              <span style="font-size:.7rem;font-weight:700;color:var(--ink3);text-transform:uppercase;text-align:right">Tutar (₺)</span>
              <span></span>
            </div>
            <div id="hs_sepet_listesi">
              <div style="text-align:center;color:var(--ink3);font-size:.85rem;padding:1.5rem 0">Henüz ürün eklenmedi</div>
            </div>
            <div class="hs-toplam" id="hs_toplam_goster">₺0,00</div>
          </div>
        </div>
      </div>

      <!-- SAĞ: Cari + Ödeme + Kaydet -->
      <div>
        <div class="panel" style="margin-bottom:1rem;overflow:visible">
          <div class="panel-head"><div class="panel-title">Cari Seçimi</div></div>
          <div style="padding:1rem">
            <div style="position:relative;margin-bottom:.5rem">
              <input type="text" id="cari_ara" class="form-input" placeholder="Cari adı yazın..." autocomplete="off"
                oninput="cariAra(this.value)" onfocus="cariAra(this.value)">
              <div id="cari_liste" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:300;background:var(--surface);border:1px solid var(--border2);border-radius:var(--r);box-shadow:var(--shadow);max-height:200px;overflow-y:auto;margin-top:2px"></div>
              <input type="hidden" id="secilen_cari_id">
            </div>
            <div id="secilen_cari_bilgi" style="display:none;font-size:.83rem;color:var(--ink2);padding:.35rem .5rem;background:var(--surface2);border-radius:5px;margin-bottom:.5rem">
              <strong id="secilen_cari_ad"></strong>
            </div>
            <button type="button" onclick="yeniCariModal()" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;font-size:.8rem">
              + Yeni Cari Ekle
            </button>
          </div>
        </div>

        <div class="panel" style="margin-bottom:1rem">
          <div class="panel-head"><div class="panel-title">Ödeme & Tarih</div></div>
          <div style="padding:1rem">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.75rem">
              <label class="tip-radio-lbl active-alacak" id="lbl_alacak">
                <input type="radio" name="tip_sec" value="alacak" checked onchange="tipDegHS(this)"> <span>↑ Nakit</span>
              </label>
              <label class="tip-radio-lbl" id="lbl_borc">
                <input type="radio" name="tip_sec" value="borc" onchange="tipDegHS(this)"> <span>↓ Vadeli</span>
              </label>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
              <div class="form-group" style="margin-bottom:0">
                <label class="form-label">Tarih</label>
                <input type="date" id="satis_tarih" class="form-input" value="<?= date('Y-m-d') ?>">
              </div>
              <div class="form-group" id="vade_wrap" style="margin-bottom:0;display:none">
                <label class="form-label">Vade</label>
                <input type="date" id="satis_vade" class="form-input">
              </div>
            </div>
          </div>
        </div>

        <div class="panel" style="margin-bottom:1rem">
          <div style="padding:1.1rem">
            <div style="text-align:center;margin-bottom:.75rem">
              <div style="font-size:.75rem;color:var(--ink3);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem">Toplam Tutar</div>
              <div class="hs-toplam" id="hs_toplam_sag">₺0,00</div>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;border-top:1px solid var(--border);padding-top:.65rem">
              <label style="font-size:.78rem;color:var(--ink2);font-weight:600;white-space:nowrap">İndirim (₺)</label>
              <input type="number" id="hs_indirim" class="form-input" value="0" min="0" step="0.01"
                placeholder="0,00" style="font-size:.9rem;text-align:right;flex:1"
                oninput="hsToplam()">
            </div>
            <div id="hs_net_wrap" style="display:none;margin-top:.5rem;border-top:1px solid var(--border);padding-top:.5rem;text-align:center">
              <div style="font-size:.72rem;color:var(--ink3);font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.15rem">Net Ödenecek</div>
              <div style="font-size:1.5rem;font-weight:900;color:var(--green)" id="hs_net_tutar">₺0,00</div>
            </div>
          </div>
        </div>

        <form method="post" action="/hizli-satis" id="satisForm">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="action" value="satis-kaydet">
          <input type="hidden" name="cari_id" id="form_cari_id">
          <input type="hidden" name="tip" id="form_tip" value="alacak">
          <input type="hidden" name="tarih" id="form_tarih">
          <input type="hidden" name="vade_tarihi" id="form_vade">
          <input type="hidden" name="indirim" id="form_indirim" value="0">
          <div id="form_kalemler"></div>
          <?php if (!empty($kasaHesaplari)): ?>
          <div class="form-group" id="kasaSecRow" style="margin-bottom:.75rem">
            <label class="form-label" style="font-size:.78rem">Kasa Hesabı <span style="color:var(--ink3);font-weight:400">(nakit satışta)</span></label>
            <select name="kasa_hesap_id" id="form_kasa_hesap" class="form-input" style="font-size:.84rem">
              <option value="">— Hesap seç —</option>
              <?php foreach ($kasaHesaplari as $kh): ?>
              <option value="<?= $kh['id'] ?>" <?= $kh['id']===$varsayilanKasaId ? 'selected' : '' ?>><?= htmlspecialchars($kh['ad']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <button type="button" onclick="hsSatisKaydet()" class="btn btn-primary" style="width:100%;justify-content:center;font-size:1rem;padding:.7rem">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            Satışı Kaydet
          </button>
          <button type="button" onclick="hsFisYazdir()" id="fis_btn" style="display:none;margin-top:.5rem" class="btn btn-ghost" style="width:100%;justify-content:center">
            🖨 Fiş Yazdır
          </button>
        </form>
      </div>

    </div>
  </main>
</div>

<!-- Yeni Cari Modal -->
<div class="vrm-overlay" id="yeniCariModal" onclick="if(event.target===this)this.classList.remove('open')">
  <div class="vrm-dialog" style="max-width:360px">
    <div style="padding:1rem 1.5rem .75rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between">
      <div style="font-weight:800;font-size:.95rem">Yeni Cari Ekle</div>
      <button class="btn-icon" onclick="document.getElementById('yeniCariModal').classList.remove('open')">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>
    <div style="padding:1.25rem 1.5rem">
      <div class="form-group">
        <label class="form-label">Cari Adı *</label>
        <input type="text" id="yc_ad" class="form-input" placeholder="Ad Soyad / Firma">
      </div>
      <div class="form-group">
        <label class="form-label">Telefon <span class="text-muted">(opt.)</span></label>
        <input type="text" id="yc_tel" class="form-input" placeholder="05xx xxx xx xx">
      </div>
      <button type="button" onclick="yeniCariKaydet()" class="btn btn-primary" style="width:100%;justify-content:center">Kaydet</button>
    </div>
  </div>
</div>

<script>
var _firmaAdi = <?= json_encode($firmaAdi, JSON_UNESCAPED_UNICODE) ?>;
var _stoklar  = <?= json_encode(array_values($stoklar), JSON_UNESCAPED_UNICODE) ?>;
var _hsSayac  = 0;
// Sepet: uid → {stok_id, aciklama, miktar, bf, tutar} — DOM'la senkron
var _sepet = {};

// ── ÜRÜN ARAMA ─────────────────────────────────────────────
function urunAra(q) {
  var liste = document.getElementById('urun_liste');
  var araStr = q.trim().toLowerCase();
  var eslesen = araStr === ''
    ? _stoklar.slice(0, 10)
    : _stoklar.filter(function(s) {
        return s.ad.toLowerCase().indexOf(araStr) !== -1
            || (s.barkod && s.barkod.toLowerCase().indexOf(araStr) !== -1);
      });

  liste.innerHTML = '';
  if (eslesen.length === 0) { liste.style.display = 'none'; return; }

  eslesen.forEach(function(s) {
    var miktar = parseFloat(s.miktar) || 0;
    var mRenk  = miktar <= 0 ? 'var(--red)' : (miktar <= 5 ? 'var(--gold)' : 'var(--green)');
    var item = document.createElement('div');
    item.className = 'stok-ara-item';
    item.style.cssText = 'padding:.45rem .75rem;cursor:pointer;font-size:.83rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center';
    item.innerHTML = '<span>' + s.ad + (s.barkod ? ' <span style="color:var(--ink3);font-size:.72rem">' + s.barkod + '</span>' : '') + '</span>'
      + '<span style="font-size:.72rem;color:' + mRenk + ';font-weight:600">' + miktar.toLocaleString('tr-TR') + ' ' + s.birim + '</span>';
    item.addEventListener('mousedown', function(e) {
      e.preventDefault();
      urunSec(s);
    });
    liste.appendChild(item);
  });
  liste.style.display = 'block';
}

function urunSec(s) {
  document.getElementById('urun_ara').value     = s.ad;
  document.getElementById('urun_liste').style.display = 'none';
  document.getElementById('ub_stok_id').value   = s.id;
  document.getElementById('ub_birim').value     = s.birim || 'adet';
  document.getElementById('ub_ad').textContent  = s.ad;
  document.getElementById('aciklama_wrap').style.display = 'none';

  var mevcut = parseFloat(s.miktar) || 0;
  var mRenk  = mevcut <= 0 ? 'var(--red)' : (mevcut <= 5 ? 'var(--gold)' : 'var(--green)');
  document.getElementById('ub_stok').textContent  = mevcut.toLocaleString('tr-TR') + ' ' + (s.birim || 'adet');
  document.getElementById('ub_stok').style.color  = mRenk;
  document.getElementById('ub_nakit').textContent  = parseFloat(s.nakit_fiyat || 0).toFixed(2).replace('.',',') + ' ₺';
  document.getElementById('ub_vadeli').textContent = parseFloat(s.vadeli_fiyat || 0).toFixed(2).replace('.',',') + ' ₺';

  // Otomatik fiyat
  var tip   = hsTipSec();
  var fiyat = parseFloat(tip === 'alacak' ? s.nakit_fiyat : s.vadeli_fiyat) || 0;
  document.getElementById('ub_birim_fiyat').value = fiyat > 0 ? fiyat.toFixed(2) : '';
  var mik = parseFloat(document.getElementById('ub_miktar').value) || 1;
  document.getElementById('ub_toplam').value = fiyat > 0 ? (fiyat * mik).toFixed(2) : '';

  document.getElementById('urun_bilgi').style.display = 'block';
  // Barkod senaryosunda focus urun_ara'da kalır (otomatik sepet için); manuel seçimde miktar'a git
  if (!_barkodModu) document.getElementById('ub_miktar').focus();
}

function urunSifirla() {
  document.getElementById('ub_stok_id').value  = '';
  document.getElementById('ub_birim').value    = 'adet';
  document.getElementById('urun_ara').value    = '';
  document.getElementById('ub_miktar').value   = '1';
  document.getElementById('ub_birim_fiyat').value = '';
  document.getElementById('ub_toplam').value   = '';
  document.getElementById('ub_aciklama').value = '';
  document.getElementById('urun_bilgi').style.display = 'none';
  document.getElementById('aciklama_wrap').style.display = 'none';
  document.getElementById('urun_ara').focus();
}

function ubFiyatHesapla() {
  var bf = parseFloat(document.getElementById('ub_birim_fiyat').value) || 0;
  var mk = parseFloat(document.getElementById('ub_miktar').value) || 1;
  if (bf > 0) document.getElementById('ub_toplam').value = (bf * mk).toFixed(2);
}
function ubToplamHesapla() {
  var bf = parseFloat(document.getElementById('ub_birim_fiyat').value) || 0;
  var mk = parseFloat(document.getElementById('ub_miktar').value) || 1;
  if (bf > 0) document.getElementById('ub_toplam').value = (bf * mk).toFixed(2);
}
function ubBirimHesapla() {
  var tp = parseFloat(document.getElementById('ub_toplam').value) || 0;
  var mk = parseFloat(document.getElementById('ub_miktar').value) || 1;
  if (tp > 0 && mk > 0) document.getElementById('ub_birim_fiyat').value = (tp / mk).toFixed(2);
}

// Barkod okuyucu: hızlı yazma → ara + otomatik seç
// Barkod cihazları karakterleri çok hızlı gönderir (<50ms arayla) ve Enter ile bitirir.
// Bu yüzden: her keydown'da zamanlayıcıyı sıfırla, 80ms içinde yeni tuş gelmezse barkod tamamlandı say.
var _barkodTimer = null;
var _barkodModu = false; // barkod okunuyor mu (otomatik sepete ekle, miktar focus'a gitme)
var _urunAraEl = document.getElementById('urun_ara');

_urunAraEl.addEventListener('keydown', function(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    clearTimeout(_barkodTimer);
    _barkodTimer = null;
    _barkodOkuTamamlandi();
    return;
  }
  // Her tuşta zamanlayıcıyı yenile — barkod cihazı çok hızlı yazar, manuel yazma yavaştır
  _barkodModu = true;
  clearTimeout(_barkodTimer);
  _barkodTimer = setTimeout(function() { _barkodTimer = null; _barkodModu = false; }, 500);
});

function _barkodOkuTamamlandi() {
  _barkodModu = false;
  var liste = document.getElementById('urun_liste');
  if (liste.style.display !== 'none') {
    var ilk = liste.querySelector('.stok-ara-item');
    if (ilk) {
      ilk.dispatchEvent(new MouseEvent('mousedown', {bubbles:true}));
      // Barkod okundu ve ürün seçildi: otomatik sepete ekle (fiyat varsa)
      setTimeout(function() {
        var tutar = parseFloat(document.getElementById('ub_toplam').value) || 0;
        if (tutar > 0) hsKalemEkle();
      }, 30);
    }
  } else {
    // Stok bulunamadı — açıklama moduna geç
    var q = document.getElementById('urun_ara').value.trim();
    if (q) {
      document.getElementById('urun_bilgi').style.display = 'none';
      document.getElementById('aciklama_wrap').style.display = 'block';
      document.getElementById('ub_aciklama').value = q;
      document.getElementById('ub_stok_id').value  = '';
      document.getElementById('ub_toplam').value   = '';
      document.getElementById('ub_birim_fiyat').value = '';
      document.getElementById('ub_toplam').focus();
    }
  }
}

document.addEventListener('click', function(e) {
  var ara   = document.getElementById('urun_ara');
  var liste = document.getElementById('urun_liste');
  if (ara && liste && !ara.contains(e.target) && !liste.contains(e.target)) liste.style.display = 'none';
  var ca = document.getElementById('cari_ara');
  var cl = document.getElementById('cari_liste');
  if (ca && cl && !ca.contains(e.target) && !cl.contains(e.target)) cl.style.display = 'none';
});

// ── SEPETE EKLE ────────────────────────────────────────────
function hsKalemEkle() {
  var tutar  = parseFloat(document.getElementById('ub_toplam').value) || 0;
  if (tutar <= 0) { vrmToast('Toplam tutar girilmeli.', 'warn'); return; }
  var stokId = document.getElementById('ub_stok_id').value;
  if (stokId) {
    var stok = _stoklar.find(function(s){ return String(s.id) === String(stokId); });
    if (stok && parseFloat(stok.miktar) <= 0) {
      vrmToast('"' + stok.ad + '" stok miktarı 0, eklenemiyor.', 'error');
      return;
    }
  }
  var aciklama = document.getElementById('ub_aciklama').value.trim();
  var etiket = stokId
    ? document.getElementById('ub_ad').textContent.trim()
    : (aciklama || document.getElementById('urun_ara').value.trim());
  if (!etiket) { vrmToast('Ürün adı veya stok seçin.', 'warn'); return; }

  var uid    = ++_hsSayac;
  var miktar = parseFloat(document.getElementById('ub_miktar').value) || 1;
  var bf     = parseFloat(document.getElementById('ub_birim_fiyat').value) || 0;
  var birim  = document.getElementById('ub_birim').value || 'adet';

  _sepet[uid] = { stok_id: stokId, aciklama: stokId ? etiket : aciklama, miktar: miktar, bf: bf, tutar: tutar, etiket: etiket, birim: birim };
  hsSepetRender();
  urunSifirla();
}

function hsSepetSil(uid) {
  delete _sepet[uid];
  hsSepetRender();
}

function hsSepetMiktarDegis(uid, yeniMiktar) {
  var k = _sepet[uid];
  if (!k) return;
  var mik = parseFloat(yeniMiktar) || 1;
  k.miktar = mik;
  if (k.bf > 0) k.tutar = k.bf * mik;
  // tutar alanını güncelle
  var tutarEl = document.querySelector('.hs-sepet-row[data-uid="' + uid + '"] .hs-tutar-inp');
  if (tutarEl) tutarEl.value = k.tutar.toFixed(2);
  hsToplam();
}

function hsSepetTutarDegis(uid, yeniTutar) {
  var k = _sepet[uid];
  if (!k) return;
  k.tutar = parseFloat(yeniTutar) || 0;
  hsToplam();
}

function hsSepetRender() {
  var listesi = document.getElementById('hs_sepet_listesi');
  listesi.innerHTML = '';
  var uidler = Object.keys(_sepet);
  if (uidler.length === 0) {
    listesi.innerHTML = '<div style="text-align:center;color:var(--ink3);font-size:.85rem;padding:1.5rem 0">Henüz ürün eklenmedi</div>';
    hsToplam();
    return;
  }
  uidler.forEach(function(uid) {
    var k = _sepet[uid];
    var row = document.createElement('div');
    row.className = 'hs-sepet-row';
    row.dataset.uid = uid;
    row.style.cssText = 'display:grid;grid-template-columns:1fr 70px 90px 28px;gap:.35rem;align-items:center;padding:.4rem .55rem;background:var(--surface2);border-radius:6px;margin-bottom:.3rem';

    // Ad
    var adEl = document.createElement('span');
    adEl.style.cssText = 'font-size:.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap';
    adEl.title = k.etiket;
    adEl.textContent = k.etiket;
    row.appendChild(adEl);

    // Miktar input
    var mikEl = document.createElement('input');
    mikEl.type = 'number'; mikEl.step = '0.01'; mikEl.min = '0.01';
    mikEl.value = k.miktar;
    mikEl.className = 'form-input';
    mikEl.style.cssText = 'font-size:.8rem;padding:.25rem .35rem;text-align:center';
    mikEl.title = 'Miktar (' + k.birim + ')';
    mikEl.addEventListener('input', function() { hsSepetMiktarDegis(uid, this.value); });
    row.appendChild(mikEl);

    // Tutar input
    var tutarEl = document.createElement('input');
    tutarEl.type = 'number'; tutarEl.step = '0.01'; tutarEl.min = '0';
    tutarEl.value = k.tutar.toFixed(2);
    tutarEl.className = 'form-input hs-tutar-inp';
    tutarEl.style.cssText = 'font-size:.8rem;padding:.25rem .35rem;font-weight:700;color:var(--accent);text-align:right';
    tutarEl.addEventListener('input', function() { hsSepetTutarDegis(uid, this.value); });
    row.appendChild(tutarEl);

    // Sil
    var silBtn = document.createElement('button');
    silBtn.type = 'button'; silBtn.textContent = '×';
    silBtn.style.cssText = 'background:none;border:none;color:var(--red);cursor:pointer;font-size:1.1rem;font-weight:bold;padding:0;line-height:1';
    silBtn.addEventListener('click', function() { hsSepetSil(uid); });
    row.appendChild(silBtn);

    listesi.appendChild(row);
  });
  hsToplam();
}

function hsToplam() {
  var toplam = 0;
  Object.keys(_sepet).forEach(function(uid) { toplam += _sepet[uid].tutar || 0; });
  var indirim = Math.max(0, parseFloat(document.getElementById('hs_indirim').value) || 0);
  var net = Math.max(0, toplam - indirim);
  var fmt = '₺' + toplam.toFixed(2).replace('.', ',');
  document.getElementById('hs_toplam_goster').textContent = fmt;
  document.getElementById('hs_toplam_sag').textContent    = fmt;
  document.getElementById('hs_kalem_sayisi').textContent  = Object.keys(_sepet).length + ' kalem';
  var netWrap = document.getElementById('hs_net_wrap');
  if (indirim > 0 && toplam > 0) {
    document.getElementById('hs_net_tutar').textContent = '₺' + net.toFixed(2).replace('.', ',');
    netWrap.style.display = 'block';
  } else {
    netWrap.style.display = 'none';
  }
}

function hsGuncelle() { hsToplam(); }

// ── CARİ ARAMA ─────────────────────────────────────────────
var _cariler = <?= json_encode(array_values($carilerJson), JSON_UNESCAPED_UNICODE) ?>;
var _varsayilanCari = <?= json_encode($varsayilanCari, JSON_UNESCAPED_UNICODE) ?>;
if (_varsayilanCari) {
  cariSec(_varsayilanCari.id, _varsayilanCari.ad);
}

function cariAra(q) {
  var liste  = document.getElementById('cari_liste');
  var araStr = q.trim().toLowerCase();
  if (!araStr) { liste.style.display = 'none'; return; }
  var eslesen = _cariler.filter(function(c) {
    return (c.ad && c.ad.toLowerCase().indexOf(araStr) !== -1)
        || (c.telefon && c.telefon.indexOf(araStr) !== -1);
  }).slice(0, 10);
  liste.innerHTML = '';
  if (eslesen.length === 0) {
    liste.innerHTML = '<div style="padding:.6rem .75rem;font-size:.82rem;color:var(--ink3)">Bulunamadı — <a href="#" onclick="yeniCariModal();return false" style="color:var(--accent)">Yeni ekle</a></div>';
    liste.style.display = 'block';
    return;
  }
  eslesen.forEach(function(c) {
    var item = document.createElement('div');
    item.className = 'cari-ara-item';
    item.innerHTML = '<span>' + c.ad + '</span>' + (c.telefon ? '<span style="font-size:.75rem;color:var(--ink3)">' + c.telefon + '</span>' : '');
    item.addEventListener('mousedown', function(e) {
      e.preventDefault();
      cariSec(c.id, c.ad);
    });
    liste.appendChild(item);
  });
  liste.style.display = 'block';
}

function cariSec(id, ad) {
  document.getElementById('secilen_cari_id').value = id;
  document.getElementById('cari_ara').value        = ad;
  document.getElementById('cari_liste').style.display = 'none';
  document.getElementById('secilen_cari_ad').textContent = ad;
  document.getElementById('secilen_cari_bilgi').style.display = 'block';
  _secilenCariAd = ad;
}

function yeniCariModal() {
  document.getElementById('yc_ad').value  = '';
  document.getElementById('yc_tel').value = '';
  document.getElementById('yeniCariModal').classList.add('open');
  setTimeout(function(){ document.getElementById('yc_ad').focus(); }, 100);
}

function yeniCariKaydet() {
  var ad  = document.getElementById('yc_ad').value.trim();
  var tel = document.getElementById('yc_tel').value.trim();
  if (!ad) { vrmToast('Cari adı zorunlu.', 'warn'); return; }
  var fd = new FormData();
  fd.append('action', 'yeni-cari');
  fd.append('csrf', document.querySelector('input[name="csrf"]').value);
  fd.append('ad', ad);
  fd.append('telefon', tel);
  fetch('/hizli-satis', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d) {
      if (d.error) { vrmToast(d.error, 'error'); return; }
      _cariler.push({ id: d.id, ad: d.ad, telefon: tel });
      cariSec(d.id, d.ad);
      document.getElementById('yeniCariModal').classList.remove('open');
    });
}

// ── ÖDEME TİPİ ─────────────────────────────────────────────
function hsTipSec() {
  var r = document.querySelector('input[name="tip_sec"]:checked');
  return r ? r.value : 'alacak';
}

function tipDegHS(r) {
  document.getElementById('lbl_alacak').className = 'tip-radio-lbl' + (r.value==='alacak' ? ' active-alacak' : '');
  document.getElementById('lbl_borc').className   = 'tip-radio-lbl' + (r.value==='borc'   ? ' active-borc'   : '');
  document.getElementById('vade_wrap').style.display = r.value === 'borc' ? 'block' : 'none';
  // Seçili stok varsa fiyatı güncelle
  var stokId = document.getElementById('ub_stok_id').value;
  if (stokId) {
    var s = _stoklar.find(function(x){ return String(x.id) === String(stokId); });
    if (s) {
      var fiyat = parseFloat(r.value === 'alacak' ? s.nakit_fiyat : s.vadeli_fiyat) || 0;
      var mik   = parseFloat(document.getElementById('ub_miktar').value) || 1;
      document.getElementById('ub_birim_fiyat').value = fiyat > 0 ? fiyat.toFixed(2) : '';
      document.getElementById('ub_toplam').value      = fiyat > 0 ? (fiyat * mik).toFixed(2) : '';
    }
  }
}

// ── KAYDET ────────────────────────────────────────────────
function hsSatisKaydet() {
  var cariId = document.getElementById('secilen_cari_id').value;
  if (!cariId) { vrmToast('Cari seçin.', 'warn'); document.getElementById('cari_ara').focus(); return; }
  if (Object.keys(_sepet).length === 0) { vrmToast('Sepete ürün ekleyin.', 'warn'); return; }

  // Satır input'larından güncel miktar/tutar oku
  document.querySelectorAll('#hs_sepet_listesi .hs-sepet-row').forEach(function(row) {
    var uid = row.dataset.uid;
    if (!_sepet[uid]) return;
    var inps = row.querySelectorAll('input');
    _sepet[uid].miktar = parseFloat(inps[0].value) || _sepet[uid].miktar;
    _sepet[uid].tutar  = parseFloat(inps[1].value) || _sepet[uid].tutar;
  });

  document.getElementById('form_cari_id').value  = cariId;
  document.getElementById('form_tip').value      = hsTipSec();
  document.getElementById('form_tarih').value    = document.getElementById('satis_tarih').value;
  document.getElementById('form_vade').value     = document.getElementById('satis_vade').value;
  document.getElementById('form_indirim').value  = Math.max(0, parseFloat(document.getElementById('hs_indirim').value) || 0);

  // Hidden kalemler oluştur
  var fk = document.getElementById('form_kalemler');
  fk.innerHTML = '';
  Object.keys(_sepet).forEach(function(uid) {
    var k = _sepet[uid];
    function _inp(f, v) {
      var i = document.createElement('input');
      i.type = 'hidden'; i.name = 'kalemler[' + uid + '][' + f + ']'; i.value = v;
      fk.appendChild(i);
    }
    _inp('stok_id',     k.stok_id);
    _inp('aciklama',    k.aciklama || k.etiket);
    _inp('miktar',      k.miktar);
    _inp('birim_fiyat', k.bf);
    _inp('tutar',       k.tutar);
  });

  document.getElementById('satisForm').submit();
}

// ── FİŞ YAZDIR ─────────────────────────────────────────────
function hsFisYazdir() {
  var d = window._sonSatis;
  if (!d) return;
  veresiyemFisYazdir(d);
}

// ── SAYFA YÜKLENDİĞİNDE ──────────────────────────────────
<?php if ($sonSatis): ?>
window._sonSatis = <?= json_encode($sonSatis, JSON_UNESCAPED_UNICODE) ?>;
// Başarı mesajı
(function() {
  var banner = document.createElement('div');
  banner.className = 'alert alert-success';
  banner.style.cssText = 'margin-bottom:1rem';
  var tip = window._sonSatis.tip === 'alacak' ? 'Nakit Satış' : 'Vadeli Satış';
  var indirimBilgi = (window._sonSatis.indirim > 0)
    ? ' <span style="color:var(--green)">(₺' + parseFloat(window._sonSatis.indirim).toFixed(2).replace('.',',') + ' indirim uygulandı)</span>' : '';
  banner.innerHTML = '&#10003; <strong>' + tip + '</strong> kaydedildi. Tutar: <strong>₺' + parseFloat(window._sonSatis.toplam).toFixed(2).replace('.',',') + '</strong>'
    + indirimBilgi + ' — <a href="/cari?id=<?= $sonSatis['cari_id'] ?>">Cariye Git</a>';
  var main = document.querySelector('.main-content');
  var ph   = main.querySelector('.page-header');
  main.insertBefore(banner, ph ? ph.nextSibling : main.firstChild);
  // Fiş butonunu göster
  document.getElementById('fis_btn').style.display = 'block';
  // Otomatik fiş
  hsFisYazdir();
})();
<?php endif; ?>

// Enter ile sepete ekle (ub_toplam, ub_miktar, ub_birim_fiyat)
['ub_toplam','ub_miktar','ub_birim_fiyat'].forEach(function(id) {
  var el = document.getElementById(id);
  if (el) el.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') { e.preventDefault(); hsKalemEkle(); }
  });
});
</script>
</body>
</html>
