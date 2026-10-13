<?php
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/db.php';
girisGerekli();
$activePage = 'gelir-gider';
$uid = userId();

$hata   = '';
$renkler = ['#16a34a','#2563eb','#dc2626','#f59e0b','#7c3aed','#0891b2','#ea580c','#be185d','#059669','#64748b'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ad  = trim($_POST['ad'] ?? '');
    $tip = $_POST['tip'] ?? '';
    $renk = $_POST['renk'] ?? '#16a34a';

    if (!$ad)                                    $hata = 'Kategori adı gerekli.';
    elseif (!in_array($tip, ['gelir','gider']))  $hata = 'Geçerli bir tür seçin.';

    if (!$hata) {
        $ins = $pdo->prepare("INSERT INTO gelir_gider_kategoriler (user_id, ad, tip, renk) VALUES (?,?,?,?)");
        $ins->execute([$uid, $ad, $tip, $renk]);
        header('Location: /gelir-gider-ekle');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Yeni Kategori — Veresiyem</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<?php require __DIR__ . '/includes/titlebar.php'; ?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="page-header-left" style="display:flex;align-items:center;gap:.6rem">
        <a href="/gelir-gider-ekle" class="btn btn-ghost btn-sm" style="padding:.3rem .5rem">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </a>
        <div>
          <div class="page-title">Yeni Kategori</div>
          <div class="page-sub">Gelir/gider kategorisi ekle</div>
        </div>
      </div>
    </div>

    <div class="page-body">
      <?php if ($hata): ?>
      <div style="background:var(--red-lt,#fee2e2);color:var(--red,#dc2626);border:1px solid var(--red,#dc2626);border-radius:6px;padding:.65rem .9rem;margin-bottom:.85rem;font-size:.86rem"><?= htmlspecialchars($hata) ?></div>
      <?php endif; ?>

      <div class="panel" style="max-width:440px;padding:1.5rem">
        <form method="POST" action="/gg-kategori-ekle">

          <div style="margin-bottom:1rem">
            <label class="form-label">Kategori Adı *</label>
            <input type="text" name="ad" value="<?= htmlspecialchars($_POST['ad'] ?? '') ?>" class="form-input" placeholder="örn. Kira, Maaş, Satış..." required autofocus style="width:100%">
          </div>

          <div style="margin-bottom:1rem">
            <label class="form-label">Tür *</label>
            <div style="display:flex;gap:.5rem">
              <label style="flex:1;cursor:pointer">
                <input type="radio" name="tip" value="gelir" <?= ($_POST['tip'] ?? 'gelir') !== 'gider' ? 'checked' : '' ?> style="display:none" class="tip-radio">
                <div class="tip-btn" data-tip="gelir" style="text-align:center;padding:.6rem;border-radius:7px;border:2px solid var(--border,#e5e7eb);font-weight:600;font-size:.88rem;cursor:pointer;color:#16a34a;transition:all .12s">Gelir</div>
              </label>
              <label style="flex:1;cursor:pointer">
                <input type="radio" name="tip" value="gider" <?= ($_POST['tip'] ?? '') === 'gider' ? 'checked' : '' ?> style="display:none" class="tip-radio">
                <div class="tip-btn" data-tip="gider" style="text-align:center;padding:.6rem;border-radius:7px;border:2px solid var(--border,#e5e7eb);font-weight:600;font-size:.88rem;cursor:pointer;color:#dc2626;transition:all .12s">Gider</div>
              </label>
            </div>
          </div>

          <div style="margin-bottom:1.25rem">
            <label class="form-label">Renk</label>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.35rem">
              <?php foreach ($renkler as $r): ?>
              <label style="cursor:pointer">
                <input type="radio" name="renk" value="<?= $r ?>" <?= ($_POST['renk'] ?? '#16a34a') === $r ? 'checked' : '' ?> style="display:none" class="renk-radio">
                <span class="renk-circle" style="display:block;width:28px;height:28px;border-radius:50%;background:<?= $r ?>;border:3px solid transparent;transition:all .12s"></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div style="display:flex;gap:.5rem;justify-content:flex-end">
            <a href="/gelir-gider-ekle" class="btn btn-ghost">İptal</a>
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
      btn.style.borderColor = r.checked ? (isGelir ? '#16a34a' : '#dc2626') : '';
      btn.style.background  = r.checked ? (isGelir ? '#dcfce7' : '#fee2e2') : '';
    });
  }
  document.querySelectorAll('.tip-radio').forEach(function(r){ r.addEventListener('change', updateTipUI); });
  document.querySelectorAll('.tip-btn').forEach(function(btn){
    btn.addEventListener('click', function(){ btn.parentElement.querySelector('.tip-radio').checked = true; updateTipUI(); });
  });
  updateTipUI();

  function updateRenkUI(){
    document.querySelectorAll('.renk-radio').forEach(function(r){
      var c = r.parentElement.querySelector('.renk-circle');
      c.style.borderColor = r.checked ? '#1e293b' : 'transparent';
      c.style.transform   = r.checked ? 'scale(1.18)' : '';
    });
  }
  document.querySelectorAll('.renk-radio').forEach(function(r){ r.addEventListener('change', updateRenkUI); });
  updateRenkUI();
})();
</script>
</body>
</html>
