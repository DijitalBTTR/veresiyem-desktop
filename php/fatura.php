<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'faturalar';
$uid = userId();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT f.*, t.ad as tedarikci_ad, t.id as tedarikci_id, t.telefon as tedarikci_tel, t.email as tedarikci_email
    FROM faturalar f
    JOIN tedarikciler t ON t.id = f.tedarikci_id
    WHERE f.id = ? AND t.user_id = ?
");
$stmt->execute([$id, $uid]);
$f = $stmt->fetch();
if (!$f) { header('Location: /faturalar'); exit; }

// Satırlar
$satirStmt = $pdo->prepare("SELECT * FROM fatura_satirlar WHERE fatura_id = ? ORDER BY id ASC");
$satirStmt->execute([$id]);
$satirlar = $satirStmt->fetchAll();

// Kasa hesapları (modal için)
$hesaplar = $pdo->prepare("SELECT id, ad, renk, bakiye, tip FROM kasa_hesaplari WHERE user_id=? AND aktif=1 ORDER BY varsayilan DESC, ad");
$hesaplar->execute([$uid]);
$hesaplar = $hesaplar->fetchAll();
$nakitHes = array_filter($hesaplar, fn($h) => ($h['tip'] ?? 'nakit') === 'nakit');
$bankaHes = array_filter($hesaplar, fn($h) => ($h['tip'] ?? 'nakit') === 'banka');

// Durum güncelle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['durum_guncelle'])) {
    $yeniDurum  = $_POST['durum'] ?? 'odenmedi';
    $oHesapId   = (int)($_POST['odeme_hesap_id'] ?? 0) ?: null;

    if (in_array($yeniDurum, ['odendi','odenmedi'])) {
        $oKasaId = null;

        // Ödendi → kasa çıkış hareketi oluştur
        if ($yeniDurum === 'odendi' && $oHesapId) {
            $tutar  = (float)$f['genel_toplam'];
            $acikl  = 'Fatura ödemesi: ' . ($f['fatura_no'] ?: '#'.$id);
            $tarih  = date('Y-m-d');
            $ins = $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, tarih) VALUES (?,?,?,?,?,?)");
            $ins->execute([$uid, $oHesapId, 'cikis', $tutar, $acikl, $tarih]);
            $oKasaId = $pdo->lastInsertId();
            $pdo->prepare("UPDATE kasa_hesaplari SET bakiye = bakiye - ? WHERE id=? AND user_id=?")->execute([$tutar, $oHesapId, $uid]);
        }

        // Ödenmedi'ye dönüş → eski kasa hareketini geri al
        if ($yeniDurum === 'odenmedi' && $f['odeme_kasa_id'] && $f['odeme_hesap_id']) {
            $pdo->prepare("UPDATE kasa_hesaplari SET bakiye = bakiye + ? WHERE id=? AND user_id=?")->execute([(float)$f['genel_toplam'], $f['odeme_hesap_id'], $uid]);
            $pdo->prepare("DELETE FROM kasa WHERE id=? AND user_id=?")->execute([$f['odeme_kasa_id'], $uid]);
            $oHesapId = null;
            $oKasaId  = null;
        }

        $pdo->prepare("UPDATE faturalar SET durum=?, odeme_hesap_id=?, odeme_kasa_id=? WHERE id=? AND user_id=?")
            ->execute([$yeniDurum, $oHesapId, $oKasaId, $id, $uid]);
        header('Location: /fatura?id=' . $id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fatura <?= htmlspecialchars($f['fatura_no'] ?: '#'.$id) ?> — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left">
        <a href="/tedarikci?id=<?= $f['tedarikci_id'] ?>" class="btn btn-ghost btn-sm" style="margin-right:.5rem">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <div>
          <div class="page-title">Fatura <?= htmlspecialchars($f['fatura_no'] ?: '#' . $id) ?></div>
          <div style="font-size:.75rem;color:var(--ink3)"><?= htmlspecialchars($f['tedarikci_ad']) ?></div>
        </div>
      </div>
      <div class="page-header-right" style="display:flex;gap:.5rem">
        <?php if ($f['durum'] === 'odenmedi'): ?>
        <form method="post" action="/fatura?id=<?= $id ?>" id="odendiForm">
          <input type="hidden" name="durum_guncelle" value="1">
          <input type="hidden" name="durum" value="odendi">
          <input type="hidden" name="odeme_hesap_id" id="secilenHesapId" value="">
          <button type="button" class="btn btn-primary btn-sm" id="odendiBtn">
            ✓ Ödendi Olarak İşaretle
          </button>
        </form>
        <?php else: ?>
        <form method="post" action="/fatura?id=<?= $id ?>" data-odenmedi>
          <input type="hidden" name="durum_guncelle" value="1">
          <input type="hidden" name="durum" value="odenmedi">
          <button type="button" class="btn btn-ghost btn-sm">Ödenmedi'ye Al</button>
        </form>
        <?php endif; ?>
        <button type="button" class="btn btn-ghost btn-sm" style="color:var(--red)" id="fatSilBtn">Sil</button>
      </div>
    </div>

    <div class="page-body">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
        <!-- Fatura Bilgileri -->
        <div class="panel" style="padding:1.25rem">
          <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem">Fatura Bilgileri</div>
          <div style="display:flex;flex-direction:column;gap:.5rem">
            <div style="display:flex;justify-content:space-between;font-size:.85rem">
              <span style="color:var(--ink3)">Fatura No:</span>
              <span style="font-weight:600;color:var(--ink)"><?= htmlspecialchars($f['fatura_no'] ?: '—') ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.85rem">
              <span style="color:var(--ink3)">Tarih:</span>
              <span style="font-weight:600;color:var(--ink)"><?= date('d.m.Y', strtotime($f['tarih'])) ?></span>
            </div>
            <?php if ($f['vade_tarihi']): ?>
            <div style="display:flex;justify-content:space-between;font-size:.85rem">
              <span style="color:var(--ink3)">Vade:</span>
              <?php $vadeGecti = strtotime($f['vade_tarihi']) < time() && $f['durum'] !== 'odendi'; ?>
              <span style="font-weight:600;color:<?= $vadeGecti ? 'var(--red,#dc2626)' : 'var(--ink)' ?>"><?= date('d.m.Y', strtotime($f['vade_tarihi'])) ?></span>
            </div>
            <?php endif; ?>
            <div style="display:flex;justify-content:space-between;font-size:.85rem">
              <span style="color:var(--ink3)">Durum:</span>
              <?php if ($f['durum'] === 'odendi'): ?>
                <span class="badge badge-green">Ödendi</span>
              <?php elseif ($f['vade_tarihi'] && strtotime($f['vade_tarihi']) < time()): ?>
                <span class="badge badge-red">Vadesi Geçti</span>
              <?php else: ?>
                <span class="badge badge-yellow">Bekliyor</span>
              <?php endif; ?>
            </div>
            <?php if ($f['durum'] === 'odendi' && $f['odeme_hesap_id']): ?>
            <?php
              $odHesap = null;
              foreach ($hesaplar as $h) { if ($h['id'] == $f['odeme_hesap_id']) { $odHesap = $h; break; } }
            ?>
            <div style="display:flex;justify-content:space-between;font-size:.85rem">
              <span style="color:var(--ink3)">Ödeme Kasası:</span>
              <span style="font-weight:600;display:flex;align-items:center;gap:.35rem">
                <?php if ($odHesap): ?>
                <span style="width:8px;height:8px;border-radius:50%;background:<?= htmlspecialchars($odHesap['renk'] ?? '#3B82F6') ?>;flex-shrink:0"></span>
                <?= htmlspecialchars($odHesap['ad']) ?>
                <?php else: ?>—<?php endif; ?>
              </span>
            </div>
            <?php endif; ?>
            <?php if ($f['aciklama']): ?>
            <div style="margin-top:.5rem;padding:.5rem .75rem;background:var(--bg);border-radius:6px;font-size:.82rem;color:var(--ink2)"><?= nl2br(htmlspecialchars($f['aciklama'])) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Tedarikçi Bilgileri -->
        <div class="panel" style="padding:1.25rem">
          <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.75rem">Tedarikçi</div>
          <a href="/tedarikci?id=<?= $f['tedarikci_id'] ?>" style="font-weight:700;font-size:.95rem;color:var(--ink);text-decoration:none"><?= htmlspecialchars($f['tedarikci_ad']) ?></a>
          <?php if ($f['tedarikci_tel']): ?>
          <div style="font-size:.82rem;color:var(--ink3);margin-top:.3rem"><?= htmlspecialchars($f['tedarikci_tel']) ?></div>
          <?php endif; ?>
          <?php if ($f['tedarikci_email']): ?>
          <div style="font-size:.82rem;color:var(--ink3)"><?= htmlspecialchars($f['tedarikci_email']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Kalemler -->
      <div class="panel" style="margin-bottom:1rem">
        <div style="padding:1rem 1.25rem;border-bottom:1px solid var(--border);font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em">Fatura Kalemleri</div>
        <table class="data-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Ürün Adı</th>
              <th style="text-align:right">Miktar</th>
              <th>Birim</th>
              <th style="text-align:right">Birim Fiyat</th>
              <th style="text-align:right">KDV %</th>
              <th style="text-align:right">Toplam</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($satirlar as $idx => $s): ?>
            <tr>
              <td style="color:var(--ink3);font-size:.8rem"><?= $idx + 1 ?></td>
              <td style="font-weight:600"><?= htmlspecialchars($s['urun_adi']) ?></td>
              <td style="text-align:right;font-variant-numeric:tabular-nums"><?= rtrim(rtrim(number_format($s['miktar'], 3, ',', '.'), '0'), ',') ?></td>
              <td style="color:var(--ink3)"><?= htmlspecialchars($s['birim']) ?></td>
              <td style="text-align:right;font-variant-numeric:tabular-nums">₺<?= number_format($s['birim_fiyat'], 2, ',', '.') ?></td>
              <td style="text-align:right;color:var(--ink3)">%<?= rtrim(rtrim(number_format($s['kdv_oran'], 1, ',', '.'), '0'), ',') ?></td>
              <td style="text-align:right;font-weight:700;font-variant-numeric:tabular-nums">₺<?= number_format($s['toplam'], 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="6" style="text-align:right;font-size:.82rem;color:var(--ink3);padding:.6rem 1rem">Ara Toplam:</td>
              <td style="text-align:right;font-variant-numeric:tabular-nums;padding:.6rem 1rem">₺<?= number_format($f['toplam_tutar'], 2, ',', '.') ?></td>
            </tr>
            <tr>
              <td colspan="6" style="text-align:right;font-size:.82rem;color:var(--ink3);padding:.3rem 1rem">KDV:</td>
              <td style="text-align:right;font-variant-numeric:tabular-nums;padding:.3rem 1rem">₺<?= number_format($f['kdv_toplam'], 2, ',', '.') ?></td>
            </tr>
            <tr style="border-top:2px solid var(--border)">
              <td colspan="6" style="text-align:right;font-weight:700;padding:.75rem 1rem">Genel Toplam:</td>
              <td style="text-align:right;font-weight:800;font-size:1.05rem;color:var(--blue,#2563EB);font-variant-numeric:tabular-nums;padding:.75rem 1rem">₺<?= number_format($f['genel_toplam'], 2, ',', '.') ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </main>
</div>
<!-- Ödeme Kasa Seçim Modalı -->
<div id="odemModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center">
  <div style="background:var(--panel,#fff);border-radius:12px;padding:1.5rem;width:360px;max-width:92vw;box-shadow:0 8px 32px rgba(0,0,0,.18)">
    <div style="font-weight:700;font-size:1rem;margin-bottom:.25rem">Ödeme Kaynağı Seç</div>
    <div style="font-size:.83rem;color:var(--ink3);margin-bottom:1rem">Ödeme hangi kasa veya bankadan yapıldı?</div>

    <?php if (!empty($hesaplar)): ?>
    <div style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:1rem" id="kasaSecimListesi">
      <?php if ($nakitHes): ?>
      <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;padding:.1rem 0">💵 Nakit</div>
      <?php foreach ($nakitHes as $h): ?>
      <label style="cursor:pointer">
        <input type="radio" name="odeme_hesap_r" value="<?= $h['id'] ?>" style="display:none" class="hesap-radio">
        <div class="hesap-secim-btn" data-id="<?= $h['id'] ?>" style="display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:8px;border:2px solid var(--border,#e5e7eb);transition:all .12s;cursor:pointer">
          <span style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($h['renk'] ?? '#10b981') ?>;flex-shrink:0"></span>
          <span style="flex:1;font-size:.88rem;font-weight:600"><?= htmlspecialchars($h['ad']) ?></span>
          <span style="font-size:.78rem;color:var(--ink3);font-variant-numeric:tabular-nums"><?= number_format($h['bakiye'],2,',','.') ?> ₺</span>
        </div>
      </label>
      <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($bankaHes): ?>
      <div style="font-size:.72rem;font-weight:700;color:var(--ink3);text-transform:uppercase;letter-spacing:.05em;padding:.3rem 0 .1rem">🏦 Banka</div>
      <?php foreach ($bankaHes as $h): ?>
      <label style="cursor:pointer">
        <input type="radio" name="odeme_hesap_r" value="<?= $h['id'] ?>" style="display:none" class="hesap-radio">
        <div class="hesap-secim-btn" data-id="<?= $h['id'] ?>" style="display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:8px;border:2px solid var(--border,#e5e7eb);transition:all .12s;cursor:pointer">
          <span style="width:10px;height:10px;border-radius:50%;background:<?= htmlspecialchars($h['renk'] ?? '#3B82F6') ?>;flex-shrink:0"></span>
          <span style="flex:1;font-size:.88rem;font-weight:600"><?= htmlspecialchars($h['ad']) ?></span>
          <span style="font-size:.78rem;color:var(--ink3);font-variant-numeric:tabular-nums"><?= number_format($h['bakiye'],2,',','.') ?> ₺</span>
        </div>
      </label>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="font-size:.84rem;color:var(--ink3);padding:.5rem .75rem;background:var(--surface2,#f8f9fa);border-radius:6px;border:1px solid var(--border);margin-bottom:1rem">
      Kasa hesabı tanımlı değil. Kasasız olarak ödendi işaretlensin mi?
    </div>
    <?php endif; ?>

    <div style="display:flex;gap:.5rem;justify-content:flex-end">
      <button type="button" id="odemModalIptal" class="btn btn-ghost">İptal</button>
      <button type="button" id="odemModalOnayla" class="btn btn-primary">Ödendi İşaretle</button>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  // Fatura Sil
  var silBtn = document.getElementById('fatSilBtn');
  if (silBtn) {
    silBtn.addEventListener('click', async function(){
      var ok = await window.veresiyemConfirm({
        title: 'Fatura Sil',
        msg: 'Bu faturayı silmek istediğinizden emin misiniz? İşlem geri alınamaz.',
        okLabel: 'Evet, Sil',
        type: 'red'
      });
      if (ok) window.location.href = '/fatura-sil?id=<?= $id ?>';
    });
  }

  // Ödendi İşaretle → kasa seçim modalı
  var odendiBtn = document.getElementById('odendiBtn');
  var overlay   = document.getElementById('odemModalOverlay');
  var iptalBtn  = document.getElementById('odemModalIptal');
  var onaylaBtn = document.getElementById('odemModalOnayla');

  if (odendiBtn && overlay) {
    overlay.style.display = 'flex'; // flex hazır ama görünmez
    overlay.style.display = 'none';

    odendiBtn.addEventListener('click', function(){ overlay.style.display = 'flex'; });
    iptalBtn.addEventListener('click',  function(){ overlay.style.display = 'none'; });
    overlay.addEventListener('click', function(e){ if (e.target === overlay) overlay.style.display = 'none'; });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') overlay.style.display = 'none'; });

    // Kasa seçim toggle
    document.querySelectorAll('.hesap-secim-btn').forEach(function(btn){
      btn.addEventListener('click', function(){
        var radio = btn.parentElement.querySelector('.hesap-radio');
        radio.checked = true;
        document.querySelectorAll('.hesap-secim-btn').forEach(function(b){
          b.style.borderColor = '';
          b.style.background  = '';
        });
        btn.style.borderColor = 'var(--accent,#2563eb)';
        btn.style.background  = 'var(--accent-lt,#eff6ff)';
      });
    });

    onaylaBtn.addEventListener('click', function(){
      var secili = document.querySelector('.hesap-radio:checked');
      document.getElementById('secilenHesapId').value = secili ? secili.value : '';
      overlay.style.display = 'none';
      document.getElementById('odendiForm').submit();
    });
  }

  // Ödenmedi'ye al — direkt confirm
  var odenmediForm = document.querySelector('form[data-odenmedi]');
  if (odenmediForm) {
    odenmediForm.querySelector('button').addEventListener('click', async function(e){
      e.preventDefault();
      var ok = await window.veresiyemConfirm({
        title: 'Ödenmedi\'ye Al',
        msg: 'Fatura ödenmedi olarak işaretlensin mi? Varsa kasa hareketi geri alınacak.',
        okLabel: 'Evet',
        type: 'gold'
      });
      if (ok) odenmediForm.submit();
    });
  }
});
</script>
</body>
</html>
