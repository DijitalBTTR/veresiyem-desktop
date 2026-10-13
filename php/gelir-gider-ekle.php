<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'gelir-gider';
$uid = userId();

$hata = '';

$hesaplar = $pdo->prepare("SELECT id, ad, renk, bakiye, tip FROM kasa_hesaplari WHERE user_id=? AND aktif=1 ORDER BY varsayilan DESC, ad");
$hesaplar->execute([$uid]);
$hesaplar = $hesaplar->fetchAll();

$kategoriler = $pdo->prepare("SELECT * FROM gelir_gider_kategoriler WHERE user_id=? ORDER BY tip, ad");
$kategoriler->execute([$uid]);
$kategoriler = $kategoriler->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tip      = $_POST['tip'] ?? '';
    $tutar    = str_replace(',', '.', trim($_POST['tutar'] ?? ''));
    $tarih    = $_POST['tarih'] ?? date('Y-m-d');
    $aciklama = trim($_POST['aciklama'] ?? '');
    $hesap_id = (int)($_POST['hesap_id'] ?? 0) ?: null;
    $kat_id   = (int)($_POST['kategori_id'] ?? 0) ?: null;
    $cari_id  = (int)($_POST['cari_id'] ?? 0) ?: null;

    if (!in_array($tip, ['gelir', 'gider']))          $hata = 'Geçerli bir tür seçin.';
    elseif (!is_numeric($tutar) || (float)$tutar <= 0) $hata = 'Geçerli bir tutar girin.';
    elseif (!$tarih)                                   $hata = 'Tarih gereklidir.';

    if (!$hata) {
        $tutar   = (float)$tutar;
        $kasa_id = null;

        if ($hesap_id) {
            $kasaTip = $tip === 'gelir' ? 'giris' : 'cikis';
            $kasaAc  = $aciklama ?: ($tip === 'gelir' ? 'Gelir kaydı' : 'Gider kaydı');
            $ins = $pdo->prepare("INSERT INTO kasa (user_id, hesap_id, tip, tutar, aciklama, tarih) VALUES (?,?,?,?,?,?)");
            $ins->execute([$uid, $hesap_id, $kasaTip, $tutar, $kasaAc, $tarih]);
            $kasa_id = $pdo->lastInsertId();

            if ($kasaTip === 'giris') {
                $pdo->prepare("UPDATE kasa_hesaplari SET bakiye = bakiye + ? WHERE id=? AND user_id=?")->execute([$tutar, $hesap_id, $uid]);
            } else {
                $pdo->prepare("UPDATE kasa_hesaplari SET bakiye = bakiye - ? WHERE id=? AND user_id=?")->execute([$tutar, $hesap_id, $uid]);
            }
        }

        $ins = $pdo->prepare("INSERT INTO gelir_gider (user_id, hesap_id, kategori_id, cari_id, tip, tutar, aciklama, tarih, kasa_id) VALUES (?,?,?,?,?,?,?,?,?)");
        $ins->execute([$uid, $hesap_id, $kat_id, $cari_id, $tip, $tutar, $aciklama ?: null, $tarih, $kasa_id]);

        header('Location: /gelir-gider');
        exit;
    }
}

$nakitHes = array_filter($hesaplar, fn($h) => ($h['tip'] ?? 'nakit') === 'nakit');
$bankaHes = array_filter($hesaplar, fn($h) => ($h['tip'] ?? 'nakit') === 'banka');
$gelirKat = array_filter($kategoriler, fn($k) => $k['tip'] === 'gelir');
$giderKat = array_filter($kategoriler, fn($k) => $k['tip'] === 'gider');

$cariler = $pdo->prepare("SELECT id, ad, telefon FROM cariler WHERE user_id=? ORDER BY ad");
$cariler->execute([$uid]);
$cariler = $cariler->fetchAll();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yeni Gelir/Gider — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left" style="display:flex;align-items:center;gap:.6rem">
        <a href="/gelir-gider" class="btn btn-ghost btn-sm" style="padding:.3rem .5rem">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <div>
          <div class="page-title">Yeni Gelir / Gider</div>
          <div class="page-sub">Gelir veya gider kaydı ekleyin</div>
        </div>
      </div>
    </div>

    <div class="page-body">
      <?php if ($hata): ?>
      <div style="background:var(--red-lt,#fee2e2);color:var(--red,#dc2626);border:1px solid var(--red,#dc2626);border-radius:6px;padding:.65rem .9rem;margin-bottom:.85rem;font-size:.86rem"><?= htmlspecialchars($hata) ?></div>
      <?php endif; ?>

      <div class="panel" style="max-width:560px;padding:1.5rem">
        <form method="POST" action="/gelir-gider-ekle">

          <!-- Tür -->
          <div style="margin-bottom:1rem">
            <label class="form-label">Tür *</label>
            <div style="display:flex;gap:.5rem">
              <label style="flex:1;cursor:pointer">
                <input type="radio" name="tip" value="gelir" <?= ($_POST['tip'] ?? '') !== 'gider' ? 'checked' : '' ?> style="display:none" class="tip-radio">
                <div class="tip-btn" data-tip="gelir" style="text-align:center;padding:.6rem;border-radius:7px;border:2px solid var(--border,#e5e7eb);font-weight:600;font-size:.88rem;cursor:pointer;color:#16a34a;transition:all .12s">
                  ↑ Gelir
                </div>
              </label>
              <label style="flex:1;cursor:pointer">
                <input type="radio" name="tip" value="gider" <?= ($_POST['tip'] ?? '') === 'gider' ? 'checked' : '' ?> style="display:none" class="tip-radio">
                <div class="tip-btn" data-tip="gider" style="text-align:center;padding:.6rem;border-radius:7px;border:2px solid var(--border,#e5e7eb);font-weight:600;font-size:.88rem;cursor:pointer;color:#dc2626;transition:all .12s">
                  ↓ Gider
                </div>
              </label>
            </div>
          </div>

          <!-- Tutar -->
          <div style="margin-bottom:1rem">
            <label class="form-label">Tutar (₺) *</label>
            <input type="number" name="tutar" value="<?= htmlspecialchars($_POST['tutar'] ?? '') ?>" class="form-input" min="0.01" step="0.01" placeholder="0,00" required autofocus style="width:100%">
          </div>

          <!-- Tarih -->
          <div style="margin-bottom:1rem">
            <label class="form-label">Tarih *</label>
            <input type="date" name="tarih" value="<?= htmlspecialchars($_POST['tarih'] ?? date('Y-m-d')) ?>" class="form-input" required style="width:100%">
          </div>

          <!-- Kasa -->
          <div style="margin-bottom:1rem">
            <label class="form-label">Kasa Hesabı <span style="font-size:.75rem;font-weight:400;color:var(--ink3)">(seçilirse kasaya işlenir)</span></label>
            <?php if (empty($hesaplar)): ?>
            <div style="font-size:.84rem;color:var(--ink3);padding:.5rem .75rem;background:var(--surface2,#f8f9fa);border-radius:6px;border:1px solid var(--border)">
              Henüz kasa hesabı yok. <a href="/kasa" style="color:var(--accent)">Kasa sayfasından</a> ekleyebilirsiniz.
            </div>
            <?php else: ?>
            <select name="hesap_id" class="form-input" style="width:100%">
              <option value="">— Kasaya işleme</option>
              <?php if ($nakitHes): ?><optgroup label="💵 Nakit"><?php foreach ($nakitHes as $h): ?><option value="<?= $h['id'] ?>" <?= ($_POST['hesap_id'] ?? '') == $h['id'] ? 'selected' : '' ?>><?= htmlspecialchars($h['ad']) ?> — <?= number_format($h['bakiye'],2,',','.') ?> ₺</option><?php endforeach; ?></optgroup><?php endif; ?>
              <?php if ($bankaHes): ?><optgroup label="🏦 Banka"><?php foreach ($bankaHes as $h): ?><option value="<?= $h['id'] ?>" <?= ($_POST['hesap_id'] ?? '') == $h['id'] ? 'selected' : '' ?>><?= htmlspecialchars($h['ad']) ?> — <?= number_format($h['bakiye'],2,',','.') ?> ₺</option><?php endforeach; ?></optgroup><?php endif; ?>
            </select>
            <?php endif; ?>
          </div>

          <!-- Cari -->
          <?php if (!empty($cariler)): ?>
          <div style="margin-bottom:1rem">
            <label class="form-label">Cari <span style="font-size:.75rem;font-weight:400;color:var(--ink3)">(isteğe bağlı)</span></label>
            <select name="cari_id" class="form-input" style="width:100%">
              <option value="">— Cariye bağlama</option>
              <?php foreach ($cariler as $c): ?>
              <option value="<?= $c['id'] ?>" <?= ($_POST['cari_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['ad']) ?><?= $c['telefon'] ? ' · ' . htmlspecialchars($c['telefon']) : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <!-- Kategori -->
          <div style="margin-bottom:1rem">
            <label class="form-label">Kategori</label>
            <div style="display:flex;gap:.4rem;align-items:center">
              <select name="kategori_id" class="form-input" style="flex:1">
                <option value="">— Kategorisiz</option>
                <?php if ($gelirKat): ?><optgroup label="Gelir Kategorileri"><?php foreach ($gelirKat as $k): ?><option value="<?= $k['id'] ?>" <?= ($_POST['kategori_id'] ?? '') == $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['ad']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                <?php if ($giderKat): ?><optgroup label="Gider Kategorileri"><?php foreach ($giderKat as $k): ?><option value="<?= $k['id'] ?>" <?= ($_POST['kategori_id'] ?? '') == $k['id'] ? 'selected' : '' ?>><?= htmlspecialchars($k['ad']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
              </select>
              <a href="/gg-kategori-ekle" class="btn btn-ghost btn-sm" style="white-space:nowrap;flex-shrink:0">+ Kategori</a>
            </div>
          </div>

          <!-- Açıklama -->
          <div style="margin-bottom:1.25rem">
            <label class="form-label">Açıklama</label>
            <textarea name="aciklama" class="form-input" rows="3" placeholder="İsteğe bağlı açıklama..." style="width:100%;resize:vertical"><?= htmlspecialchars($_POST['aciklama'] ?? '') ?></textarea>
          </div>

          <div style="display:flex;gap:.5rem;justify-content:flex-end">
            <a href="/gelir-gider" class="btn btn-ghost">İptal</a>
            <button type="submit" class="btn btn-primary">Kaydet</button>
          </div>
        </form>
      </div>
    </div>

  </main>
</div>
<script>
(function(){
  function updateTipUI(){
    document.querySelectorAll('.tip-radio').forEach(function(r){
      var btn = r.parentElement.querySelector('.tip-btn');
      var isGelir = btn.dataset.tip === 'gelir';
      if(r.checked){
        btn.style.borderColor = isGelir ? '#16a34a' : '#dc2626';
        btn.style.background  = isGelir ? '#dcfce7' : '#fee2e2';
      } else {
        btn.style.borderColor = '';
        btn.style.background  = '';
      }
    });
  }
  document.querySelectorAll('.tip-radio').forEach(function(r){ r.addEventListener('change', updateTipUI); });
  document.querySelectorAll('.tip-btn').forEach(function(btn){
    btn.addEventListener('click', function(){ btn.parentElement.querySelector('.tip-radio').checked = true; updateTipUI(); });
  });
  updateTipUI();
})();
</script>
</body>
</html>
