<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'fatura-ekle';
$uid = userId();

// Tedarikçileri yükle
$tList = $pdo->prepare("SELECT id, ad FROM tedarikciler WHERE user_id = ? ORDER BY ad ASC");
$tList->execute([$uid]);
$tedarikciler = $tList->fetchAll();

// URL'den gelen tedarikçi
$preTedarikciId = (int)($_GET['tedarikci_id'] ?? 0);

$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tedarikci_id  = (int)($_POST['tedarikci_id'] ?? 0);
    $fatura_no     = trim($_POST['fatura_no'] ?? '');
    $tarih         = trim($_POST['tarih'] ?? date('Y-m-d'));
    $vade_tarihi   = trim($_POST['vade_tarihi'] ?? '');
    $aciklama      = trim($_POST['aciklama'] ?? '');
    $durum         = $_POST['durum'] ?? 'odenmedi';

    // Satırlar
    $urun_adlari   = $_POST['urun_adi'] ?? [];
    $miktarlar     = $_POST['miktar'] ?? [];
    $birimler      = $_POST['birim'] ?? [];
    $birim_fiyatlar = $_POST['birim_fiyat'] ?? [];
    $kdv_oranlar   = $_POST['kdv_oran'] ?? [];

    // Tedarikçi kontrolü
    $tKontrol = $pdo->prepare("SELECT id FROM tedarikciler WHERE id = ? AND user_id = ?");
    $tKontrol->execute([$tedarikci_id, $uid]);
    if (!$tKontrol->fetch()) {
        $hata = 'Geçersiz tedarikçi.';
    } elseif (empty($urun_adlari) || !array_filter($urun_adlari)) {
        $hata = 'En az bir ürün satırı eklemelisiniz.';
    } else {
        // Toplam hesapla
        $toplam_tutar = 0;
        $kdv_toplam   = 0;
        $satirlar = [];
        for ($i = 0; $i < count($urun_adlari); $i++) {
            $ad = trim($urun_adlari[$i] ?? '');
            if ($ad === '') continue;
            $miktar     = (float)($miktarlar[$i] ?? 1);
            $birim      = trim($birimler[$i] ?? 'adet');
            $birim_fiy  = (float)($birim_fiyatlar[$i] ?? 0);
            $kdv        = (float)($kdv_oranlar[$i] ?? 0);
            $satir_top  = round($miktar * $birim_fiy, 2);
            $satir_kdv  = round($satir_top * $kdv / 100, 2);
            $toplam_tutar += $satir_top;
            $kdv_toplam   += $satir_kdv;
            $satirlar[] = [$ad, $miktar, $birim, $birim_fiy, $kdv, $satir_top + $satir_kdv];
        }
        $genel_toplam = round($toplam_tutar + $kdv_toplam, 2);

        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO faturalar (user_id, tedarikci_id, fatura_no, tarih, vade_tarihi, toplam_tutar, kdv_toplam, genel_toplam, durum, aciklama) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $ins->execute([$uid, $tedarikci_id, $fatura_no, $tarih, $vade_tarihi ?: null, $toplam_tutar, $kdv_toplam, $genel_toplam, $durum, $aciklama ?: null]);
        $fatura_id = (int)$pdo->lastInsertId();

        $insS = $pdo->prepare("INSERT INTO fatura_satirlar (fatura_id, urun_adi, miktar, birim, birim_fiyat, kdv_oran, toplam) VALUES (?,?,?,?,?,?,?)");
        foreach ($satirlar as $s) {
            $insS->execute(array_merge([$fatura_id], $s));
        }
        $pdo->commit();

        header('Location: /fatura?id=' . $fatura_id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yeni Fatura — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">
    <div class="page-header">
      <div class="page-header-left">
        <a href="/faturalar" class="btn btn-ghost btn-sm" style="margin-right:.5rem">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <div class="page-title">Yeni Fatura</div>
      </div>
    </div>

    <div class="page-body" style="max-width:720px">
      <?php if ($hata): ?>
      <div class="alert alert-danger" style="margin-bottom:1rem"><?= htmlspecialchars($hata) ?></div>
      <?php endif; ?>

      <?php if (empty($tedarikciler)): ?>
      <div class="empty-state">
        <p>Fatura eklemek için önce bir tedarikçi oluşturmanız gerekiyor.</p>
        <a href="/tedarikci-ekle" class="btn btn-primary">Tedarikçi Ekle</a>
      </div>
      <?php else: ?>
      <form method="post" action="/fatura-ekle" id="faturaForm">
        <!-- Üst Bilgiler -->
        <div class="panel" style="padding:1.25rem;margin-bottom:1rem">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
            <div class="form-group" style="grid-column:1/-1">
              <label class="form-label">Tedarikçi <span style="color:var(--red)">*</span></label>
              <select name="tedarikci_id" class="form-input" required>
                <option value="">Tedarikçi seçin...</option>
                <?php foreach ($tedarikciler as $t): ?>
                <option value="<?= $t['id'] ?>" <?= ((int)($_POST['tedarikci_id'] ?? $preTedarikciId) === (int)$t['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($t['ad']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Fatura No</label>
              <input type="text" name="fatura_no" class="form-input" placeholder="FTR-2025-001" value="<?= htmlspecialchars($_POST['fatura_no'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Durum</label>
              <select name="durum" class="form-input">
                <option value="odenmedi" <?= ($_POST['durum'] ?? 'odenmedi') === 'odenmedi' ? 'selected' : '' ?>>Ödenmedi</option>
                <option value="odendi"   <?= ($_POST['durum'] ?? '') === 'odendi' ? 'selected' : '' ?>>Ödendi</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Fatura Tarihi</label>
              <input type="date" name="tarih" class="form-input" value="<?= htmlspecialchars($_POST['tarih'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="form-group">
              <label class="form-label">Vade Tarihi <span style="color:var(--ink3)">(opsiyonel)</span></label>
              <input type="date" name="vade_tarihi" class="form-input" value="<?= htmlspecialchars($_POST['vade_tarihi'] ?? '') ?>">
            </div>
            <div class="form-group" style="grid-column:1/-1">
              <label class="form-label">Açıklama <span style="color:var(--ink3)">(opsiyonel)</span></label>
              <textarea name="aciklama" class="form-input" rows="2" placeholder="Fatura notu..."><?= htmlspecialchars($_POST['aciklama'] ?? '') ?></textarea>
            </div>
          </div>
        </div>

        <!-- Ürün Satırları -->
        <div class="panel" style="padding:1.25rem;margin-bottom:1rem">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
            <h3 style="margin:0;font-size:.9rem;font-weight:700">Ürün Kalemleri</h3>
            <button type="button" class="btn btn-ghost btn-sm" onclick="satirEkle()">
              <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
              Satır Ekle
            </button>
          </div>

          <div id="satirlar">
            <!-- Başlık -->
            <div style="display:grid;grid-template-columns:3fr 1fr 1fr 1.5fr 1fr auto;gap:.5rem;margin-bottom:.4rem">
              <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase">Ürün Adı</div>
              <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase">Miktar</div>
              <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase">Birim</div>
              <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase">Birim Fiyat</div>
              <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase">KDV %</div>
              <div></div>
            </div>
            <div id="satirListesi">
              <!-- JS ile doldurulacak -->
            </div>
          </div>

          <!-- Toplam -->
          <div style="border-top:1px solid var(--border);margin-top:1rem;padding-top:1rem">
            <div style="display:flex;flex-direction:column;gap:.4rem;align-items:flex-end">
              <div style="display:flex;gap:1.5rem;font-size:.85rem">
                <span style="color:var(--ink3)">Ara Toplam:</span>
                <span style="font-weight:600;min-width:100px;text-align:right;font-variant-numeric:tabular-nums" id="araToplam">₺0,00</span>
              </div>
              <div style="display:flex;gap:1.5rem;font-size:.85rem">
                <span style="color:var(--ink3)">KDV:</span>
                <span style="font-weight:600;min-width:100px;text-align:right;font-variant-numeric:tabular-nums" id="kdvToplam">₺0,00</span>
              </div>
              <div style="display:flex;gap:1.5rem;font-size:1rem;border-top:1px solid var(--border);padding-top:.4rem;margin-top:.2rem">
                <span style="font-weight:700">Genel Toplam:</span>
                <span style="font-weight:800;min-width:100px;text-align:right;color:var(--blue,#2563EB);font-variant-numeric:tabular-nums" id="genelToplam">₺0,00</span>
              </div>
            </div>
          </div>
        </div>

        <div style="display:flex;gap:.75rem">
          <a href="/faturalar" class="btn btn-ghost">İptal</a>
          <button type="submit" class="btn btn-primary">Faturayı Kaydet</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
var satirSayisi = 0;

function satirEkle(ad, miktar, birim, fiyat, kdv) {
    var i = satirSayisi++;
    var row = document.createElement('div');
    row.style.cssText = 'display:grid;grid-template-columns:3fr 1fr 1fr 1.5fr 1fr auto;gap:.5rem;margin-bottom:.5rem;align-items:center';
    row.id = 'satir_' + i;
    row.innerHTML =
        '<input type="text" name="urun_adi[]" class="form-input" placeholder="Ürün adı" value="'+(ad||'')+'" required oninput="hesapla()">' +
        '<input type="number" name="miktar[]" class="form-input" value="'+(miktar||1)+'" min="0.001" step="any" style="text-align:right" oninput="hesapla()">' +
        '<select name="birim[]" class="form-input">' +
            '<option value="adet"'+((!birim||birim==='adet')?' selected':'')+'>adet</option>' +
            '<option value="kg"'+(birim==='kg'?' selected':'')+'>kg</option>' +
            '<option value="lt"'+(birim==='lt'?' selected':'')+'>lt</option>' +
            '<option value="m"'+(birim==='m'?' selected':'')+'>m</option>' +
            '<option value="m2"'+(birim==='m2'?' selected':'')+'>m²</option>' +
            '<option value="paket"'+(birim==='paket'?' selected':'')+'>paket</option>' +
            '<option value="kutu"'+(birim==='kutu'?' selected':'')+'>kutu</option>' +
        '</select>' +
        '<input type="number" name="birim_fiyat[]" class="form-input" value="'+(fiyat||'')+'" step="0.01" min="0" placeholder="0,00" style="text-align:right;font-variant-numeric:tabular-nums" oninput="hesapla()">' +
        '<select name="kdv_oran[]" class="form-input" onchange="hesapla()">' +
            '<option value="0"'+((!kdv||kdv===0)?' selected':'')+'>%0</option>' +
            '<option value="1"'+(kdv===1?' selected':'')+'>%1</option>' +
            '<option value="8"'+(kdv===8?' selected':'')+'>%8</option>' +
            '<option value="10"'+(kdv===10?' selected':'')+'>%10</option>' +
            '<option value="18"'+(kdv===18?' selected':'')+'>%18</option>' +
            '<option value="20"'+(kdv===20?' selected':'')+'>%20</option>' +
        '</select>' +
        '<button type="button" class="btn-icon" style="color:var(--red,#dc2626)" onclick="satirSil('+i+')"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>';
    document.getElementById('satirListesi').appendChild(row);
    hesapla();
}

function satirSil(i) {
    var el = document.getElementById('satir_' + i);
    if (el) el.remove();
    hesapla();
}

function fmt(n) {
    return '₺' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function hesapla() {
    var ara = 0, kdvT = 0;
    var adlar   = document.querySelectorAll('[name="urun_adi[]"]');
    var miktarlar = document.querySelectorAll('[name="miktar[]"]');
    var fiyatlar = document.querySelectorAll('[name="birim_fiyat[]"]');
    var kdvler  = document.querySelectorAll('[name="kdv_oran[]"]');
    for (var i = 0; i < adlar.length; i++) {
        var m = parseFloat(miktarlar[i].value) || 0;
        var f = parseFloat(fiyatlar[i].value) || 0;
        var k = parseFloat(kdvler[i].value) || 0;
        var st = m * f;
        ara  += st;
        kdvT += st * k / 100;
    }
    document.getElementById('araToplam').textContent   = fmt(ara);
    document.getElementById('kdvToplam').textContent   = fmt(kdvT);
    document.getElementById('genelToplam').textContent = fmt(ara + kdvT);
}

// İlk satırı otomatik ekle
satirEkle();
</script>
</body>
</html>
